<?php

namespace App\Console\Commands;

use Cesa\ExitClearance\Models\Request as ExitClearanceRequest;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
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
    protected $signature = 'approvals:send-pending-reminders
                            {--only= : Comma-separated modules: waste, exit_clearance, form_transfer}';

    protected $description = 'Send daily reminders to pending approvers of waste, form-transfer, and exit-clearance';

    /**
     * @var array<string, string>
     */
    protected array $featureAliases = [
        'waste'          => 'waste',
        'exit_clearance' => 'exit_clearance',
        'exit-clearance' => 'exit_clearance',
        'exit'           => 'exit_clearance',
        'form_transfer'  => 'form_transfer',
        'form-transfer'  => 'form_transfer',
        'transfer'       => 'form_transfer',
    ];

    /**
     * @var array<string, string>
     */
    protected array $featureLabels = [
        'exit_clearance' => 'Exit-clearance approvers reminded',
        'form_transfer'  => 'Form-transfer approvers reminded',
        'waste'          => 'Waste approvers reminded',
    ];

    public function handle(
        ExitClearanceNotificationService $exitClearanceNotifications,
        TransferApprovalNotificationService $transferNotifications,
        ApprovalWorkflowService $approvalWorkflow,
        WasteApprovalService $wasteApprovals,
    ): int {
        try {
            $features = $this->selectedFeatures($this->option('only'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $runners = [
            'exit_clearance' => fn (): int => $this->remindExitClearance($exitClearanceNotifications),
            'form_transfer'  => fn (): int => $this->remindFormTransfer($transferNotifications, $approvalWorkflow),
            'waste'          => fn (): int => $this->remindWaste($wasteApprovals),
        ];

        $reminded = [];

        foreach ($features as $feature) {
            try {
                $reminded[$feature] = $runners[$feature]();
            } catch (Throwable $exception) {
                report($exception);

                $this->error("Failed to send {$feature} approval reminders: {$exception->getMessage()}");
                $reminded[$feature] = 0;
            }
        }

        foreach ($reminded as $feature => $count) {
            $this->info("{$this->featureLabels[$feature]}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function selectedFeatures(?string $only): array
    {
        $all = array_keys($this->featureLabels);

        if (blank($only)) {
            return $all;
        }

        $selected = [];

        foreach (preg_split('/\s*,\s*/', $only) ?: [] as $part) {
            $key = strtolower(trim((string) $part));

            if ($key === '') {
                continue;
            }

            if (! isset($this->featureAliases[$key])) {
                throw new \InvalidArgumentException(
                    'Invalid --only module ['.$key.']. Allowed: waste, exit_clearance, form_transfer.',
                );
            }

            $selected[$this->featureAliases[$key]] = true;
        }

        if ($selected === []) {
            throw new \InvalidArgumentException(
                'Invalid --only module. Allowed: waste, exit_clearance, form_transfer.',
            );
        }

        return array_values(array_filter(
            $all,
            fn (string $feature): bool => isset($selected[$feature]),
        ));
    }

    protected function remindExitClearance(ExitClearanceNotificationService $notifications): int
    {
        $notified = 0;

        ExitClearanceRequest::query()
            ->whereRaw('LOWER(form_status) = ?', ['pending'])
            ->whereNotNull('departure_date')
            ->where(function ($query): void {
                // H-7, H-1, then H through H+3 (stop reminding after 3 days past departure).
                $query
                    ->whereDate('departure_date', today()->addDays(7))
                    ->orWhereDate('departure_date', today()->addDay())
                    ->orWhere(function ($window): void {
                        $window
                            ->whereDate('departure_date', '<=', today())
                            ->whereDate('departure_date', '>=', today()->subDays(3));
                    });
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
            ->needsApprovalReminder()
            ->chunkById(100, function ($requests) use ($notifications, $workflow, &$notified): void {
                foreach ($requests as $request) {
                    if (! $request->needsApprovalReminder()) {
                        continue;
                    }

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
