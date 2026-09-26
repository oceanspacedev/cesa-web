<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteUnitResource\Pages\ManageWasteUnits;
use Cesa\Waste\Filament\Support\DerivedWasteFields;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteUnit;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Webkul\PluginManager\Package;

class WasteUnitResource extends Resource
{
    protected static ?string $model = WasteUnit::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 20;

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function getNavigationGroup(): string
    {
        return __('waste::waste.admin.groups.global');
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.units');
    }

    public static function getModelLabel(): string
    {
        return __('waste::waste.admin.unit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('waste::waste.admin.units');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedScale;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DerivedWasteFields::name(codeMax: 32, normalizeUnit: true)
                ->helperText('Nama satuan untuk admin, contoh Gram atau Pieces. Kode mengikuti nama ini.')
                ->maxLength(100)
                ->columnSpanFull(),
            DerivedWasteFields::code(32)
                ->helperText('Terisi otomatis, contoh GRAM menjadi GR. Tidak bisa diubah setelah disimpan.')
                ->unique(ignoreRecord: true)
                ->disabled(fn (?WasteUnit $record): bool => $record?->exists ?? false)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Nonaktif menyembunyikan satuan dari pilihan barang baru; barang lama tetap memakai satuan ini.')
                ->default(true)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Kode')->searchable()->sortable(),
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            WasteActiveColumn::make(),
        ])->recordActions([
            EditAction::make()->slideOver()->modalWidth('md'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteUnits::route('/')];
    }
}
