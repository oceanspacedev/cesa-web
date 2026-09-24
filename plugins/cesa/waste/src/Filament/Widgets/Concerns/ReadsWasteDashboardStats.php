<?php

namespace Cesa\Waste\Filament\Widgets\Concerns;

use Cesa\Waste\Services\WasteDashboardStats;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait ReadsWasteDashboardStats
{
    use InteractsWithPageFilters;

    /**
     * @return array<string, mixed>
     */
    protected function wasteStats(): array
    {
        return app(WasteDashboardStats::class)->for(
            $this->pageFilters['month'] ?? null,
            $this->pageFilters['year'] ?? null,
            filament()->auth()->user(),
        );
    }
}
