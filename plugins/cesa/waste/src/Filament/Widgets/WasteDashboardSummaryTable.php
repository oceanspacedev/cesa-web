<?php

namespace Cesa\Waste\Filament\Widgets;

use Cesa\Waste\Filament\Widgets\Concerns\ReadsWasteDashboardStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class WasteDashboardSummaryTable extends TableWidget
{
    use ReadsWasteDashboardStats;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('waste::waste.admin.dashboard_page.summary'))
            ->queryStringIdentifier('summary')
            ->records(fn (): array => collect($this->wasteStats()['summary'])
                ->map(fn (array $row): array => [
                    'brand'    => $row[0],
                    'outlet'   => $row[1],
                    'category' => $row[2],
                    'item'     => $row[4].' ('.$row[3].')',
                    'quantity' => $row[6].' '.$row[5],
                ])
                ->all())
            ->columns([
                TextColumn::make('brand')->label(__('waste::waste.admin.dashboard_page.brand')),
                TextColumn::make('outlet')->label(__('waste::waste.admin.dashboard_page.outlet')),
                TextColumn::make('category')->label(__('waste::waste.admin.dashboard_page.category')),
                TextColumn::make('item')->label(__('waste::waste.admin.dashboard_page.item')),
                TextColumn::make('quantity')->label(__('waste::waste.admin.dashboard_page.quantity'))->alignEnd(),
            ])
            ->paginated(false)
            ->searchable(false)
            ->emptyStateHeading(__('waste::waste.admin.dashboard_page.empty_summary'));
    }
}
