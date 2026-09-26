<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use Webkul\Security\Models\User;

it('combines thirty days of incidents from three brands into isolated monthly totals', function (): void {
    $reviewer = UserFactory::new()->createQuietly();
    $catalog = [];

    foreach (['JCHICKEN', 'LUUCA', 'MOMOYO'] as $brandCode) {
        $catalog[$brandCode] = monthlyVolumeCatalog($brandCode, $reviewer);
    }

    for ($day = 1; $day <= 30; $day++) {
        foreach ($catalog as $brandCode => $entry) {
            $lines = [[
                'item_id'  => $entry['main']->id,
                'quantity' => (string) $day,
                'unit'     => $brandCode === 'JCHICKEN' && $day % 10 === 0 ? 'ML' : 'GR',
            ]];

            if ($day % 10 === 0) {
                $lines[] = ['item_id' => $entry['extra']->id, 'quantity' => '2', 'unit' => 'PCS'];
            }

            $event = [
                'category_id' => $entry['category']->id,
                'reason'      => "Sisa bahan hari {$day}",
                'section'     => $brandCode === 'JCHICKEN' ? 'COOK' : null,
                'lines'       => $lines,
            ];

            if ($brandCode === 'MOMOYO' && $day % 10 === 0) {
                $event['pip_item_id'] = $entry['pip']->id;
                $event['pip_quantity'] = (string) (1000 + $day);
            }

            $report = monthlyVolumeSubmit($entry['brand'], $entry['outlet'], sprintf('2026-09-%02d', $day), $event);
            if ($day < 30) {
                monthlyVolumeApprove($report, $reviewer, $brandCode);
            }
        }
    }

    $jchicken = $catalog['JCHICKEN'];
    $kemang = WasteOutlet::query()->create([
        'brand_id' => $jchicken['brand']->id, 'name' => 'Kemang', 'code' => 'KEMANG',
        'slug'     => 'jchicken-kemang', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $extraEvent = [
        'category_id' => $jchicken['category']->id,
        'section'     => 'COOK',
        'reason'      => 'Outlet dan bulan berbeda',
        'lines'       => [['item_id' => $jchicken['main']->id, 'quantity' => '100', 'unit' => 'GR']],
    ];
    monthlyVolumeApprove(monthlyVolumeSubmit($jchicken['brand'], $kemang, '2026-09-15', $extraEvent), $reviewer, 'JCHICKEN');
    $extraEvent['lines'][0]['quantity'] = '200';
    monthlyVolumeApprove(monthlyVolumeSubmit($jchicken['brand'], $jchicken['outlet'], '2026-10-01', $extraEvent), $reviewer, 'JCHICKEN');

    expect(WasteReport::query()->count())->toBe(92)
        ->and(WasteReport::query()->where('status', WasteReportStatus::Pending)->count())->toBe(3)
        ->and(WasteReport::query()->where('status', WasteReportStatus::Approved)->count())->toBe(89);

    $september = new WasteReportExport('2026-09-01', '2026-09-30', 'approved', user: $reviewer);
    $allStatuses = new WasteReportExport('2026-09-01', '2026-09-30', 'all', user: $reviewer);
    $october = new WasteReportExport('2026-10-01', '2026-10-31', 'approved', user: $reviewer);

    expect($september->detailRows())->toHaveCount(94)
        ->and($allStatuses->detailRows())->toHaveCount(100)
        ->and($september->summaryRows())->toHaveCount(8)
        ->and($allStatuses->summaryRows())->toHaveCount(8)
        ->and($october->detailRows())->toHaveCount(1)
        ->and($october->summaryRows()->sole()[6])->toBe(200.0);

    foreach ([
        ['Jchicken', 'Ciledug', 'SHARED-001', 'GR', 405.0],
        ['Jchicken', 'Ciledug', 'SHARED-001', 'ML', 30.0],
        ['Jchicken', 'Ciledug', 'EXTRA-001', 'PCS', 4.0],
        ['Jchicken', 'Kemang', 'SHARED-001', 'GR', 100.0],
        ['Luuca', 'Ciledug', 'SHARED-001', 'GR', 435.0],
        ['Luuca', 'Ciledug', 'EXTRA-001', 'PCS', 4.0],
        ['Momoyo', 'Ciledug', 'SHARED-001', 'GR', 435.0],
        ['Momoyo', 'Ciledug', 'EXTRA-001', 'PCS', 4.0],
    ] as [$brandName, $outletName, $itemCode, $unit, $quantity]) {
        $row = $september->summaryRows()->first(fn (array $row): bool => $row[0] === $brandName
            && $row[1] === $outletName && $row[3] === $itemCode && $row[5] === $unit);
        expect($row)->not->toBeNull()->and($row[6])->toBe($quantity);
    }

    foreach ($catalog as $brandCode => $entry) {
        $outletExport = new WasteReportExport(
            '2026-09-01', '2026-09-30', 'approved',
            $entry['brand']->id, $entry['outlet']->id, $reviewer,
        );
        $rows = $outletExport->sheets()[0]->collection()->skip($brandCode === 'MOMOYO' ? 5 : 6)->values();
        $days = $rows->pluck(0)->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): int => $brandCode === 'JCHICKEN'
                ? (int) $value
                : (int) SpreadsheetDate::excelToDateTimeObject((float) $value)->format('j'))
            ->unique()->values()->all();

        expect($outletExport->detailRows())->toHaveCount(31)
            ->and($rows)->toHaveCount(31)
            ->and($days)->toBe(range(1, 29));
    }

    $jchickenRows = (new WasteReportExport(
        '2026-09-01', '2026-09-30', 'approved',
        $jchicken['brand']->id, $jchicken['outlet']->id, $reviewer,
    ))->sheets()[0]->collection();
    expect($jchickenRows->filter(fn (array $row): bool => ($row[5] ?? null) === 'ML'))->toHaveCount(2);

    $momoyo = $catalog['MOMOYO'];
    $momoyoRows = (new WasteReportExport(
        '2026-09-01', '2026-09-30', 'approved',
        $momoyo['brand']->id, $momoyo['outlet']->id, $reviewer,
    ))->sheets()[0]->collection();
    expect($momoyoRows->filter(fn (array $row): bool => ($row[1] ?? null) === 'PIP Milk Tea'))->toHaveCount(4);

    $workbookPath = tempnam(sys_get_temp_dir(), 'waste-monthly-volume-');
    try {
        file_put_contents($workbookPath, Excel::raw($september, ExcelFormat::XLSX));
        $workbook = IOFactory::load($workbookPath);
        expect($workbook->getSheetCount())->toBe(7)
            ->and($workbook->getSheetByName('Detail'))->toBeNull()
            ->and($workbook->getSheetByName('Ringkasan'))->toBeNull();
    } finally {
        @unlink($workbookPath);
    }
});

