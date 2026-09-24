<?php

namespace Cesa\Waste\Filament\Resources;

use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteOutletResource\Pages\ManageWasteOutlets;
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
use Filament\Schemas\Schema;
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

    public static function getNavigationIcon(): ?string
    {
        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Brand')->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())->required()->searchable(),
            TextInput::make('name')->label('Nama')->helperText('Nama outlet di kartu pilihan form.')->required(),
            TextInput::make('code')->label('Kode')->helperText('Muncul di judul form, contoh CILEDUG.')->required(),
            TextInput::make('slug')->label('Tautan')->helperText('Alamat form: /waste/kode-brand/tautan-ini.')->required(),
            TextInput::make('timezone')->label('Zona waktu')->helperText('Menentukan tanggal bawaan di form.')->default('Asia/Jakarta')->required(),
            Toggle::make('is_active')->label('Aktif')->helperText('Nonaktif menutup tautan form outlet ini.')->default(true),
            Select::make('users')->label('Pengelola outlet')->helperText('Hanya melihat laporan outlet ini, tanpa mengubah data brand.')->relationship('users', 'name')->multiple()->preload()->searchable()->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('brand.name')->label('Brand')->sortable(), TextColumn::make('name')->searchable()->sortable(), TextColumn::make('code')->searchable(), TextColumn::make('is_active')->badge()])->recordActions([EditAction::make()->slideOver(), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteOutlets::route('/')];
    }
}
