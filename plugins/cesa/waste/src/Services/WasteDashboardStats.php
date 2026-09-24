<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Models\WasteReport;
use Illuminate\Support\Carbon;

class WasteDashboardStats
{
    /**
     * @return array<string, string>
     */
    public function yearOptions(): array
    {
        $currentYear = now('Asia/Jakarta')->year;

        return collect(range($currentYear, $currentYear - 4))
            ->mapWithKeys(fn (int $year): array => [(string) $year => (string) $year])
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{month: string, year: string}
     */
    public function normalizeFilters(?array $filters): array
    {
        $now = now('Asia/Jakarta');
        $month = $this->integerInRange($filters['month'] ?? null, 1, 12);
        $years = array_map(intval(...), array_keys($this->yearOptions()));
        $year = $this->integerInRange($filters['year'] ?? null, min($years), max($years));

        return [
            'month' => (string) ($month === false ? $now->month : $month),
            'year'  => (string) ($year === false ? $now->year : $year),
        ];
    }

    /**
     * @return array{Carbon, Carbon}
     */
    public function period(mixed $month, mixed $year): array
    {
        $filters = $this->normalizeFilters([
            'month' => $month,
            'year'  => $year,
        ]);
        $from = Carbon::create((int) $filters['year'], (int) $filters['month'], 1, 0, 0, 0, 'Asia/Jakarta');

        return [$from, $from->copy()->endOfMonth()];
    }

    protected function integerInRange(mixed $value, int $min, int $max): int|false
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return false;
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            return false;
        }

        return $value;
    }

    public function reportUrl(mixed $month, mixed $year, ?string $status = null): string
    {
        [$from] = $this->period($month, $year);
        $parameters = [
            'tableFilters' => [
                'month' => ['month' => $from->month, 'year' => $from->year],
            ],
        ];

        if ($status !== null) {
            $parameters['tableFilters']['status'] = ['value' => $status];
        }

        return WasteReportResource::getUrl('index', $parameters);
    }

    /**
     * @return array<string, mixed>
     */
    public function for(mixed $month, mixed $year, ?object $user): array
    {
        [$from, $to] = $this->period($month, $year);

        $reports = app(WasteAccessService::class)->scopeReports(
            WasteReport::query()->with(['brand', 'outlet'])->whereDate('event_date', '>=', $from)->whereDate('event_date', '<=', $to),
            $user,
        )->get();
        $approvedExport = new WasteReportExport($from->toDateString(), $to->toDateString(), WasteReportStatus::Approved->value, null, null, $user);
        $reportsByDate = $reports->groupBy(fn (WasteReport $report): string => $report->event_date->toDateString());
        $daily = [];

        for ($day = 1; $day <= $from->daysInMonth; $day++) {
            $date = $from->copy()->day($day)->toDateString();
            $dayReports = $reportsByDate->get($date, collect());
            $daily[] = [
                'date'     => $date,
                'day'      => $day,
                'total'    => $dayReports->count(),
                'pending'  => $dayReports->filter(fn (WasteReport $report): bool => $report->status === WasteReportStatus::Pending)->count(),
                'approved' => $dayReports->filter(fn (WasteReport $report): bool => $report->status === WasteReportStatus::Approved)->count(),
                'rejected' => $dayReports->filter(fn (WasteReport $report): bool => $report->status === WasteReportStatus::Rejected)->count(),
            ];
        }

        return [
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'period_label' => $from->locale('id')->translatedFormat('F Y'),
            'total'        => $reports->count(),
            'statuses'     => collect(WasteReportStatus::cases())->mapWithKeys(fn (WasteReportStatus $status): array => [
                $status->value => $reports->filter(fn (WasteReport $report): bool => $report->status === $status)->count(),
            ])->all(),
            'approval_queue' => $reports->filter(fn (WasteReport $report): bool => $report->status === WasteReportStatus::Pending)->count(),
            'by_brand'       => $reports->groupBy(fn (WasteReport $report): string => $report->brand->name)->map->count()->sortDesc()->all(),
            'by_outlet'      => $reports->groupBy(fn (WasteReport $report): string => $report->brand->name.' / '.$report->outlet->name)->map->count()->sortDesc()->all(),
            'daily'          => $daily,
            'summary'        => $approvedExport->summaryRows()->take(10)->all(),
        ];
    }
}
