<?php

namespace Cesa\Waste\Filament\Resources;

use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteCategoryResource\Pages\ManageWasteCategories;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Services\WasteAccessService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Brand')->helperText('Kategori hanya muncul di form brand ini.')->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())->nullable()->required(fn (): bool => ! (filament()->auth()->user()?->can('view_any_waste_waste::report') ?? false))->searchable(),
            TextInput::make('name')->label('Nama')->helperText('Teks di dropdown Kategori adjustment.')->required(),
            TextInput::make('code')->label('Kode')->helperText('Kode unik di dalam brand.')->required(),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif menghilangkannya dari form.')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('brand.name')->label('Brand')->placeholder('Global'), TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()->sortable(), TextColumn::make('is_active')->badge()])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteCategories::route('/')];
    }
}
