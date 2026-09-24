<?php

namespace Cesa\Waste\Filament\Resources\WasteReportResource\Pages;

use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Services\WasteAccessService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ListWasteReports extends ListRecords
{
    protected static string $resource = WasteReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('waste::waste.admin.incident')),
            Action::make('export')
                ->label('Export laporan bulanan')
                ->modalDescription('Pilih bulan laporan. Semua pengiriman yang disetujui pada bulan itu digabung per brand dan outlet sesuai template Excel.')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->schema([
                    Select::make('month')
                        ->label('Bulan')
                        ->options(fn (): array => collect(range(1, 12))
                            ->mapWithKeys(fn (int $month): array => [$month => Carbon::createFromDate(2024, $month, 1)->locale('id')->translatedFormat('F')])
                            ->all())
                        ->default(fn (): int => (int) ($this->getTableFilterState('month')['month'] ?? now('Asia/Jakarta')->month))
                        ->required(),
                    Select::make('year')
                        ->label('Tahun')
                        ->options(fn (): array => collect(range(now('Asia/Jakarta')->year + 1, 2000))
                            ->mapWithKeys(fn (int $year): array => [$year => (string) $year])
                            ->all())
                        ->default(fn (): int => (int) ($this->getTableFilterState('month')['year'] ?? now('Asia/Jakarta')->year))
                        ->searchable()
                        ->required(),
                    Select::make('brand_id')
                        ->label('Brand')
                        ->placeholder('Semua brand')
                        ->options(fn (): array => app(WasteAccessService::class)
                            ->scopeBrands(WasteBrand::query()->orderBy('name'), filament()->auth()->user())
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (): int|string|null => $this->getTableFilterState('brand_id')['value'] ?? null)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('outlet_id', null)),
                    Select::make('outlet_id')
                        ->label('Outlet')
                        ->placeholder('Semua outlet')
                        ->options(function (Get $get): array {
                            $query = WasteOutlet::query()->with('brand')->orderBy('name');
                            if (filled($get('brand_id'))) {
                                $query->where('brand_id', (int) $get('brand_id'));
                            }

                            return app(WasteAccessService::class)
                                ->scopeOutlets($query, filament()->auth()->user())
                                ->get()
                                ->mapWithKeys(fn (WasteOutlet $outlet): array => [
                                    $outlet->getKey() => $outlet->brand->name.' / '.$outlet->name,
                                ])
                                ->all();
                        })
                        ->default(fn (): int|string|null => $this->getTableFilterState('outlet_id')['value'] ?? null)
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    $period = Carbon::createFromDate((int) $data['year'], (int) $data['month'], 1, 'Asia/Jakarta');
                    $export = new WasteReportExport(
                        $period->copy()->startOfMonth()->toDateString(),
                        $period->copy()->endOfMonth()->toDateString(),
                        'approved',
                        filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
                        filled($data['outlet_id'] ?? null) ? (int) $data['outlet_id'] : null,
                        filament()->auth()->user(),
                    );

                    if (! $export->hasReports()) {
                        Notification::make()
                            ->title('Belum ada laporan disetujui untuk bulan dan outlet terpilih.')
                            ->warning()
                            ->send();

                        return null;
                    }

                    $filename = 'waste-bulanan-'.$period->format('Y-m').'-'.now()->format('Ymd-His').'.xlsx';

                    return Excel::download(
                        $export,
                        $filename,
                        ExcelFormat::XLSX,
                    );
                }),
        ];
    }
}
