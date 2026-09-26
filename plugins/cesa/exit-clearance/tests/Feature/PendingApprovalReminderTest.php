<?php

namespace Cesa\ExitClearance\Tests\Feature;

use Cesa\ExitClearance\Jobs\SendWhatsAppNotification;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Notifications\ApprovalRequestNotification;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Cesa\ExitClearance\Tests\ExitClearanceTestCase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class PendingApprovalReminderTest extends ExitClearanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require base_path('plugins/cesa/exit-clearance/routes/web.php');

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        Notification::fake();
        Queue::fake();

        config()->set('exit-clearance.notifications.mail.enabled', true);
        config()->set('exit-clearance.notifications.mail.throttle.enabled', false);
        config()->set('exit-clearance.notifications.whatsapp.enabled', true);
        config()->set('exit-clearance.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('exit-clearance.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('exit-clearance.notifications.whatsapp.throttle.enabled', false);
    }

    public function test_command_reminds_only_pending_approvers_of_pending_requests(): void
    {
        $request = $this->createRequestWithPendingApprover(
            ExitClearanceRequestService::FORM_STATUS_PENDING,
            today()->toDateString(),
        );

        $approver = Approver::query()->create([
            'name'  => 'Uwis GA',
            'title' => 'GA Officer',
            'email' => 'uwis.ga@example.com',
            'phone' => '081234567890',
        ]);

        $approvedOne = Approver::query()->create([
            'name'  => 'Approved One',
            'title' => 'IT Manager',
            'email' => 'approved@example.com',
            'phone' => '081211111111',
        ]);

        $request->approvers()->sync([
            $approver->getKey()    => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
            $approvedOne->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_APPROVED],
        ]);

        $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(ApprovalRequestNotification::class, 1);

        Notification::assertSentOnDemand(ApprovalRequestNotification::class, function (
            ApprovalRequestNotification $notification,
            array $channels,
            object $notifiable,
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'uwis.ga@example.com';
        });

        Queue::assertPushed(SendWhatsAppNotification::class, 1);
    }

    public function test_command_reminds_at_h7_h1_on_departure_day_and_when_overdue(): void
    {
        $schedules = [
            'h7@example.com'      => today()->addDays(7)->toDateString(),
            'h1@example.com'      => today()->addDay()->toDateString(),
            'h0@example.com'      => today()->toDateString(),
            'overdue@example.com' => today()->subDays(3)->toDateString(),
        ];

        foreach ($schedules as $email => $departureDate) {
            $request = $this->createRequestWithPendingApprover(
                ExitClearanceRequestService::FORM_STATUS_PENDING,
                $departureDate,
            );

            $request->approvers()->sync([
                $this->createApprover($email)->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
            ]);
        }

        $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(ApprovalRequestNotification::class, 4);

        foreach (array_keys($schedules) as $email) {
            Notification::assertSentOnDemand(ApprovalRequestNotification::class, function (
                ApprovalRequestNotification $notification,
                array $channels,
                object $notifiable,
            ) use ($email): bool {
                return ($notifiable->routes['mail'] ?? null) === $email;
            });
        }
    }

    public function test_command_skips_requests_outside_the_reminder_schedule(): void
    {
        foreach ([today()->addDays(3), today()->addDays(8)] as $departureDate) {
            $request = $this->createRequestWithPendingApprover(
                ExitClearanceRequestService::FORM_STATUS_PENDING,
                $departureDate->toDateString(),
            );

            $request->approvers()->sync([
                $this->createApprover($this->uniqueEmail())->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
            ]);
        }

        $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_command_keeps_reminding_requests_without_departure_date(): void
    {
        $request = $this->createRequestWithPendingApprover(
            ExitClearanceRequestService::FORM_STATUS_PENDING,
            null,
        );

        $email = $this->uniqueEmail();
        $request->approvers()->sync([
            $this->createApprover($email)->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
        ]);

        $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(ApprovalRequestNotification::class, 1);
    }

    public function test_command_skips_approved_requests(): void
    {
        $request = $this->createRequestWithPendingApprover(
            ExitClearanceRequestService::FORM_STATUS_APPROVED,
            today()->toDateString(),
        );

        $email = $this->uniqueEmail();
        $request->approvers()->sync([
            $this->createApprover($email)->getKey() => ['status' => ExitClearanceRequestService::APPROVAL_PENDING],
        ]);

        $this->artisan('approvals:send-pending-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    protected function createRequestWithPendingApprover(string $formStatus, ?string $departureDate): Request
    {
        return Request::factory()->create([
            'department_id'  => Department::factory()->create()->id,
            'form_status'    => $formStatus,
            'departure_date' => $departureDate,
        ]);
    }

    protected function createApprover(string $email): Approver
    {
        return Approver::query()->create([
            'name'  => 'Reminder Approver',
            'title' => 'Approver',
            'email' => $email,
            'phone' => '0812'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }

    protected function uniqueEmail(): string
    {
        return 'approver-'.Str::uuid().'@example.com';
    }
}
