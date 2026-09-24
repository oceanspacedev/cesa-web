<?php

namespace Cesa\Waste\Livewire;

use Cesa\Waste\Services\WasteReportService;

class PublicWasteRevisionPage extends PublicWasteReportForm
{
    public function mount(?string $token = null, ?string $outlet = null, ?string $manageToken = null): void
    {
        abort_unless(filled($token), 404);
        $report = app(WasteReportService::class)->reportForManageToken($token);
        abort_unless($report->status?->value === 'rejected', 404);
        abort_unless($report->brand->is_active && $report->outlet->is_active, 404);
        $this->initializeFromReport($report, $token);
    }
}
