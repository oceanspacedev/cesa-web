<?php

namespace Cesa\Waste\Filament\Resources;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\CreateWasteReport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\EditWasteReport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ListWasteReports;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ViewWasteReport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEventLine;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Services\WasteAccessService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Webkul\PluginManager\Package;

class WasteReportResource extends Resource
{
    protected static ?string $model = WasteReport::class;

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.waste');
    }

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function getModelLabel(): string
    {
        return __('waste::waste.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('waste::waste.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(WasteAccessService::class)->scopeReports(parent::getEloquentQuery()->with(['brand', 'outlet', 'latestVersion']), filament()->auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Wizard::make([
                Step::make(__('waste::waste.steps.start'))
                    ->schema([
                        Select::make('brand_id')
                            ->label('Brand')
                            ->options(function (Select $component): array {
                                $query = WasteBrand::query()->orderBy('name');
                                if (! $component->getRecord()) {
                                    $query->where('is_active', true);
                                }

                                return app(WasteAccessService::class)->scopeBrands($query, filament()->auth()->user())->pluck('name', 'id')->all();
                            })
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('outlet_id', null);
                                $set('events', [static::emptyEvent()]);
                            }),
                        Select::make('outlet_id')
                            ->label(__('waste::waste.fields.outlet'))
                            ->options(function (Get $get, Select $component): array {
                                $query = WasteOutlet::query()->where('brand_id', $get('brand_id'))->orderBy('name');
                                if (! $component->getRecord()) {
                                    $query->where('is_active', true);
                                }

                                return app(WasteAccessService::class)->scopeOutlets($query, filament()->auth()->user())->pluck('name', 'id')->all();
                            })
                            ->required()
                            ->searchable(),
                        DatePicker::make('event_date')->label(__('waste::waste.fields.event_date'))->required()->native(false),
                        TextInput::make('reporter_name')->label(__('waste::waste.fields.reporter_name'))->placeholder(__('waste::waste.placeholders.reporter_name'))->required()->maxLength(255),
                        TextInput::make('reporter_phone')->label(__('waste::waste.fields.reporter_phone'))->placeholder(__('waste::waste.placeholders.reporter_phone'))->tel()
                            ->required(fn (?WasteReport $record): bool => $record === null || (! $record->hasQaSourceMarker() && $record->reporter_phone !== '0000000000'))->maxLength(40),
                        TextInput::make('reporter_email')->label(__('waste::waste.fields.reporter_email'))->placeholder(__('waste::waste.placeholders.reporter_email'))->email()->maxLength(255)->columnSpanFull(),
                    ])
                    ->columns(2),
                Step::make(__('waste::waste.steps.report'))
                    ->schema([
                        Repeater::make('events')
                            ->hiddenLabel()
                            ->schema([
                                Hidden::make('id'),
                                Repeater::make('lines')
                                    ->hiddenLabel()
                                    ->schema([
                                        Hidden::make('id'),
                                        Select::make('item_id')
                                            ->label(__('waste::waste.choose_item'))
                                            ->placeholder(__('waste::waste.choose_item'))
                                            ->options(fn (Get $get): array => static::itemOptions(static::selectedBrandId($get)))
                                            ->required()
                                            ->searchable()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                                $set('unit', WasteItem::query()->whereKey($state)->value('unit'));
                                            })
                                            ->columnSpan(2),
                                        TextInput::make('quantity')
                                            ->label(__('waste::waste.fields.quantity'))
                                            ->placeholder(__('waste::waste.placeholders.quantity'))
                                            ->numeric()
                                            ->required(),
                                        Select::make('unit')
                                            ->label('Satuan')
                                            ->options(fn (Get $get): array => static::unitOptionsForItem(filled($get('item_id')) ? (int) $get('item_id') : null))
                                            ->required(),
                                        Select::make('sm_checked')
                                            ->label('SM')
                                            ->options(['1' => 'TRUE', '0' => 'FALSE'])
                                            ->placeholder('Belum ditandai')
                                            ->helperText('Ditandai MIS per barang.')
                                            ->visible(fn (Get $get): bool => static::selectedBrandCode($get) === 'JCHICKEN'
                                                && static::canReviewSelectedBrand($get)),
                                        Select::make('audit_checked')
                                            ->label('AUDIT')
                                            ->options(['1' => 'TRUE', '0' => 'FALSE'])
                                            ->placeholder('Belum ditandai')
                                            ->helperText('Ditandai MIS per barang.')
                                            ->visible(fn (Get $get): bool => in_array(static::selectedBrandCode($get), ['JCHICKEN', 'LUUCA'], true)
                                                && static::canReviewSelectedBrand($get)),
                                    ])
                                    ->columns(6)
                                    ->minItems(1)
                                    ->defaultItems(1)
                                    ->addActionLabel(__('waste::waste.add_line'))
                                    ->reorderable(false)
                                    ->columnSpanFull(),
                                TextInput::make('reason')
                                    ->label(__('waste::waste.fields.reason'))
                                    ->placeholder(__('waste::waste.placeholders.reason'))
                                    ->required()
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                                Select::make('section')
                                    ->label(__('waste::waste.fields.section'))
                                    ->placeholder(__('waste::waste.placeholders.section'))
                                    ->options(fn (Get $get): array => static::sectionOptions(static::selectedBrandId($get)))
                                    ->visible(fn (Get $get): bool => static::sectionOptions(static::selectedBrandId($get)) !== []),
                                Select::make('category_id')
                                    ->label(__('waste::waste.fields.category'))
                                    ->placeholder(__('waste::waste.choose'))
                                    ->options(fn (Get $get): array => WasteCategory::query()
                                        ->where('brand_id', static::selectedBrandId($get))
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->required()
                                    ->searchable()
                                    ->columnSpan(fn (Get $get): int => static::sectionOptions(static::selectedBrandId($get)) === [] ? 2 : 1),
                                Select::make('pip_item_id')
                                    ->label(__('waste::waste.fields.pip_item'))
                                    ->placeholder(__('waste::waste.no_pip'))
                                    ->options(fn (Get $get): array => static::referenceItemOptions(static::selectedBrandId($get)))
                                    ->searchable()
                                    ->visible(fn (Get $get): bool => static::referenceItemOptions(static::selectedBrandId($get)) !== []),
                                TextInput::make('pip_quantity')
                                    ->label(__('waste::waste.fields.pip_quantity'))
                                    ->placeholder(__('waste::waste.placeholders.pip_quantity'))
                                    ->numeric()
                                    ->visible(fn (Get $get): bool => static::referenceItemOptions(static::selectedBrandId($get)) !== []),
                            ])
                            ->columns(2)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel(__('waste::waste.add_event'))
                            ->reorderable(false),
                    ]),
            ])
                ->nextAction(fn (Action $action): Action => $action->label(__('waste::waste.next')))
                ->previousAction(fn (Action $action): Action => $action->label(__('waste::waste.back')))
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="submit">
                        {{ $label }}
                    </x-filament::button>
                BLADE, ['label' => __('waste::waste.submit')]))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyEvent(): array
    {
        return [
            'section'      => null,
            'category_id'  => null,
            'reason'       => null,
            'pip_item_id'  => null,
            'pip_quantity' => null,
            'lines'        => [['item_id' => null, 'quantity' => null, 'unit' => null]],
        ];
    }

    protected static function selectedBrandId(Get $get): ?int
    {
        $brandId = $get('/data.brand_id');

        return filled($brandId) ? (int) $brandId : null;
    }

    protected static function selectedBrandCode(Get $get): ?string
    {
        $brandId = static::selectedBrandId($get);

        return $brandId ? strtoupper((string) WasteBrand::query()->whereKey($brandId)->value('code')) : null;
    }

    protected static function canReviewSelectedBrand(Get $get): bool
    {
        $brandId = static::selectedBrandId($get);
        $brand = $brandId ? WasteBrand::query()->find($brandId) : null;

        return $brand && app(WasteAccessService::class)->canManageBrand(filament()->auth()->user(), $brand);
    }

    /**
     * @return array<int|string, string>
     */
    protected static function itemOptions(?int $brandId, bool $pipOnly = false): array
    {
        if (! $brandId) {
            return [];
        }

        return WasteItem::query()
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->whereNotNull('unit')
            ->when($pipOnly, fn ($query) => $query->whereRaw('upper(item_type) = ?', ['PIP']))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (WasteItem $item): array => [
                $item->getKey() => sprintf('%s — %s (%s)', $item->name, $item->code, $item->unit),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function referenceItemOptions(?int $brandId): array
    {
        if (! $brandId) {
            return [];
        }

        $brandCode = WasteBrand::query()->whereKey($brandId)->value('code');

        return static::itemOptions($brandId, pipOnly: strtoupper((string) $brandCode) !== 'MOMOYO');
    }

    /**
     * @return array<string, string>
     */
    protected static function unitOptionsForItem(?int $itemId): array
    {
        if (! $itemId) {
            return [];
        }

        $item = WasteItem::query()->with('alternateUnits')->find($itemId);
        if (! $item || blank($item->unit)) {
            return [];
        }

        return collect([(string) $item->unit, ...$item->alternateUnits->where('is_active', true)->pluck('code')->all()])
            ->unique()
            ->mapWithKeys(fn (string $code): array => [$code => $code])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function sectionOptions(?int $brandId): array
    {
        if (! $brandId) {
            return [];
        }

        return WasteSection::query()
            ->where('brand_id', $brandId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('name', 'name')
            ->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('waste::waste.reporter_section'))->schema([
                TextEntry::make('uid')->label(__('waste::waste.fields.uid'))->copyable(),
                TextEntry::make('status')->label(__('waste::waste.fields.status'))->badge(),
                TextEntry::make('brand.name')->label('Brand'),
                TextEntry::make('outlet.name')->label(__('waste::waste.fields.outlet')),
                TextEntry::make('event_date')->label(__('waste::waste.fields.event_date'))->date(),
                TextEntry::make('reporter_name')->label(__('waste::waste.fields.reporter_name'))
                    ->formatStateUsing(fn (WasteReport $record): string => $record->reporterNameForDisplay()),
                TextEntry::make('reporter_phone')->label(__('waste::waste.fields.reporter_phone'))
                    ->visible(fn (WasteReport $record): bool => ! $record->hasQaSourceMarker() && $record->reporter_phone !== '0000000000'),
                TextEntry::make('reporter_email')->label(__('waste::waste.fields.reporter_email'))
                    ->visible(fn (WasteReport $record): bool => ! $record->hasQaSourceMarker()),
            ])->columns(2),
            Section::make(__('waste::waste.event_summary'))->schema([
                RepeatableEntry::make('latestVersion.events')->schema([
                    TextEntry::make('section')->label(__('waste::waste.fields.section'))->placeholder('—'),
                    TextEntry::make('category_name')->label(__('waste::waste.fields.category')),
                    TextEntry::make('reason')->label(__('waste::waste.fields.reason')),
                    TextEntry::make('pip_item_name')->label(__('waste::waste.fields.pip_item'))
                        ->visible(fn (mixed $state): bool => filled($state)),
                    TextEntry::make('pip_quantity')->label(__('waste::waste.fields.pip_quantity'))
                        ->visible(fn (mixed $state): bool => filled($state)),
                    RepeatableEntry::make('lines')->label(__('waste::waste.admin.items'))->schema([
                        TextEntry::make('item_name')->label('Barang'),
                        TextEntry::make('item_code')->label('Kode'),
                        TextEntry::make('quantity')->label(__('waste::waste.fields.quantity')),
                        TextEntry::make('unit')->label('Satuan'),
                        TextEntry::make('sm_checked')->label('SM')->placeholder('Belum ditandai')
                            ->visible(fn (WasteEventLine $record): bool => strtoupper((string) $record->event->version->report->brand->code) === 'JCHICKEN')
                            ->formatStateUsing(fn (?bool $state): string => $state === null ? 'Belum ditandai' : ($state ? 'TRUE' : 'FALSE')),
                        TextEntry::make('audit_checked')->label('AUDIT')->placeholder('Belum ditandai')
                            ->visible(fn (WasteEventLine $record): bool => in_array(strtoupper((string) $record->event->version->report->brand->code), ['JCHICKEN', 'LUUCA'], true))
                            ->formatStateUsing(fn (?bool $state): string => $state === null ? 'Belum ditandai' : ($state ? 'TRUE' : 'FALSE')),
                    ])->columns(6)->columnSpanFull(),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (WasteReport $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('brand.name')->label('Brand')->sortable()->searchable(),
                TextColumn::make('outlet.name')->label(__('waste::waste.fields.outlet'))->sortable()->searchable(),
                TextColumn::make('event_date')->label(__('waste::waste.fields.event_date'))->date()->sortable(),
                TextColumn::make('reporter_name')->label(__('waste::waste.fields.reporter_name'))
                    ->formatStateUsing(fn (WasteReport $record): string => $record->reporterNameForDisplay())
                    ->searchable(),
                TextColumn::make('status')->label('Status')->badge()->sortable()->formatStateUsing(function (mixed $state): string {
                    $value = $state instanceof WasteReportStatus ? $state->value : (string) $state;

                    return __('waste::waste.status.'.$value);
                })->color(fn (mixed $state): string => match ($state instanceof WasteReportStatus ? $state : WasteReportStatus::tryFrom((string) $state)) {
                    WasteReportStatus::Approved => 'success',
                    WasteReportStatus::Rejected => 'danger',
                    default                     => 'warning',
                }),
            ])
            ->filters([
                Filter::make('month')
                    ->label('Bulan laporan')
                    ->schema([
                        Select::make('month')
                            ->label('Bulan')
                            ->options(fn (): array => collect(range(1, 12))
                                ->mapWithKeys(fn (int $month): array => [$month => Carbon::createFromDate(2024, $month, 1)->locale('id')->translatedFormat('F')])
                                ->all()),
                        Select::make('year')
                            ->label('Tahun')
                            ->options(fn (): array => collect(range(now('Asia/Jakarta')->year + 1, 2000))
                                ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
                                ->all())
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        $month = (int) ($data['month'] ?? 0);
                        $year = (int) ($data['year'] ?? 0);
                        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                            return $query;
                        }

                        $period = Carbon::createFromDate($year, $month, 1, 'Asia/Jakarta');

                        return $query
                            ->whereDate('event_date', '>=', $period->copy()->startOfMonth()->toDateString())
                            ->whereDate('event_date', '<=', $period->copy()->endOfMonth()->toDateString());
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $month = (int) ($data['month'] ?? 0);
                        $year = (int) ($data['year'] ?? 0);
                        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
                            return null;
                        }

                        return 'Periode: '.Carbon::createFromDate($year, $month, 1)->locale('id')->translatedFormat('F Y');
                    }),
                SelectFilter::make('status')->options([
                    'pending'  => __('waste::waste.status.pending'),
                    'rejected' => __('waste::waste.status.rejected'),
                    'approved' => __('waste::waste.status.approved'),
                ]),
                SelectFilter::make('brand_id')->relationship(
                    'brand',
                    'name',
                    fn (Builder $query): Builder => app(WasteAccessService::class)->scopeBrands($query, filament()->auth()->user()),
                ),
                SelectFilter::make('outlet_id')->relationship(
                    'outlet',
                    'name',
                    fn (Builder $query): Builder => app(WasteAccessService::class)->scopeOutlets($query, filament()->auth()->user()),
                ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListWasteReports::route('/'),
            'create' => CreateWasteReport::route('/create'),
            'view'   => ViewWasteReport::route('/{record}'),
            'edit'   => EditWasteReport::route('/{record}/edit'),
        ];
    }
}
