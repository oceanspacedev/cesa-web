<?php

namespace Cesa\Waste\Filament\Resources;

use BackedEnum;
use Cesa\Waste\Enums\WasteAlternateUnitCandidateStatus;
use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Resources\WasteItemUnitCandidateResource\Pages\ManageWasteItemUnitCandidates;
use Cesa\Waste\Models\WasteItemUnitCandidate;
use Cesa\Waste\Services\WasteAccessService;
use Cesa\Waste\Services\WasteAlternateUnitCandidateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Webkul\PluginManager\Package;

class WasteItemUnitCandidateResource extends Resource
{
    protected static ?string $model = WasteItemUnitCandidate::class;

    protected static ?string $cluster = Configurations::class;

    protected static ?int $navigationSort = 70;

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
        return parent::getEloquentQuery()
            ->with(['item.brand', 'unit'])
            ->whereHas('item', fn (Builder $query): Builder => app(WasteAccessService::class)
                ->scopeItems($query, filament()->auth()->user()));
    }

    public static function getNavigationLabel(): string
    {
        return 'Kandidat satuan';
    }

    public static function getModelLabel(): string
    {
        return 'Kandidat satuan alternatif';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kandidat satuan alternatif';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedQueueList;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.brand.name')->label('Brand')->sortable(),
                TextColumn::make('item.code')->label('Kode barang')->searchable(),
                TextColumn::make('item.name')->label('Nama barang')->searchable()->wrap(),
                TextColumn::make('item.unit')->label('Satuan utama'),
                TextColumn::make('unit.code')->label('Usulan satuan')->badge(),
                TextColumn::make('source_row_count')->label('Jumlah temuan')->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (WasteItemUnitCandidate $record): string => match ($record->status) {
                        WasteAlternateUnitCandidateStatus::Pending  => 'Menunggu',
                        WasteAlternateUnitCandidateStatus::Approved => 'Disetujui',
                        WasteAlternateUnitCandidateStatus::Rejected => 'Ditolak',
                    })
                    ->color(fn (WasteItemUnitCandidate $record): string => match ($record->status) {
                        WasteAlternateUnitCandidateStatus::Pending  => 'warning',
                        WasteAlternateUnitCandidateStatus::Approved => 'success',
                        WasteAlternateUnitCandidateStatus::Rejected => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    WasteAlternateUnitCandidateStatus::Pending->value  => 'Menunggu',
                    WasteAlternateUnitCandidateStatus::Approved->value => 'Disetujui',
                    WasteAlternateUnitCandidateStatus::Rejected->value => 'Ditolak',
                ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')
                    ->color('success')
                    ->visible(fn (WasteItemUnitCandidate $record): bool => $record->status === WasteAlternateUnitCandidateStatus::Pending
                        && Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Satuan ini akan tersedia untuk barang tersebut di form publik. Pastikan jumlah diisi sesuai satuan yang dipilih.')
                    ->action(function (WasteItemUnitCandidate $record): void {
                        app(WasteAlternateUnitCandidateService::class)->approve($record, filament()->auth()->user());
                        Notification::make()->title('Satuan alternatif disetujui.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Tolak')
                    ->color('danger')
                    ->visible(fn (WasteItemUnitCandidate $record): bool => $record->status === WasteAlternateUnitCandidateStatus::Pending
                        && Gate::allows('update', $record))
                    ->schema([
                        Textarea::make('review_note')->label('Alasan penolakan')->required()->maxLength(1000),
                    ])
                    ->action(function (WasteItemUnitCandidate $record, array $data): void {
                        app(WasteAlternateUnitCandidateService::class)->reject($record, filament()->auth()->user(), $data['review_note']);
                        Notification::make()->title('Kandidat satuan ditolak.')->success()->send();
                    }),
                Action::make('reopen')
                    ->label('Buka ulang')
                    ->color('gray')
                    ->visible(fn (WasteItemUnitCandidate $record): bool => $record->status !== WasteAlternateUnitCandidateStatus::Pending
                        && Gate::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Keputusan lama dibatalkan. Satuan ini tidak tersedia di form publik sampai disetujui lagi.')
                    ->action(function (WasteItemUnitCandidate $record): void {
                        app(WasteAlternateUnitCandidateService::class)->reopen($record, filament()->auth()->user());
                        Notification::make()->title('Kandidat kembali menunggu tinjauan.')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWasteItemUnitCandidates::route('/'),
        ];
    }
}
