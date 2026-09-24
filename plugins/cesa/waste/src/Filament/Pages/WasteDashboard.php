<?php

namespace Cesa\Waste\Filament\Pages;

use Cesa\Waste\Filament\Pages\Concerns\SyncsWasteDashboardFilterDefaults;
use Cesa\Waste\Filament\Widgets\WasteDashboardCountTable;
use Cesa\Waste\Filament\Widgets\WasteDashboardDailyTable;
use Cesa\Waste\Filament\Widgets\WasteDashboardStatsOverview;
use Cesa\Waste\Filament\Widgets\WasteDashboardSummaryTable;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Services\WasteDashboardStats;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Carbon;
use Webkul\PluginManager\Package;

class WasteDashboard extends Page
{
    use HasFiltersForm {
        mountHasFilters as mountUnnormalizedWasteDashboardFilters;
    }
    use SyncsWasteDashboardFilterDefaults;

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function canAccess(): bool
    {
        return app(WasteAccessService::class)->canAccessReports(filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.dashboard');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.waste');
    }

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public function getHeading(): string
    {
        return __('waste::waste.admin.dashboard');
    }

    public function filtersForm(Schema $schema): Schema
    {
        $now = now('Asia/Jakarta');

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('month')
                        ->label(__('waste::waste.admin.dashboard_page.month'))
                        ->options(collect(range(1, 12))->mapWithKeys(
                            fn (int $month): array => [$month => Carbon::create(2000, $month, 1)->locale('id')->translatedFormat('F')],
                        )->all())
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->default((string) $now->month),
                    Select::make('year')
                        ->label(__('waste::waste.admin.dashboard_page.year'))
                        ->options(fn (): array => app(WasteDashboardStats::class)->yearOptions())
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->default((string) $now->year)
                        ->afterStateHydrated(function (Select $component, mixed $state): void {
                            $options = app(WasteDashboardStats::class)->yearOptions();

                            if (! array_key_exists((string) $state, $options)) {
                                $component->state((string) now('Asia/Jakarta')->year);
                            }
                        }),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFiltersFormContentComponent(),
            $this->getWidgetsContentComponent(),
        ]);
    }

    public function getFiltersFormContentComponent(): EmbeddedSchema
    {
        return EmbeddedSchema::make('filtersForm');
    }

    public function getWidgetsContentComponent(): Grid
    {
        return Grid::make($this->getColumns())
            ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets()));
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            WasteDashboardStatsOverview::class,
            WasteDashboardCountTable::make(['group' => 'brand']),
            WasteDashboardCountTable::make(['group' => 'outlet']),
            WasteDashboardDailyTable::class,
            WasteDashboardSummaryTable::class,
        ];
    }

    public function mountHasFilters(): void
    {
        $this->filters = app(WasteDashboardStats::class)->normalizeFilters($this->filters);
        $this->mountUnnormalizedWasteDashboardFilters();
    }

    public function updatedFilters(): void
    {
        $normalized = app(WasteDashboardStats::class)->normalizeFilters($this->filters);

        if ($this->filters === $normalized) {
            return;
        }

        $this->filters = $normalized;
        $this->getFiltersForm()->fill($this->filters);

        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    public function reportUrl(?string $status = null): string
    {
        return app(WasteDashboardStats::class)->reportUrl(
            $this->filters['month'] ?? null,
            $this->filters['year'] ?? null,
            $status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatsProperty(): array
    {
        return app(WasteDashboardStats::class)->for(
            $this->filters['month'] ?? null,
            $this->filters['year'] ?? null,
            filament()->auth()->user(),
        );
    }
}
