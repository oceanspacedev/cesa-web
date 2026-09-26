<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteOutletResource\Pages\ManageWasteOutlets;
use Cesa\Waste\Filament\Support\DerivedWasteFields;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Webkul\PluginManager\Package;

class WasteOutletResource extends Resource
{
    protected static ?string $model = WasteOutlet::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 30;

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
        return app(WasteAccessService::class)->scopeOutlets(parent::getEloquentQuery(), filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.outlets');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedMapPin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')
                ->label('Brand')
                ->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())
                ->required()
                ->searchable()
                ->live()
                ->columnSpanFull()
                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set): void {
                    DerivedWasteFields::outletSlugFromBrand($state, $old, $get, $set);
                }),
            DerivedWasteFields::name(slug: true)->helperText('Nama outlet di kartu pilihan form. Kode dan tautan mengikuti nama ini.')->columnSpanFull(),
            DerivedWasteFields::code(50)->helperText('Terisi otomatis dari nama, contoh CILEDUG.')->columnSpanFull(),
            TextInput::make('slug')->label('Tautan')->helperText('Terisi otomatis, contoh momoyo-ciledug. Alamat form: /waste/tautan-ini.')->maxLength(100)->columnSpanFull(),
            TextInput::make('timezone')->label('Zona waktu')->helperText('Menentukan tanggal bawaan di form.')->default('Asia/Jakarta')->required()->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif menutup tautan form outlet ini.')->default(true)->columnSpanFull(),
            Select::make('users')->label('Pengelola outlet')->helperText('Hanya melihat laporan outlet ini, tanpa mengubah data brand.')->relationship('users', 'name')->multiple()->preload()->searchable()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('brand.name')->label('Brand')->sortable(), TextColumn::make('name')->searchable()->sortable(), TextColumn::make('code')->searchable(), WasteActiveColumn::make()])->recordActions([EditAction::make()->slideOver()->modalWidth('md'), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteOutlets::route('/')];
    }
}
