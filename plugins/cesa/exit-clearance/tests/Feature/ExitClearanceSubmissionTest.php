<?php

namespace Cesa\ExitClearance\Tests\Feature;

use Cesa\ExitClearance\Jobs\SendWhatsAppNotification;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Notifications\ApprovalRequestNotification;
use Cesa\ExitClearance\Notifications\RequestStatusNotification;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Cesa\ExitClearance\Services\WhatsAppThrottleService;
use Cesa\ExitClearance\Tests\ExitClearanceTestCase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionProperty;

class ExitClearanceSubmissionTest extends ExitClearanceTestCase
{
    public function test_request_model_sets_default_tracking_fields_on_create(): void
    {
        $department = Department::factory()->create();

        $request = Request::query()->create([
            'department_id' => $department->id,
            'name'          => 'Test User',
            'email'         => 'test@example.com',
        ]);

        $this->assertMatchesRegularExpression('/^EXC-\d{5}$/', (string) $request->form_uid);
        $this->assertNotEmpty($request->form_response_id);
        $this->assertSame(ExitClearanceRequestService::FORM_STATUS_PENDING, $request->form_status);
        $this->assertSame(now()->toDateString(), $request->request_date?->format('Y-m-d'));
    }

    public function test_create_public_request_attaches_department_approvers_with_pending_status(): void
    {
        $service = app(ExitClearanceRequestService::class);
        $department = Department::factory()->create();

        $approverOne = Approver::query()->create([
            'name'  => 'Approver One',
            'email' => 'approver1@example.com',
            'title' => 'Head HR',
        ]);

        $approverTwo = Approver::query()->create([
            'name'  => 'Approver Two',
            'email' => 'approver2@example.com',
            'title' => 'Finance Manager',
        ]);

        $department->approvers()->sync([$approverOne->id, $approverTwo->id]);

        $request = $service->createPublicRequest([
            'department_id' => $department->id,
            'name'          => 'John Doe',
            'email'         => 'john@example.com',
        ]);

        $this->assertDatabaseHas('exit_clearance_request_approver', [
            'request_id'  => $request->id,
            'approver_id' => $approverOne->id,
            'status'      => ExitClearanceRequestService::APPROVAL_PENDING,
        ]);
        $this->assertDatabaseHas('exit_clearance_request_approver', [
            'request_id'  => $request->id,
            'approver_id' => $approverTwo->id,
            'status'      => ExitClearanceRequestService::APPROVAL_PENDING,
        ]);
    }

    public function test_create_public_request_keeps_creator_id_null_for_guest_submission(): void
    {
        $service = app(ExitClearanceRequestService::class);
        $department = Department::factory()->create();

        $request = $service->createPublicRequest([
            'department_id' => $department->id,
            'name'          => 'Guest Submitter',
            'email'         => 'guest.submitter@example.com',
        ]);

        $this->assertNull($request->creator_id);
    }

    public function test_sync_overall_status_updates_request_status_from_approver_pivots(): void
    {
        $service = app(ExitClearanceRequestService::class);
        $department = Department::factory()->create();

        $approverOne = Approver::query()->create([
            'name'  => 'Approver One',
            'email' => 'approver1@example.com',
            'title' => 'Head HR',
        ]);

        $approverTwo = Approver::query()->create([
            'name'  => 'Approver Two',
            'email' => 'approver2@example.com',
            'title' => 'Finance Manager',
        ]);

        $request = Request::query()->create([
            'department_id' => $department->id,
            'name'          => 'John Doe',
            'email'         => 'john@example.com',
        ]);

        $request->approvers()->sync([
            $approverOne->id => ['status' => ExitClearanceRequestService::APPROVAL_APPROVED],
            $approverTwo->id => ['status' => ExitClearanceRequestService::APPROVAL_REJECTED],
        ]);

        $rejectedStatus = $service->syncOverallStatus($request->fresh('approvers'));
        $this->assertSame(ExitClearanceRequestService::FORM_STATUS_REJECTED, $rejectedStatus);

        DB::table('exit_clearance_request_approver')
            ->where('request_id', $request->id)
            ->update(['status' => ExitClearanceRequestService::APPROVAL_APPROVED]);

        $approvedStatus = $service->syncOverallStatus($request->fresh('approvers'));
        $this->assertSame(ExitClearanceRequestService::FORM_STATUS_APPROVED, $approvedStatus);
    }

