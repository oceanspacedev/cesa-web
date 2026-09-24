<?php

namespace Cesa\Waste\Filament\Widgets;

use Cesa\Waste\Filament\Widgets\Concerns\ReadsWasteDashboardStats;
use Cesa\Waste\Services\WasteDashboardStats;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WasteDashboardStatsOverview extends StatsOverviewWidget
{
    use ReadsWasteDashboardStats;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var int | array<string, ?int> | null
     */
    protected int|array|null $columns = [
        'default' => 1,
        'sm'      => 2,
        'xl'      => 4,
    ];

    protected function getStats(): array
    {
        $stats = $this->wasteStats();
        $urls = app(WasteDashboardStats::class);

        return [
            Stat::make(__('waste::waste.admin.dashboard_page.total'), $stats['total'])
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url($urls->reportUrl($this->pageFilters['month'] ?? null, $this->pageFilters['year'] ?? null)),
            Stat::make(__('waste::waste.status.pending'), $stats['statuses']['pending'] ?? 0)
                ->icon(Heroicon::OutlinedClock)
                ->color('warning')
                ->url($urls->reportUrl($this->pageFilters['month'] ?? null, $this->pageFilters['year'] ?? null, 'pending')),
            Stat::make(__('waste::waste.status.rejected'), $stats['statuses']['rejected'] ?? 0)
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->url($urls->reportUrl($this->pageFilters['month'] ?? null, $this->pageFilters['year'] ?? null, 'rejected')),
            Stat::make(__('waste::waste.status.approved'), $stats['statuses']['approved'] ?? 0)
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->url($urls->reportUrl($this->pageFilters['month'] ?? null, $this->pageFilters['year'] ?? null, 'approved')),
        ];
    }
}
