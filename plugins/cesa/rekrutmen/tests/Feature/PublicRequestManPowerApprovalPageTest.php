<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Enums\RequestManPowerApprovalStatus;
use Cesa\Rekrutmen\Enums\RequestManPowerStatus;
use Cesa\Rekrutmen\Enums\StatusKebutuhan;
use Cesa\Rekrutmen\Jobs\SendNotificationDeliveryJob;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Models\RequestManPowerApprovalRequestedNotification;
use Cesa\Rekrutmen\Models\RequestManPowerStatusChangedNotification;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Webkul\Support\Models\Company;

class PublicRequestManPowerApprovalPageTest extends RekrutmenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRekrutmenWhatsAppEngine();
        $this->makeConnectedWhatsAppAccount();
    }

    public function test_public_approval_route_is_accessible(): void
    {
        $request = $this->createRequestWithApprovers();
        $request->sendApprovalRequestNotifications();

        $approval = $request->currentPendingApproval()->firstOrFail();

        $response = $this->get($approval->buildApprovalUrl());

        $response
            ->assertOk()
            ->assertSee(__('rekrutmen::livewire/public-request-man-power-approval-page.page_title'));

        expect(substr_count($response->getContent(), 'x-data="{ expanded: true }"'))
            ->toBeGreaterThanOrEqual(3);
    }

    public function test_public_approval_page_processes_approvals_sequentially_until_final_approval(): void
    {
        Notification::fake();
        Queue::fake();

        config()->set('rekrutmen.notifications.whatsapp.enabled', true);

        config()->set('rekrutmen.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('rekrutmen.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('rekrutmen.notifications.whatsapp.queue', 'whatsapp');

        RekrutmenPipeline::query()->create([
            'name' => 'Default Pipeline',
        ]);

        $request = $this->createRequestWithApprovers(emailAddress: 'requester@example.com');

        $request->sendApprovalRequestNotifications();

        Notification::assertSentOnDemandTimes(RequestManPowerApprovalRequestedNotification::class, 1);
        Notification::assertSentOnDemand(RequestManPowerApprovalRequestedNotification::class, function (
            RequestManPowerApprovalRequestedNotification $notification,
            array $channels,
            object $notifiable
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'first.approver@example.com';
        });
        Queue::assertPushed(SendNotificationDeliveryJob::class, 1);

        $firstApproval = $request->currentPendingApproval()->firstOrFail();

        $request->approveApprovalStep($firstApproval, 'Checked by first approver');

        $request->refresh();
        $request->load(['approvals', 'currentPendingApproval']);

        $this->assertSame(RequestManPowerStatus::PENDING, $request->status);
        $this->assertSame('second.approver@example.com', $request->currentPendingApproval?->approver_email);
        $this->assertSame(RequestManPowerApprovalStatus::APPROVED, $request->approvals->firstWhere('step_order', 1)?->status);
        $this->assertSame('Checked by first approver', $request->approvals->firstWhere('step_order', 1)?->notes);

        Notification::assertSentOnDemandTimes(RequestManPowerApprovalRequestedNotification::class, 2);
        Notification::assertSentOnDemand(RequestManPowerApprovalRequestedNotification::class, function (
            RequestManPowerApprovalRequestedNotification $notification,
            array $channels,
            object $notifiable
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'second.approver@example.com';
        });
        Queue::assertPushed(SendNotificationDeliveryJob::class, 2);

        $secondApproval = $request->currentPendingApproval()->firstOrFail();

        $request->approveApprovalStep($secondApproval, 'Final approval complete');

        $request->refresh();
        $request->load(['approvals', 'jobPosting']);

        $this->assertSame(RequestManPowerStatus::APPROVED, $request->status);
        $this->assertNotNull($request->jobPosting);
        $this->assertFalse($request->jobPosting->is_published);
        $this->assertSame(RequestManPowerApprovalStatus::APPROVED, $request->approvals->firstWhere('step_order', 2)?->status);
        $this->assertNull($request->currentPendingApproval()->first());

        Notification::assertSentOnDemand(RequestManPowerStatusChangedNotification::class, function (
            RequestManPowerStatusChangedNotification $notification,
            array $channels,
            object $notifiable
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'requester@example.com';
        });
    }

    public function test_public_approval_page_can_reject_the_request_and_stop_the_chain(): void
    {
        Notification::fake();

        $request = $this->createRequestWithApprovers(emailAddress: 'reject-requester@example.com');
        $request->sendApprovalRequestNotifications();

        $approval = $request->currentPendingApproval()->firstOrFail();

        $request->rejectApprovalStep($approval, 'Need revision');

        $request->refresh();
        $request->load('approvals');

        $this->assertSame(RequestManPowerStatus::REJECTED, $request->status);
        $this->assertSame(RequestManPowerApprovalStatus::REJECTED, $request->approvals->firstWhere('step_order', 1)?->status);
        $this->assertSame(RequestManPowerApprovalStatus::WAITING, $request->approvals->firstWhere('step_order', 2)?->status);
        $this->assertNull($request->currentPendingApproval()->first());

        Notification::assertSentOnDemandTimes(RequestManPowerApprovalRequestedNotification::class, 1);
        Notification::assertSentOnDemand(RequestManPowerStatusChangedNotification::class, function (
            RequestManPowerStatusChangedNotification $notification,
            array $channels,
            object $notifiable
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'reject-requester@example.com';
        });
    }

    public function test_resend_pending_approval_uses_mail_delay_and_whatsapp_queue_when_enabled(): void
    {
        Notification::fake();
        Queue::fake();

        Carbon::setTestNow('2026-04-22 10:00:00');

        config()->set('rekrutmen.notifications.mail.throttle', [
            'enabled'              => true,
            'min_interval_seconds' => 4,
            'max_interval_seconds' => 4,
            'key'                  => 'mail-resend-'.Str::uuid(),
        ]);
        config()->set('rekrutmen.notifications.whatsapp.enabled', true);

        config()->set('rekrutmen.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('rekrutmen.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('rekrutmen.notifications.whatsapp.queue', 'whatsapp');
        config()->set('rekrutmen.notifications.whatsapp.throttle', [
            'enabled'              => true,
            'min_interval_seconds' => 7,
            'max_interval_seconds' => 7,
            'key'                  => 'whatsapp-resend-'.Str::uuid(),
        ]);

        try {
            $request = $this->createRequestWithApprovers();

            $request->sendApprovalRequestNotifications();

            $initialApproval = $request->currentPendingApproval()->firstOrFail();
            $initialToken = $initialApproval->action_token;

            $request->notifyCurrentPendingApproval(true);

            $resentApproval = $request->currentPendingApproval()->firstOrFail();
            $mailNotifications = Notification::sent(
                new AnonymousNotifiable,
                RequestManPowerApprovalRequestedNotification::class
            )->values();
            $whatsAppJobs = Queue::pushed(SendNotificationDeliveryJob::class)->values();

            $this->assertCount(2, $mailNotifications);
            $this->assertNull($mailNotifications[0]->delay);
            $this->assertSame('2026-04-22 10:00:04', $mailNotifications[1]->delay?->format('Y-m-d H:i:s'));

            $this->assertCount(2, $whatsAppJobs);
            $this->assertSame('2026-04-22 10:00:00', $whatsAppJobs[0]->delay?->format('Y-m-d H:i:s'));
            $this->assertSame('2026-04-22 10:00:00', $whatsAppJobs[1]->delay?->format('Y-m-d H:i:s'));

            $this->assertNotSame($initialToken, $resentApproval->action_token);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_resend_pending_approval_keeps_email_flow_when_whatsapp_is_disabled(): void
    {
        Notification::fake();
        Queue::fake();

        config()->set('rekrutmen.notifications.whatsapp.enabled', false);

        $request = $this->createRequestWithApprovers();

        $request->sendApprovalRequestNotifications();
        $request->notifyCurrentPendingApproval(true);

        Notification::assertSentOnDemandTimes(RequestManPowerApprovalRequestedNotification::class, 2);
        Queue::assertNothingPushed();
        $this->assertSame(2, NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_SKIPPED)
            ->where('error_message', 'Pengiriman WhatsApp rekrutmen sedang nonaktif.')
            ->count());
    }

    private function createRequestWithApprovers(?string $emailAddress = 'requester@example.com'): RequestManPower
    {
        $company = Company::query()->create([
            'name' => 'PT Cesa Approval Flow',
        ]);

        $division = Division::query()->create([
            'name'       => 'IT',
            'company_id' => $company->getKey(),
        ]);

        Approver::query()->create([
            'name'           => 'First Approver',
            'email'          => 'first.approver@example.com',
            'phone'          => '081234567890',
            'title'          => 'HRBP',
            'approval_order' => 1,
            'division_id'    => $division->getKey(),
            'is_active'      => true,
        ]);

        Approver::query()->create([
            'name'           => 'Second Approver',
            'email'          => 'second.approver@example.com',
            'phone'          => '081234567891',
            'title'          => 'Division Head',
            'approval_order' => 2,
            'division_id'    => $division->getKey(),
            'is_active'      => true,
        ]);

        return RequestManPower::query()->create([
            'company_id'                 => $company->getKey(),
            'division_id'                => $division->getKey(),
            'email_address'              => $emailAddress,
            'nama_pengaju'               => 'Andi Saputra',
            'posisi_pengaju'             => 'HR Manager',
            'tanggal_pengajuan'          => '2026-03-02',
            'posisi_dibutuhkan'          => 'Software Engineer',
            'lokasi_penempatan'          => 'Jakarta',
            'status_kebutuhan'           => StatusKebutuhan::NEW_HIRING,
            'divisi'                     => 'IT',
            'level_pekerjaan'            => 'Staff',
            'nama_karyawan_replacement'  => null,
            'jumlah_karyawan_dibutuhkan' => 1,
            'estimasi_tanggal_join'      => '2026-04-01',
            'requirements_kualifikasi'   => 'PHP, Laravel, SQL',
            'job_description'            => 'Develop internal systems',
            'keterangan'                 => 'Urgent hiring',
            'status'                     => RequestManPowerStatus::PENDING,
            'approved_by'                => null,
        ]);
    }
}
