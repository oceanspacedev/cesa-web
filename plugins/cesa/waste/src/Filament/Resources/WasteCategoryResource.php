<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteCategoryResource\Pages\ManageWasteCategories;
use Cesa\Waste\Filament\Support\DerivedWasteFields;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
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

class WasteCategoryResource extends Resource
{
    protected static ?string $model = WasteCategory::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 50;

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
        return app(WasteAccessService::class)->scopeCategories(parent::getEloquentQuery(), filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.categories');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedTag;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Brand')->helperText('Kategori hanya muncul di form brand ini.')->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())->nullable()->required(fn (): bool => ! (filament()->auth()->user()?->can('view_any_waste_waste::report') ?? false))->searchable(),
            DerivedWasteFields::name(codeMax: 100)->helperText('Teks di dropdown Kategori adjustment. Kode mengikuti nama ini.'),
            DerivedWasteFields::code(100),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif menghilangkannya dari form.')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('brand.name')->label('Brand')->placeholder('Global'), TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()->sortable(), WasteActiveColumn::make()])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteCategories::route('/')];
    }
}