    public function test_exit_clearance_notifications_and_whatsapp_jobs_use_standard_queues(): void
    {
        $request = new Request;
        $approver = new Approver;

        $approvalNotification = new ApprovalRequestNotification(
            $request,
            $approver,
            [],
            [],
            'https://example.com/approve',
            'https://example.com/progress'
        );
        $statusNotification = new RequestStatusNotification(
            $request,
            'Approved',
            [],
            [],
            'https://example.com/progress'
        );
        $whatsAppJob = new SendWhatsAppNotification(
            '628123456789',
            'Test message',
            'https://example.com/whatsapp',
            'test-api-key',
            '628111111111'
        );

        $this->assertSame('notifications', config('exit-clearance.notifications.queue'));
        $this->assertSame('whatsapp', config('exit-clearance.notifications.whatsapp.queue'));
        $this->assertInstanceOf(ShouldQueue::class, $approvalNotification);
        $this->assertInstanceOf(ShouldQueue::class, $statusNotification);
        $this->assertInstanceOf(ShouldQueue::class, $whatsAppJob);
        $this->assertSame('notifications', $approvalNotification->queue);
        $this->assertSame('notifications', $statusNotification->queue);
        $this->assertSame('whatsapp', $whatsAppJob->queue);
    }

    public function test_exit_clearance_waghub_job_uses_correct_payload(): void
    {

        Http::fake([
            'https://waghub.mekayastudio.com/api/v1/messages' => Http::response(['status' => 'queued'], 200),
        ]);

        $job = new SendWhatsAppNotification(
            '+628123456789',
            'Test message',
            'https://waghub.mekayastudio.com',
            'test-token',
            10,
        );

        $job->handle();

        Http::assertSent(function (HttpRequest $request): bool {
            $body = $request->data();

            return $request->url() === 'https://waghub.mekayastudio.com/api/v1/messages'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->hasHeader('Idempotency-Key')
                && $body['recipient']['value'] === '+628123456789'
                && $body['message']['text'] === 'Test message'
                && $body['purpose'] === 'notification';
        });
    }

    public function test_exit_clearance_whatsapp_messages_include_requester_progress_link(): void
    {
        $service = app(ExitClearanceNotificationService::class);
        $department = new Department(['name' => 'Human Resource']);
        $request = new Request([
            'form_uid'    => 'EXC-00001',
            'name'        => 'Budi Santoso',
            'form_status' => ExitClearanceRequestService::FORM_STATUS_PENDING,
        ]);
        $request->setRelation('department', $department);
        $approver = new Approver([
            'name'  => 'Uwis GA',
            'title' => 'GA Officer',
        ]);

        $approverMethod = new \ReflectionMethod($service, 'buildApproverWhatsAppMessage');
        $approverMethod->setAccessible(true);
        $approverMessage = $approverMethod->invoke(
            $service,
            $request,
            $approver,
            'https://example.com/approval',
            'https://example.com/progress'
        );

        $requesterMethod = new \ReflectionMethod($service, 'buildRequesterWhatsAppMessage');
        $requesterMethod->setAccessible(true);
        $requesterMessage = $requesterMethod->invoke(
            $service,
            $request,
            'Pending',
            'https://example.com/progress'
        );

        $this->assertStringContainsString(__('exit-clearance::notifications.whatsapp.approver.heading', ['uid' => 'EXC-00001']), $approverMessage);
        $this->assertStringContainsString('*'.__('exit-clearance::notifications.whatsapp.labels.approval_step').':* GA Officer', $approverMessage);
        $this->assertStringContainsString('*'.__('exit-clearance::notifications.whatsapp.labels.approver_name').':* Uwis GA', $approverMessage);
        $this->assertStringContainsString('*'.__('exit-clearance::notifications.whatsapp.labels.approval_link').':*', $approverMessage);
        $this->assertStringNotContainsString('Progress', $approverMessage);
        $this->assertStringContainsString(__('exit-clearance::notifications.whatsapp.requester.heading', ['uid' => 'EXC-00001']), $requesterMessage);
        $this->assertStringContainsString('*'.__('exit-clearance::notifications.whatsapp.labels.requester_name').':* Budi Santoso', $requesterMessage);
        $this->assertStringContainsString('*'.__('exit-clearance::notifications.whatsapp.labels.progress_link').':*', $requesterMessage);
        $this->assertStringContainsString('https://example.com/progress', $requesterMessage);
    }

