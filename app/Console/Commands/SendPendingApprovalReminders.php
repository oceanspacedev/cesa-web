<?php

namespace App\Console\Commands;

use Cesa\ExitClearance\Models\Request as ExitClearanceRequest;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\ApprovalWorkflowService;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteApprovalService;
use Illuminate\Console\Command;
use Throwable;

class SendPendingApprovalReminders extends Command
{
    protected $signature = 'approvals:send-pending-reminders';

    protected $description = 'Send daily reminders to pending approvers of exit-clearance, form-transfer, and waste requests';

    public function handle(
        ExitClearanceNotificationService $exitClearanceNotifications,
        TransferApprovalNotificationService $transferNotifications,
        ApprovalWorkflowService $approvalWorkflow,
        WasteApprovalService $wasteApprovals,
    ): int {
        $reminded = [
            'exit_clearance' => 0,
            'form_transfer'  => 0,
            'waste'          => 0,
        ];

        foreach ([
            'exit_clearance' => fn (): int => $this->remindExitClearance($exitClearanceNotifications),
            'form_transfer'  => fn (): int => $this->remindFormTransfer($transferNotifications, $approvalWorkflow),
            'waste'          => fn (): int => $this->remindWaste($wasteApprovals),
        ] as $feature => $remindFeature) {
            try {
                $reminded[$feature] = $remindFeature();
            } catch (Throwable $exception) {
                report($exception);

                $this->error("Failed to send {$feature} approval reminders: {$exception->getMessage()}");
            }
        }

        $this->info("Exit-clearance approvers reminded: {$reminded['exit_clearance']}");
        $this->info("Form-transfer approvers reminded: {$reminded['form_transfer']}");
        $this->info("Waste approvers reminded: {$reminded['waste']}");

        return self::SUCCESS;
    }

    protected function remindExitClearance(ExitClearanceNotificationService $notifications): int
    {
        $notified = 0;

        ExitClearanceRequest::query()
            ->whereRaw('LOWER(form_status) = ?', ['pending'])
            ->where(function ($query): void {
                $query
                    ->whereNull('departure_date')
                    ->orWhereDate('departure_date', '<=', today())
                    ->orWhereDate('departure_date', today()->addDay())
                    ->orWhereDate('departure_date', today()->addDays(7));
            })
            ->chunkById(100, function ($requests) use ($notifications, &$notified): void {
                foreach ($requests as $request) {
                    $notified += $notifications->notifyPendingApprovers($request);
                }
            });

        return $notified;
    }

    protected function remindFormTransfer(
        TransferApprovalNotificationService $notifications,
        ApprovalWorkflowService $workflow,
    ): int {
        $notified = 0;

        TransferRequest::query()
            ->where('approval_status', TransferRequestApprovalStatus::PENDING)
            ->chunkById(100, function ($requests) use ($notifications, $workflow, &$notified): void {
                foreach ($requests as $request) {
                    $approvals = $request->approvals ?? [];
                    $pending = $workflow->getCurrentPendingApproval($approvals);

                    if (! $pending || blank($pending['approval']['email'] ?? null)) {
                        continue;
                    }

                    $notifications->notifyApprover($request, $pending['approval'], $approvals);

                    if (isset($pending['index'], $approvals[$pending['index']])) {
                        $approvals[$pending['index']]['notified_at'] = now()->toISOString();
                        $request->approvals = $approvals;
                        $request->save();
                    }

                    $notified++;
                }
            });

        return $notified;
    }

    protected function remindWaste(WasteApprovalService $wasteApprovals): int
    {
        $notified = 0;

        WasteReport::query()
            ->where('status', WasteReportStatus::Pending)
            ->whereHas('latestVersion', fn ($query) => $query->whereHas(
                'approvals',
                fn ($approvals) => $approvals->where('status', WasteApprovalStatus::Pending->value),
            ))
            ->chunkById(100, function ($reports) use ($wasteApprovals, &$notified): void {
                foreach ($reports as $report) {
                    if ($wasteApprovals->remind($report)) {
                        $notified++;
                    }
                }
            });

        return $notified;
    }
}
