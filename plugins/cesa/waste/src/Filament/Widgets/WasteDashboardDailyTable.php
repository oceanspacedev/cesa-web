<?php

namespace Cesa\Waste\Filament\Widgets;

use Cesa\Waste\Filament\Widgets\Concerns\ReadsWasteDashboardStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class WasteDashboardDailyTable extends TableWidget
{
    use ReadsWasteDashboardStats;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn (): string => __('waste::waste.admin.dashboard_page.daily', ['period' => $this->wasteStats()['period_label']]))
            ->queryStringIdentifier('daily')
            ->records(fn (): array => $this->wasteStats()['daily'])
            ->columns([
                TextColumn::make('day')->label(__('waste::waste.admin.dashboard_page.date')),
                TextColumn::make('total')->label(__('waste::waste.admin.dashboard_page.submitted'))->alignEnd()->numeric(),
                TextColumn::make('pending')->label(__('waste::waste.admin.dashboard_page.waiting'))->alignEnd()->numeric()->color('warning'),
                TextColumn::make('approved')->label(__('waste::waste.status.approved'))->alignEnd()->numeric()->color('success'),
                TextColumn::make('rejected')->label(__('waste::waste.status.rejected'))->alignEnd()->numeric()->color('danger'),
            ])
            ->paginated(false)
            ->searchable(false);
    }
}