/**
 * @return array{brand: WasteBrand, outlet: WasteOutlet, main: WasteItem, extra: WasteItem, pip: ?WasteItem, category: WasteCategory}
 */
function monthlyVolumeCatalog(string $brandCode, User $reviewer): array
{
    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true]);
    $brand->users()->attach($reviewer);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $main = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'SHARED-001', 'name' => 'Bahan Bersama',
        'unit'     => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $extra = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'EXTRA-001', 'name' => 'Kemasan',
        'unit'     => 'PCS', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);

    $pip = null;
    if ($brandCode === 'JCHICKEN') {
        WasteSection::query()->create(['brand_id' => $brand->id, 'code' => 'COOK', 'name' => 'COOK', 'is_active' => true]);
        $milliliters = WasteUnit::query()->create(['code' => 'ML', 'name' => 'Mililiter', 'is_active' => true]);
        $main->alternateUnits()->attach($milliliters);
    } elseif ($brandCode === 'MOMOYO') {
        $pip = WasteItem::query()->create([
            'brand_id' => $brand->id, 'code' => 'PIP-001', 'name' => 'PIP Milk Tea',
            'unit'     => 'PCS', 'item_type' => 'PIP', 'is_active' => true,
        ]);
    }

    return compact('brand', 'outlet', 'main', 'extra', 'pip', 'category');
}

/**
 * @param  array<string, mixed>  $event
 */
function monthlyVolumeSubmit(WasteBrand $brand, WasteOutlet $outlet, string $date, array $event): WasteReport
{
    $result = app(WasteReportService::class)->submit($brand, $outlet, [
        'event_date'     => $date, 'reporter_name' => 'Petugas QA Bulanan',
        'reporter_phone' => '081234567890', 'events' => [$event],
    ], [0 => [UploadedFile::fake()->image('bukti-'.$date.'.jpg')]]);

    return $result['report'];
}

function monthlyVolumeApprove(WasteReport $report, User $reviewer, string $brandCode): void
{
    $report->load('latestVersion.events.lines');
    foreach ($report->latestVersion->events as $event) {
        foreach ($event->lines as $line) {
            if ($brandCode === 'JCHICKEN') {
                $line->update(['sm_checked' => false, 'audit_checked' => true]);
            } elseif ($brandCode === 'LUUCA') {
                $line->update(['audit_checked' => true]);
            }
        }
    }

    app(WasteMisReviewService::class)->approve($report, $reviewer);
}
