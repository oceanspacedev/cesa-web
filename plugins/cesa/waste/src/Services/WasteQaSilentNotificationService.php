<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteApproval;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteReportVersion;

class WasteQaSilentNotificationService extends WasteNotificationService
{
    public function queueSubmission(WasteReport $report, string $progressToken, string $manageToken, array $approvalTokens): void {}

    public function queueNextApproval(WasteReport $report, WasteReportVersion $version, WasteApproval $approval, string $token): void {}

    public function queueRequester(WasteReport $report, string $type, ?string $progressToken = null, ?string $manageToken = null): void {}
}
