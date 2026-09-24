<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteReportPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canAccessReports($user);
    }

    public function view(User $user, WasteReport $report): bool
    {
        return app(WasteAccessService::class)->canManageBrand($user, $report->brand)
            || app(WasteAccessService::class)->canManageOutlet($user, $report->outlet);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canAccessReports($user);
    }

    public function update(User $user, WasteReport $report): bool
    {
        return $this->view($user, $report) && ! $report->latestVersion?->approvals()->exists();
    }

    public function review(User $user, WasteReport $report): bool
    {
        return app(WasteAccessService::class)->canManageBrand($user, $report->brand);
    }

    public function delete(User $user, WasteReport $report): bool
    {
        return $report->status === WasteReportStatus::Pending
            && app(WasteAccessService::class)->canManageBrand($user, $report->brand)
            && ! $report->versions()->whereIn('status', [WasteReportStatus::Approved, WasteReportStatus::Rejected])->exists()
            && ! $report->versions()->whereHas('approvals', fn ($query) => $query->whereIn('status', ['approved', 'rejected']))->exists();
    }
}
