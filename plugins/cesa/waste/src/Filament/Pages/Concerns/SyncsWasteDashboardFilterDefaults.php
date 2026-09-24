<?php

namespace Cesa\Waste\Filament\Pages\Concerns;

use Cesa\Waste\Services\WasteDashboardStats;

trait SyncsWasteDashboardFilterDefaults
{
    public function bootedSyncsWasteDashboardFilterDefaults(): void
    {
        $this->filters = app(WasteDashboardStats::class)->normalizeFilters($this->filters);
        $this->getFiltersForm()->fill($this->filters);

        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }
}
