<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteApproval;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteReportVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;

class WasteMisReviewService
{
    public function __construct(
        protected WasteNotificationService $notificationService,
        protected WasteReportService $reportService,
    ) {}

    public function approve(WasteReport $report, User $reviewer): WasteReport
    {
        return $this->decide($report, $reviewer, WasteReportStatus::Approved);
    }

    public function reject(WasteReport $report, User $reviewer, string $reason): WasteReport
    {
        $reason = trim($reason);

        validator(['reason' => $reason], [
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'Alasan penolakan wajib diisi.',
            'reason.max'      => 'Alasan penolakan maksimal 2000 karakter.',
        ])->validate();

        return $this->decide($report, $reviewer, WasteReportStatus::Rejected, $reason);
    }

    protected function decide(WasteReport $report, User $reviewer, WasteReportStatus $decision, ?string $reason = null): WasteReport
    {
        [$reviewedReport, $newManageToken] = DB::transaction(function () use ($report, $reviewer, $decision, $reason): array {
            $lockedReport = WasteReport::query()
                ->whereKey($report->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($reviewer)->authorize('review', $lockedReport);

            $version = $lockedReport->latestVersion()
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensurePending($lockedReport, $version);
            $approvals = $version->approvals()->lockForUpdate()->get();
            if ($approvals->isNotEmpty() && ! $this->allExternalApprovalsApproved($approvals)) {
                throw ValidationException::withMessages([
                    'report' => 'Semua langkah approval harus disetujui sebelum laporan ini disetujui.',
                ]);
            }

            if ($decision === WasteReportStatus::Approved) {
                if ($version->events()->doesntExist() || $version->events()->whereDoesntHave('lines')->exists()) {
                    throw ValidationException::withMessages([
                        'report' => 'Setiap kejadian harus memiliki barang sebelum disetujui.',
                    ]);
                }
            }

            $now = now();
            $newManageToken = $decision === WasteReportStatus::Rejected && $approvals->isNotEmpty()
                ? Str::random(64)
                : null;

            $version->forceFill([
                'status'           => $decision,
                'rejection_reason' => $reason,
            ])->save();

            $lockedReport->forceFill([
                'status'            => $decision,
                'approved_at'       => $decision === WasteReportStatus::Approved ? $now : null,
                'rejected_at'       => $decision === WasteReportStatus::Rejected ? $now : null,
                'manage_token_hash' => $decision === WasteReportStatus::Rejected
                    ? ($newManageToken ? $this->reportService->tokenHash($newManageToken) : $lockedReport->progress_token_hash)
                    : null,
                'token_version' => ((int) $lockedReport->token_version) + 1,
            ])->save();

            $lockedReport->activityLogs()->create([
                'version_id' => $version->getKey(),
                'event'      => $decision === WasteReportStatus::Approved ? 'mis_approved' : 'mis_rejected',
                'actor_type' => 'admin',
                'actor_id'   => $reviewer->getKey(),
                'metadata'   => $reason === null ? null : ['reason' => $reason],
            ]);

            return [$lockedReport->fresh('latestVersion'), $newManageToken];
        }, 3);

        $this->notificationService->queueRequester(
            $reviewedReport,
            $decision === WasteReportStatus::Approved ? 'approved' : 'rejected',
            manageToken: $newManageToken,
        );

        return $reviewedReport;
    }

    protected function ensurePending(WasteReport $report, WasteReportVersion $version): void
    {
        if ($report->status !== WasteReportStatus::Pending) {
            throw ValidationException::withMessages(['report' => 'Laporan ini sudah diproses.']);
        }

        if ((int) $version->report_id !== (int) $report->getKey() || $version->status !== WasteReportStatus::Pending) {
            throw ValidationException::withMessages(['report' => 'Versi laporan ini sudah diproses.']);
        }
    }

    /**
     * @param  Collection<int, WasteApproval>  $approvals
     */
    protected function allExternalApprovalsApproved(Collection $approvals): bool
    {
        return $approvals->every(fn (WasteApproval $approval): bool => $approval->status === WasteApprovalStatus::Approved);
    }
}
