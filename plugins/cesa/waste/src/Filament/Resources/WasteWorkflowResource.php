<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteWorkflowResource\Pages\ManageWasteWorkflows;
use Cesa\Waste\Filament\Support\WasteActiveColumn;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Support\WasteConfigurationKeys;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
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

class WasteWorkflowResource extends Resource
{
    protected static ?string $model = WasteWorkflow::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 80;

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
        return app(WasteAccessService::class)->scopeWorkflows(parent::getEloquentQuery(), filament()->auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('waste::waste.admin.workflows');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedCheckBadge;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('brand_id')->label('Brand')->options(fn (): array => app(WasteAccessService::class)->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())->pluck('name', 'id')->all())->required()->live()->searchable()
                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set): void {
                    $set('outlet_id', null);
                    $previous = WasteConfigurationKeys::approvalName(is_numeric($old) ? (int) $old : null, null);
                    $next = WasteConfigurationKeys::approvalName(is_numeric($state) ? (int) $state : null, null);
                    if (blank($get('name')) || (string) $get('name') === $previous) {
                        $set('name', $next);
                    }
                })
                ->columnSpanFull(),
            Select::make('outlet_id')->label('Outlet')->helperText('Kosongkan agar berlaku untuk semua outlet dalam brand ini.')->options(fn (Get $get): array => app(WasteAccessService::class)
                ->scopeOutlets(WasteOutlet::query()->when($get('brand_id'), fn (Builder $query, mixed $brandId): Builder => $query->where('brand_id', (int) $brandId))->orderBy('name'), filament()->auth()->user())
                ->pluck('name', 'id')
                ->all())->nullable()->searchable()->live()->columnSpanFull()
                ->afterStateUpdated(function (mixed $state, mixed $old, Get $get, Set $set): void {
                    $brandId = is_numeric($get('brand_id')) ? (int) $get('brand_id') : null;
                    $previous = WasteConfigurationKeys::approvalName($brandId, is_numeric($old) ? (int) $old : null);
                    $next = WasteConfigurationKeys::approvalName($brandId, is_numeric($state) ? (int) $state : null);
                    if (blank($get('name')) || (string) $get('name') === $previous) {
                        $set('name', $next);
                    }
                })
                ->columnSpanFull(),
            TextInput::make('name')->label('Nama')->helperText('Terisi otomatis dari brand atau outlet, contoh Persetujuan Ciledug.')->required()->columnSpanFull(),
            Repeater::make('steps')->label('Urutan persetujuan')->helperText('Orang pertama di atas menerima pemberitahuan lebih dulu. Setiap langkah memerlukan nama serta WhatsApp atau email.')->schema([
                TextInput::make('label')->label('Jabatan')->helperText('Contoh Supervisor.')->required(),
                TextInput::make('name')->label('Nama pemeriksa')->required(),
                TextInput::make('phone')->label('WhatsApp')->tel(),
                TextInput::make('email')->label('Email')->email(),
            ])->minItems(1)->required()->columnSpanFull(),
            Toggle::make('is_active')->label('Aktif')->helperText('Jika nonaktif, laporan memakai alur persetujuan lain atau menunggu tinjauan MIS.')->default(true)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('brand.name')->label('Brand')->sortable(), TextColumn::make('outlet.name')->label('Outlet')->placeholder('Semua outlet'), TextColumn::make('name')->label('Nama')->searchable(), TextColumn::make('steps')->formatStateUsing(fn ($state): string => (string) count($state ?? []))->label('Langkah'), WasteActiveColumn::make()])->recordActions([EditAction::make()->slideOver()->modalWidth('md'), DeleteAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWasteWorkflows::route('/')];
    }
}