    public function test_notify_pending_approvers_can_target_a_single_pending_approver(): void
    {
        Notification::fake();
        Queue::fake();

        config()->set('exit-clearance.notifications.mail.enabled', true);
        config()->set('exit-clearance.notifications.whatsapp.enabled', true);
        config()->set('exit-clearance.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('exit-clearance.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('exit-clearance.notifications.whatsapp.throttle.enabled', false);

        $department = Department::factory()->create();
        $request = Request::factory()->create([
            'department_id' => $department->id,
            'form_status'   => ExitClearanceRequestService::FORM_STATUS_PENDING,
        ]);

        $gaOfficer = Approver::query()->create([
            'name'  => 'Uwis GA',
            'title' => 'GA Officer',
            'email' => 'uwis.ga@example.com',
            'phone' => '081234567890',
        ]);

        $hrManager = Approver::query()->create([
            'name'  => 'Ester HR',
            'title' => 'HR Manager',
            'email' => 'ester.hr@example.com',
            'phone' => '081298765432',
        ]);

        $approvedApprover = Approver::query()->create([
            'name'  => 'Approved One',
            'title' => 'IT Manager',
            'email' => 'approved@example.com',
            'phone' => '081211111111',
        ]);

        $request->approvers()->sync([
            $gaOfficer->id        => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
            $hrManager->id        => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
            $approvedApprover->id => ['status' => ExitClearanceRequestService::APPROVAL_APPROVED],
        ]);

        $sentCount = app(ExitClearanceNotificationService::class)
            ->notifyPendingApprovers($request->fresh('approvers'), $gaOfficer->id);

        $this->assertSame(1, $sentCount);

        Notification::assertSentOnDemandTimes(ApprovalRequestNotification::class, 1);
        Notification::assertSentOnDemand(ApprovalRequestNotification::class, function (
            ApprovalRequestNotification $notification,
            array $channels,
            object $notifiable,
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'uwis.ga@example.com';
        });

        Queue::assertPushed(SendWhatsAppNotification::class, 1);
        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job): bool {
            $phone = new ReflectionProperty($job, 'phone');
            $message = new ReflectionProperty($job, 'message');

            return $phone->getValue($job) === '6281234567890'
                && str_contains((string) $message->getValue($job), 'GA Officer')
                && str_contains((string) $message->getValue($job), 'Uwis GA');
        });
    }

    public function test_whatsapp_throttle_spaces_messages_two_to_three_seconds_apart(): void
    {
        $key = 'exit-clearance-whatsapp-'.Str::uuid();

        config()->set('exit-clearance.notifications.whatsapp.throttle', [
            'enabled'              => true,
            'min_interval_seconds' => 2,
            'max_interval_seconds' => 3,
            'key'                  => $key,
        ]);

        $throttle = app(WhatsAppThrottleService::class);
        $firstDelay = $throttle->getDispatchDelaySeconds();
        $secondDelay = $throttle->getDispatchDelaySeconds();

        $this->assertSame(0, $firstDelay);
        $this->assertGreaterThanOrEqual(2, $secondDelay);
        $this->assertLessThanOrEqual(3, $secondDelay);
    }
}
