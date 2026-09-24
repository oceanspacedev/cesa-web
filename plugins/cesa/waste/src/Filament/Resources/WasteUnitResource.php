<?php

namespace Cesa\Waste\Filament\Resources;

use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteUnitResource\Pages\ManageWasteUnits;
use Cesa\Waste\Models\WasteUnit;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Kode')
                ->helperText('Kode satuan di pilihan barang, contoh GR atau PCS.')
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?WasteUnit $record): bool => $record?->exists ?? false),
            TextInput::make('name')
                ->label('Nama')
                ->helperText('Nama satuan untuk admin, contoh Gram atau Pieces.')
                ->required()
                ->maxLength(100),
            Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Nonaktif menyembunyikan satuan dari pilihan barang baru; barang lama tetap memakai satuan ini.')
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('Kode')->searchable()->sortable(),
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('is_active')->label('Aktif')->badge(),
        ])->recordActions([
            EditAction::make()->slideOver(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteUnits::route('/')];
    }
}
