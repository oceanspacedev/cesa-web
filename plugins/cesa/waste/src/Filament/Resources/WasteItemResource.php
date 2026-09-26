<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Enums\WasteAlternateUnitCandidateStatus;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Filament\Support\DerivedWasteFields;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteAccessService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Webkul\PluginManager\Package;

class WasteItemResource extends Resource
{
    protected static ?string $model = WasteItem::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 60;

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function getNavigationGroup(): string
    {
        return __('waste::waste.admin.groups.brand');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(WasteAccessService::class)->scopeItems(parent::getEloquentQuery()->with('alternateUnits'), filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.items');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedCube;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Brand')->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())->required()->searchable()->columnSpanFull(),
            DerivedWasteFields::name(codeMax: 100)->helperText('Kode barang mengikuti nama ini. Isi kode sendiri jika memakai kode stok.')->columnSpanFull(),
            DerivedWasteFields::code(100)->helperText('Terisi otomatis dari nama. Ganti dengan kode stok jika sudah ada.')->columnSpanFull(),
            Select::make('unit')
                ->label('Satuan')
                ->helperText('Pilih dari Master Satuan di Pengaturan Waste.')
                ->options(function (?WasteItem $record): array {
                    $units = WasteUnit::query()
                        ->where('is_active', true)
                        ->when($record?->unit, fn (Builder $query, string $unit): Builder => $query->orWhere('code', $unit))
                        ->orderBy('code')
                        ->get();

                    $options = $units->mapWithKeys(fn (WasteUnit $unit): array => [
                        $unit->code => $unit->name === $unit->code ? $unit->code : "{$unit->code} — {$unit->name}",
                    ])->all();

                    if ($record?->unit && ! array_key_exists($record->unit, $options)) {
                        $options[$record->unit] = $record->unit;
                    }

                    return $options;
                })
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->columnSpanFull()
                ->afterStateUpdated(function (Set $set): void {
                    $set('alternateUnits', []);
                }),
            Select::make('alternateUnits')
                ->label('Satuan alternatif')
                ->helperText('Pilih satuan lain yang boleh digunakan untuk barang ini. Usulan satuan baru ditinjau di menu Kandidat satuan.')
                ->multiple()
                ->relationship(
                    name: 'alternateUnits',
                    titleAttribute: 'code',
                    modifyQueryUsing: fn (Builder $query, Get $get, ?WasteItem $record): Builder => $query
                        ->where('is_active', true)
                        ->when($get('unit'), fn (Builder $query, string $unit): Builder => $query->where('code', '!=', $unit))
                        ->whereNotIn('waste_units.id', static::blockedAlternateUnitIds($record)),
                )
                ->searchable()
                ->preload()
                ->nestedRecursiveRule(function (Get $get, ?WasteItem $record) {
                    $rule = Rule::exists('waste_units', 'id')
                        ->where('is_active', true)
                        ->where('code', '!=', (string) $get('unit'));

                    $blockedUnitIds = static::blockedAlternateUnitIds($record);

                    return $blockedUnitIds === [] ? $rule : $rule->whereNotIn('id', $blockedUnitIds);
                })
                ->columnSpanFull(),
            TextInput::make('item_type')->label('Jenis')->helperText('Khusus Momoyo, gunakan PIP hanya untuk barang PIP.')->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif tidak bisa dipilih di laporan baru.')->default(true)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('brand.name')->label('Brand')->sortable(),
            TextColumn::make('code')->searchable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('item_type')->label('Jenis')->sortable()->placeholder('—'),
            TextColumn::make('unit')->label('Satuan utama')->sortable(),
            TextColumn::make('alternate_unit_codes')
                ->label('Satuan alternatif')
                ->state(fn (WasteItem $record): string => $record->alternateUnits
                    ->map(fn (WasteUnit $unit): string => $unit->is_active ? $unit->code : "{$unit->code} (nonaktif)")
                    ->implode(', '))
                ->placeholder('—'),
            TextColumn::make('notes')
                ->label('Catatan')
                ->limit(50)
                ->tooltip(fn (WasteItem $record): ?string => $record->notes)
                ->placeholder('—'),
            WasteActiveColumn::make(),
        ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktif'),
                SelectFilter::make('brand')->relationship(
                    'brand',
                    'name',
                    fn (Builder $query): Builder => app(WasteAccessService::class)->scopeBrands($query, filament()->auth()->user()),
                ),
                SelectFilter::make('item_type')
                    ->label('Jenis')
                    ->options(fn (): array => WasteItem::query()
                        ->whereNotNull('item_type')
                        ->where('item_type', '!=', '')
                        ->distinct()
                        ->orderBy('item_type')
                        ->pluck('item_type', 'item_type')
                        ->prepend('(Tanpa jenis)', '')
                        ->all())
                    ->modifyQueryUsing(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null) {
                            return $query;
                        }

                        return $value === ''
                            ? $query->where(fn (Builder $query): Builder => $query->whereNull('item_type')->orWhere('item_type', ''))
                            : $query->where('item_type', $value);
                    }),
            ])
            ->recordActions([EditAction::make()->slideOver()->modalWidth('md'), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteItems::route('/')];
    }

    /**
     * @return array<int, int>
     */
    protected static function blockedAlternateUnitIds(?WasteItem $record): array
    {
        if (! $record?->exists) {
            return [];
        }

        return $record->alternateUnitCandidates()
            ->whereIn('status', [
                WasteAlternateUnitCandidateStatus::Pending->value,
                WasteAlternateUnitCandidateStatus::Rejected->value,
            ])
            ->pluck('unit_id')
            ->all();
    }
}
