<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteApproval;
use Cesa\Waste\Models\WasteReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WasteApprovalService
{
    public function __construct(
        protected WasteReportService $reportService,
        protected WasteNotificationService $notificationService,
    ) {}

    /**
     * @return array{report: WasteReport, next_token: ?string, next_approval: ?WasteApproval, progress_token: ?string, manage_token: ?string}
     */
    public function approve(string $token): array
    {
        return $this->decide($token, WasteApprovalStatus::Approved, null);
    }

    /**
     * @return array{report: WasteReport, next_token: ?string, next_approval: ?WasteApproval, progress_token: ?string, manage_token: ?string}
     */
    public function reject(string $token, string $note): array
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['rejection_reason' => 'Alasan penolakan wajib diisi.']);
        }

        return $this->decide($token, WasteApprovalStatus::Rejected, $note);
    }

    /**
     * Re-notify the currently pending approval step with a fresh access token.
     */
    public function remind(WasteReport $report): bool
    {
        $result = DB::transaction(function () use ($report): ?array {
            $report = WasteReport::query()
                ->whereKey($report->getKey())
                ->lockForUpdate()
                ->first();

            $version = $report?->latestVersion()->lockForUpdate()->first();

            $approval = $version?->approvals()
                ->where('status', WasteApprovalStatus::Pending)
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if (! $report || ! $version || ! $approval || $report->status !== WasteReportStatus::Pending) {
                return null;
            }

            $token = Str::random(64);

            $approval->forceFill([
                'token_hash'  => $this->reportService->tokenHash($token),
                'notified_at' => now(),
            ])->save();

            $report->activityLogs()->create([
                'version_id' => $version->getKey(),
                'event'      => 'reminder_sent',
                'actor_type' => 'system',
                'metadata'   => ['step_order' => $approval->step_order],
            ]);

            return [
                'report'   => $report->fresh(['brand', 'outlet', 'latestVersion.approvals']),
                'version'  => $version->fresh('approvals'),
                'approval' => $approval->fresh(),
                'token'    => $token,
            ];
        });

        if ($result === null) {
            return false;
        }

        $this->notificationService->queueApprovalReminder(
            $result['report'],
            $result['version'],
            $result['approval'],
            $result['token'],
        );

        return true;
    }

    /**
     * @return array{report: WasteReport, next_token: ?string, next_approval: ?WasteApproval, progress_token: ?string, manage_token: ?string}
     */
    protected function decide(string $token, WasteApprovalStatus $decision, ?string $note): array
    {
        $result = DB::transaction(function () use ($token, $decision, $note): array {
            $approval = WasteApproval::query()
                ->where('token_hash', $this->reportService->tokenHash($token))
                ->lockForUpdate()
                ->first();

            if (! $approval) {
                throw ValidationException::withMessages(['token' => 'Tautan approval sudah tidak berlaku.']);
            }

            $version = $approval->version()->lockForUpdate()->firstOrFail();
            $report = $version->report()->lockForUpdate()->firstOrFail();

            if ($report->status !== WasteReportStatus::Pending || $approval->status !== WasteApprovalStatus::Pending) {
                throw ValidationException::withMessages(['token' => 'Approval ini sudah diproses atau tidak sedang aktif.']);
            }

            $activeApproval = $version->approvals()
                ->where('status', WasteApprovalStatus::Pending)
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if (! $activeApproval || $activeApproval->getKey() !== $approval->getKey()) {
                throw ValidationException::withMessages(['token' => 'Urutan approval belum sampai pada langkah ini.']);
            }

            $approval->forceFill([
                'status'        => $decision,
                'decision_note' => $note,
                'decided_at'    => now(),
                'token_hash'    => null,
            ])->save();

            $nextToken = null;
            $nextApproval = null;
            $progressToken = null;
            $manageToken = null;

            if ($decision === WasteApprovalStatus::Rejected) {
                $progressToken = Str::random(64);
                $manageToken = Str::random(64);
                $version->forceFill([
                    'status'           => WasteReportStatus::Rejected,
                    'rejection_reason' => $note,
                ])->save();
                $report->forceFill([
                    'status'              => WasteReportStatus::Rejected,
                    'rejected_at'         => now(),
                    'progress_token_hash' => $this->reportService->tokenHash($progressToken),
                    'manage_token_hash'   => $this->reportService->tokenHash($manageToken),
                    'token_version'       => ((int) $report->token_version) + 1,
                ])->save();
                $eventName = 'rejected';
            } else {
                $next = $version->approvals()
                    ->where('status', WasteApprovalStatus::Waiting)
                    ->orderBy('step_order')
                    ->lockForUpdate()
                    ->first();

                if ($next) {
                    $nextToken = Str::random(64);
                    $next->forceFill([
                        'status'     => WasteApprovalStatus::Pending,
                        'token_hash' => $this->reportService->tokenHash($nextToken),
                    ])->save();
                    $nextApproval = $next->fresh();
                    $eventName = 'approved_step';
                } else {
                    $eventName = 'external_approved';
                }
            }

            $report->activityLogs()->create([
                'version_id' => $version->getKey(),
                'event'      => $eventName,
                'actor_type' => 'public_approval',
                'metadata'   => ['step_order' => $approval->step_order],
            ]);

            return [
                'report'         => $report->fresh(['brand', 'outlet', 'latestVersion.approvals']),
                'next_token'     => $nextToken,
                'next_approval'  => $nextApproval,
                'progress_token' => $progressToken,
                'manage_token'   => $manageToken,
            ];
        });

        if ($result['next_token'] && $result['next_approval']) {
            $this->notificationService->queueNextApproval(
                $result['report'],
                $result['report']->latestVersion,
                $result['next_approval'],
                $result['next_token'],
            );
        }

        if ($decision === WasteApprovalStatus::Rejected) {
            $this->notificationService->queueRequester(
                $result['report'],
                'rejected',
                $result['progress_token'],
                $result['manage_token'],
            );
        }

        return $result;
    }
}
