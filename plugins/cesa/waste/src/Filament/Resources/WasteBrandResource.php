<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteBrandResource\Pages\ManageWasteBrands;
use Cesa\Waste\Filament\Support\DerivedWasteFields;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Services\WasteAccessService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\PluginManager\Package;

class WasteBrandResource extends Resource
{
    protected static ?string $model = WasteBrand::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        return Package::isPluginInstalled('waste');
    }

    public static function getNavigationGroup(): string
    {
        return __('waste::waste.admin.groups.global');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(WasteAccessService::class)->scopeBrands(parent::getEloquentQuery(), filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.brands');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBuildingStorefront;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DerivedWasteFields::name()->helperText('Nama yang tampil untuk admin.')->columnSpanFull(),
            DerivedWasteFields::code(50)->helperText('Terisi otomatis dari nama, contoh MOMOYO. Ubah hanya jika perlu, dan jangan diubah setelah outlet dipakai.')->columnSpanFull(),
            Select::make('users')->label('Pengelola brand')->helperText('Bisa mengelola data form dan laporan brand ini.')->relationship('users', 'name')->multiple()->preload()->searchable()->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif menyembunyikan brand dari form publik.')->default(true)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('outlets_count')->counts('outlets')->label('Outlets'),
            WasteActiveColumn::make(),
        ])->recordActions([EditAction::make()->slideOver()->modalWidth('md'), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteBrands::route('/')];
    }
}
