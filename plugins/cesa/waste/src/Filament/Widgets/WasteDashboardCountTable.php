<?php

namespace Cesa\Waste\Filament\Widgets;

use Cesa\Waste\Filament\Widgets\Concerns\ReadsWasteDashboardStats;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class WasteDashboardCountTable extends TableWidget
{
    use ReadsWasteDashboardStats;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    public string $group = 'brand';

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->group === 'outlet'
                ? __('waste::waste.admin.dashboard_page.by_outlet')
                : __('waste::waste.admin.dashboard_page.by_brand'))
            ->queryStringIdentifier($this->group)
            ->records(fn (): array => collect($this->wasteStats()[$this->group === 'outlet' ? 'by_outlet' : 'by_brand'])
                ->map(fn (int $count, string $name): array => [
                    'name'  => $name,
                    'count' => $count,
                ])
                ->values()
                ->all())
            ->columns([
                TextColumn::make('name')
                    ->label($this->group === 'outlet'
                        ? __('waste::waste.admin.dashboard_page.outlet')
                        : __('waste::waste.admin.dashboard_page.brand')),
                TextColumn::make('count')
                    ->label(__('waste::waste.admin.dashboard_page.count'))
                    ->alignEnd()
                    ->numeric(),
            ])
            ->paginated(false)
            ->searchable(false)
            ->emptyStateHeading(__('waste::waste.admin.dashboard_page.empty'));
    }
}
