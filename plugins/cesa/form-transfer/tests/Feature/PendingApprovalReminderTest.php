<?php

namespace Cesa\FormTransfer\Tests\Feature;

use Cesa\FormTransfer\Enums\ApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Jobs\SendWhatsAppNotification;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Notifications\ApprovalRequestNotification;
use Cesa\FormTransfer\Tests\FormTransferTestCase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

class PendingApprovalReminderTest extends FormTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require base_path('plugins/cesa/form-transfer/routes/web.php');

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        Notification::fake();
        Queue::fake();

        config()->set('form-transfer.notifications.mail.enabled', true);
        config()->set('form-transfer.notifications.mail.throttle.enabled', false);
        config()->set('form-transfer.notifications.whatsapp.enabled', true);
        config()->set('form-transfer.notifications.whatsapp.endpoint', 'https://example.com/whatsapp');
        config()->set('form-transfer.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('form-transfer.notifications.whatsapp.throttle.enabled', false);
    }

    public function test_command_reminds_only_the_current_pending_step_and_stamps_notified_at(): void
    {
        $request = $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
        );

        $this->artisan('approvals:send-pending-reminders', ['--only' => 'form_transfer'])->assertSuccessful();

        Notification::assertSentOnDemandTimes(ApprovalRequestNotification::class, 1);

        Notification::assertSentOnDemand(ApprovalRequestNotification::class, function (
            ApprovalRequestNotification $notification,
            array $channels,
            object $notifiable,
        ): bool {
            return ($notifiable->routes['mail'] ?? null) === 'second@example.com';
        });

        Queue::assertPushed(SendWhatsAppNotification::class, 1);

        $approvals = $request->fresh()->approvals;

        $this->assertArrayHasKey('notified_at', $approvals[1]);
        $this->assertNotEmpty($approvals[1]['notified_at']);
        $this->assertArrayNotHasKey('notified_at', $approvals[0]);
    }

    public function test_command_skips_approved_requests(): void
    {
        $this->createRequest(
            TransferRequestApprovalStatus::APPROVED,
            ApprovalStatus::PENDING,
        );

        $this->artisan('approvals:send-pending-reminders', ['--only' => 'form_transfer'])->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_command_skips_requests_with_done_realization_status(): void
    {
        $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
            TransferRequestRealizationStatus::DONE,
        );

        $this->artisan('approvals:send-pending-reminders', ['--only' => 'form_transfer'])->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_command_skips_requests_with_cancelled_realization_status(): void
    {
        $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
            TransferRequestRealizationStatus::CANCELLED,
        );

        $this->artisan('approvals:send-pending-reminders', ['--only' => 'form_transfer'])->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_command_skips_requests_with_partial_realization_status(): void
    {
        $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
            TransferRequestRealizationStatus::PARTIAL,
        );

        $this->artisan('approvals:send-pending-reminders', ['--only' => 'form_transfer'])->assertSuccessful();

        Notification::assertNothingSent();
        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_needs_approval_reminder_only_when_both_statuses_are_pending(): void
    {
        $pending = $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
            TransferRequestRealizationStatus::PENDING,
        );
        $done = $this->createRequest(
            TransferRequestApprovalStatus::PENDING,
            ApprovalStatus::PENDING,
            TransferRequestRealizationStatus::DONE,
        );

        $this->assertTrue($pending->needsApprovalReminder());
        $this->assertFalse($done->needsApprovalReminder());
        $this->assertSame(1, TransferRequest::query()->needsApprovalReminder()->whereKey($pending->getKey())->count());
        $this->assertSame(0, TransferRequest::query()->needsApprovalReminder()->whereKey($done->getKey())->count());
    }

    protected function createRequest(
        TransferRequestApprovalStatus $requestStatus,
        ApprovalStatus $stepStatus,
        TransferRequestRealizationStatus $realizationStatus = TransferRequestRealizationStatus::PENDING,
    ): TransferRequest {
        return TransferRequest::factory()->create([
            'approval_status'    => $requestStatus->value,
            'realization_status' => $realizationStatus->value,
            'approvals'          => [
                [
                    'label'   => 'Approval 1',
                    'name'    => 'First Approver',
                    'email'   => 'first@example.com',
                    'phone'   => '081111111111',
                    'title'   => 'Finance Manager',
                    'status'  => ApprovalStatus::APPROVED->value,
                    'task_id' => 'task-step-1',
                ],
                [
                    'label'   => 'Approval 2',
                    'name'    => 'Second Approver',
                    'email'   => 'second@example.com',
                    'phone'   => '082222222222',
                    'title'   => 'HR Manager',
                    'status'  => $stepStatus->value,
                    'task_id' => 'task-step-2',
                ],
            ],
        ]);
    }
}
