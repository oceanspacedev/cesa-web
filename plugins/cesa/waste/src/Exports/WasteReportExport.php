<?php

namespace Cesa\Waste\Exports;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class WasteReportExport implements WithMultipleSheets
{
    protected Collection $reports;

    public function __construct(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $status = 'approved',
        ?int $brandId = null,
        ?int $outletId = null,
        ?object $user = null,
    ) {
        $this->validateSelection($dateFrom, $dateTo, $status, $brandId, $outletId, $user);

        $query = WasteReport::query()
            ->with([
                'brand',
                'outlet',
                'latestVersion.events.lines.item',
            ])
            ->when($dateFrom, fn (Builder $query): Builder => $query->whereDate('event_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query): Builder => $query->whereDate('event_date', '<=', $dateTo))
            ->when($status && $status !== 'all', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($brandId !== null, fn (Builder $query): Builder => $query->where('brand_id', $brandId))
            ->when($outletId !== null, fn (Builder $query): Builder => $query->where('outlet_id', $outletId))
            ->orderBy('event_date')
            ->orderBy('id');

        $this->reports = app(WasteAccessService::class)
            ->scopeReports($query, $user)
            ->get();
    }

    protected function validateSelection(
        ?string $dateFrom,
        ?string $dateTo,
        ?string $status,
        ?int $brandId,
        ?int $outletId,
        ?object $user,
    ): void {
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            throw ValidationException::withMessages(['date_to' => 'Sampai tanggal harus sama atau setelah dari tanggal.']);
        }

        $validStatuses = ['all', ...array_map(fn (WasteReportStatus $status): string => $status->value, WasteReportStatus::cases())];
        if ($status !== null && ! in_array($status, $validStatuses, true)) {
            throw ValidationException::withMessages(['status' => 'Status export tidak valid.']);
        }

        $access = app(WasteAccessService::class);
        if ($brandId !== null && ! $access->scopeBrands(WasteBrand::query(), $user)->whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Brand tidak tersedia untuk akun ini.']);
        }

        if ($outletId !== null) {
            $outlet = $access->scopeOutlets(WasteOutlet::query(), $user)->whereKey($outletId)->first();
            if (! $outlet || ($brandId !== null && (int) $outlet->brand_id !== $brandId)) {
                throw ValidationException::withMessages(['outlet_id' => 'Outlet harus sesuai dengan brand dan akses akun.']);
            }
        }
    }

    public function sheets(): array
    {
        return $this->templateSheets();
    }

    public function hasReports(): bool
    {
        return $this->reports->isNotEmpty();
    }

    /**
     * @return array<int, WasteTemplateSheet|WasteMasterSheet>
     */
    protected function templateSheets(): array
    {
        $groups = $this->reports
            ->filter(fn (WasteReport $report): bool => in_array(strtoupper($report->brand->code), ['JCHICKEN', 'LUUCA', 'MOMOYO'], true))
            ->groupBy(fn (WasteReport $report): string => implode('|', [
                $report->brand_id,
                $report->outlet_id,
                $report->event_date->format('Y-m'),
                $report->status?->value,
            ]))
            ->sortKeys();

        $usedTitles = [];
        $sheets = [];
        $masterBrands = [];
        foreach ($groups as $reports) {
            $first = $reports->first();
            $month = strtoupper($first->event_date->locale('id')->translatedFormat('F'));
            $brandCode = strtoupper($first->brand->code);
            $title = $groups->count() === 1
                ? $month.($brandCode === 'MOMOYO' ? '' : ' '.$first->event_date->format('y'))
                : implode(' ', array_filter([
                    $brandCode,
                    strtoupper($first->outlet->code),
                    strtoupper($first->event_date->format('M y')),
                    $first->status === WasteReportStatus::Approved ? null : strtoupper($first->status?->value ?? ''),
                ]));

            $sheets[] = new WasteTemplateSheet($reports, $this->uniqueSheetTitle($title, $usedTitles));

            if (! in_array($brandCode, $masterBrands, true)) {
                $masterBrands[] = $brandCode;
                $sheets[] = new WasteMasterSheet($first->brand, $this->uniqueSheetTitle('Master data '.$brandCode, $usedTitles));
            }
        }

        return $sheets;
    }

    /**
     * @param  array<int, string>  $usedTitles
     */
    protected function uniqueSheetTitle(string $candidate, array &$usedTitles): string
    {
        $base = mb_substr(trim(str_replace(['\\', '/', '*', '[', ']', ':', '?'], ' ', $candidate)), 0, 31);
        $title = $base;
        $suffix = 2;

        while (in_array(mb_strtolower($title), $usedTitles, true)) {
            $suffixText = ' '.$suffix++;
            $title = mb_substr($base, 0, 31 - mb_strlen($suffixText)).$suffixText;
        }

        $usedTitles[] = mb_strtolower($title);

        return $title;
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function detailRows(): Collection
    {
        return $this->reports->flatMap(function (WasteReport $report): Collection {
            $version = $report->latestVersion;
            if (! $version) {
                return collect();
            }

            return $version->events->sortBy('sequence')->flatMap(function ($event) use ($report, $version): Collection {
                return $event->lines->sortBy('id')->values()->map(fn ($line, int $lineIndex): array => [
                    $report->uid,
                    $version->version_number,
                    optional($report->event_date)->format('Y-m-d'),
                    $report->brand->name,
                    $report->outlet->name,
                    $report->status?->value,
                    $event->sequence + 1,
                    $event->section,
                    $event->category_name,
                    $event->reason,
                    $event->pip_item_name,
                    $event->pip_quantity === null || $lineIndex !== 0 ? null : (float) $event->pip_quantity,
                    $event->pip_unit,
                    $line->item_code,
                    $line->item_name,
                    $line->unit,
                    (float) $line->quantity,
                    $line->line_role,
                    $report->reporter_name,
                    $line->sm_checked,
                    $line->audit_checked,
                ]);
            });
        })->values();
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function summaryRows(): Collection
    {
        return $this->reports
            ->filter(fn (WasteReport $report): bool => $report->status === WasteReportStatus::Approved)
            ->flatMap(fn (WasteReport $report): Collection => $report->latestVersion?->events
                ->flatMap(fn ($event): Collection => $event->lines->map(fn ($line): array => [
                    'key' => json_encode([
                        $report->brand_id,
                        $report->outlet_id,
                        $event->category_id ?? $event->category_name,
                        $line->item_code,
                        $line->unit,
                    ]),
                    'brand'    => $report->brand->name,
                    'outlet'   => $report->outlet->name,
                    'category' => $event->category_name,
                    'code'     => $line->item_code,
                    'name'     => $line->item_name,
                    'unit'     => $line->unit,
                    'quantity' => (float) $line->quantity,
                    'date'     => $report->event_date->format('Y-m-d'),
                ])) ?? collect())
            ->groupBy('key')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    $first['brand'],
                    $first['outlet'],
                    $first['category'],
                    $first['code'],
                    $first['name'],
                    $first['unit'],
                    round($rows->sum('quantity'), 4),
                    $rows->pluck('date')->unique()->sort()->implode(', '),
                ];
            })
            ->sortBy(fn (array $row): string => implode('|', [$row[0], $row[1], $row[3]]))
            ->values();
    }
}
