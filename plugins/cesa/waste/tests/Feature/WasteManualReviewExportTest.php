<?php

use Cesa\Waste\Enums\WasteApprovalStatus;
use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Webkul\Security\Models\User;

it('requires Jchicken line checks again after a reason correction and exports the new decisions', function (): void {
    [$brand, $outlet, $category, $items, $user] = reviewExportCatalog('JCHICKEN', ['B001-036', 'B001-088']);
    $payload = reviewExportPayload($brand, $outlet, $category, [
        ['item_id' => $items[0]->id, 'quantity' => '58', 'sm_checked' => '0', 'audit_checked' => '1'],
        ['item_id' => $items[1]->id, 'quantity' => '1320', 'sm_checked' => '1', 'audit_checked' => '0'],
    ]);

    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $user);
    expect($report->status)->toBe(WasteReportStatus::Approved);

    $payload['events'][0]['id'] = $report->latestVersion->events->first()->getKey();
    foreach ($report->latestVersion->events->first()->lines as $index => $savedLine) {
        $payload['events'][0]['lines'][$index]['id'] = $savedLine->getKey();
    }
    $payload['events'][0]['reason'] = 'Koreksi alasan';
    unset($payload['events'][0]['lines'][0]['sm_checked'], $payload['events'][0]['lines'][0]['audit_checked']);
    $corrected = $service->saveByAdmin($payload, $report, $user);
    $lines = $corrected->latestVersion->events->first()->lines;
    expect($lines[0]->sm_checked)->toBeNull()
        ->and($lines[0]->audit_checked)->toBeNull()
        ->and($lines[1]->sm_checked)->toBeNull()
        ->and($lines[1]->audit_checked)->toBeNull()
        ->and($corrected->status)->toBe(WasteReportStatus::Approved);

    $workbook = reviewExportWorkbook(new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user));
    $sheet = $workbook->getSheet(0);
    expect($sheet->getCell('K6')->getValue())->toBe('STORE MANAGER')
        ->and($sheet->getCell('L6')->getValue())->toBe('AUDIT')
        ->and($sheet->getCell('K7')->getValue())->toBeNull()
        ->and($sheet->getCell('L7')->getValue())->toBeNull()
        ->and($sheet->getCell('K8')->getValue())->toBeNull()
        ->and($sheet->getCell('L8')->getValue())->toBeNull()
        ->and($sheet->getCell('M7')->getValue())->toBe('Avoidable food waste')
        ->and($sheet->getCell('N6')->getValue())->toBe('Waste');
    $workbook->disconnectWorksheets();
});

it('clears per-line review decisions when an incident date changes', function (): void {
    [$brand, $outlet, $category, $items, $reviewer] = reviewExportCatalog('LUUCA', ['BB-100022']);
    $payload = reviewExportPayload($brand, $outlet, $category, [
        ['item_id' => $items[0]->id, 'quantity' => '494,1', 'audit_checked' => '1'],
    ]);
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $reviewer);
    $payload['events'][0]['id'] = $report->latestVersion->events->first()->getKey();
    $payload['events'][0]['lines'][0]['id'] = $report->latestVersion->events->first()->lines->first()->getKey();
    $payload['event_date'] = '2026-09-03';

    $updated = $service->saveByAdmin($payload, $report, $reviewer);

    expect($updated->latestVersion->events->first()->lines->first()->audit_checked)->toBeNull()
        ->and($updated->status)->toBe(WasteReportStatus::Approved);
});

it('exports Luuca AUDIT as separate booleans for lines in one incident', function (): void {
    [$brand, $outlet, $category, $items, $user] = reviewExportCatalog('LUUCA', ['BB-100022', 'BB-100023']);
    $report = app(WasteReportService::class)->saveByAdmin(reviewExportPayload($brand, $outlet, $category, [
        ['item_id' => $items[0]->id, 'quantity' => '494,1', 'audit_checked' => '1'],
        ['item_id' => $items[1]->id, 'quantity' => '584,5', 'audit_checked' => '0'],
    ]), null, $user);

    $workbook = reviewExportWorkbook(new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user));
    $sheet = $workbook->getSheet(0);
    expect($sheet->getCell('J6')->getValue())->toBe('STORE MANAGER')
        ->and($sheet->getCell('K6')->getValue())->toBe('AUDIT')
        ->and($sheet->getCell('J7')->getValue())->toBeNull()
        ->and($sheet->getCell('K7')->getValue())->toBeNull()
        ->and($sheet->getCell('J8')->getValue())->toBeNull()
        ->and($sheet->getCell('K8')->getValue())->toBeNull()
        ->and((float) $sheet->getCell('E7')->getValue())->toBe(494.1)
        ->and((float) $sheet->getCell('E8')->getValue())->toBe(584.5);
    $workbook->disconnectWorksheets();
});

it('does not accept review flags supplied through a public report', function (): void {
    [$brand, $outlet, $category, $items, $user] = reviewExportCatalog('JCHICKEN', ['B001-036']);
    $result = app(WasteReportService::class)->submit($brand, $outlet, reviewExportPayload($brand, $outlet, $category, [
        ['item_id' => $items[0]->id, 'quantity' => '58', 'sm_checked' => '1', 'audit_checked' => '1'],
    ]), [0 => [UploadedFile::fake()->image('bukti.jpg')]]);
    $line = $result['report']->latestVersion->events->first()->lines->first();
    expect($line->sm_checked)->toBeNull()
        ->and($line->audit_checked)->toBeNull()
        ->and($result['report']->status)->toBe(WasteReportStatus::Approved)
        ->and($result['report']->approved_at)->not->toBeNull()
        ->and($result['approval_tokens'])->toBe([]);
});

it('clears MIS review flags when outlet staff corrects a checked quantity', function (): void {
    [$brand, $outlet, $category, $items, $reviewer] = reviewExportCatalog('JCHICKEN', ['B001-036']);
    $payload = reviewExportPayload($brand, $outlet, $category, [
        ['item_id' => $items[0]->id, 'quantity' => '58', 'sm_checked' => '0', 'audit_checked' => '1'],
    ]);
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $reviewer);
    $payload['events'][0]['id'] = $report->latestVersion->events->first()->getKey();
    $payload['events'][0]['lines'][0]['id'] = $report->latestVersion->events->first()->lines->first()->getKey();
    $outletStaff = UserFactory::new()->createQuietly();
    $outlet->users()->attach($outletStaff);

    $payload['events'][0]['lines'][0]['sm_checked'] = '1';
    $payload['events'][0]['lines'][0]['quantity'] = '59';
    $corrected = $service->saveByAdmin($payload, $report, $outletStaff);
    $line = $corrected->latestVersion->events->first()->lines->first();

    expect($line->quantity)->toBe('59.0000')
        ->and($line->sm_checked)->toBeNull()
        ->and($line->audit_checked)->toBeNull()
        ->and($corrected->status)->toBe(WasteReportStatus::Approved);
});

it('uses master approval steps as spreadsheet columns and keeps SM and AUDIT when approval is not set', function (): void {
    [$brand, $outlet, $category, $items, $user] = reviewExportCatalog('JCHICKEN', ['B001-036']);
    $service = app(WasteReportService::class);

    $exportMonth = function (string $date, array $steps) use ($brand, $outlet, $category, $items, $user, $service): array {
        WasteWorkflow::query()->where('brand_id', $brand->id)->delete();
        if ($steps !== []) {
            WasteWorkflow::query()->create([
                'brand_id'  => $brand->id,
                'outlet_id' => $outlet->id,
                'name'      => 'Persetujuan Ciledug',
                'is_active' => true,
                'steps'     => collect($steps)->map(fn (string $label): array => [
                    'label' => $label,
                    'name'  => $label,
                    'phone' => '081234567890',
                ])->all(),
            ]);
        }

        $payload = reviewExportPayload($brand, $outlet, $category, [
            ['item_id' => $items[0]->id, 'quantity' => '12'],
        ]);
        $payload['event_date'] = $date;
        $report = $service->saveByAdmin($payload, null, $user);
        $approvals = $report->latestVersion->approvals()->orderBy('step_order')->get();
        foreach ($approvals as $index => $approval) {
            $approval->update([
                'status' => $index === $approvals->count() - 1 ? WasteApprovalStatus::Rejected : WasteApprovalStatus::Approved,
            ]);
        }
        $period = substr($date, 0, 7);
        $sheet = reviewExportWorkbook(new WasteReportExport($period.'-01', $period.'-28', 'all', $brand->id, $outlet->id, $user))->getSheet(0);
        $headers = [];
        foreach (range(11, 10 + max(2, count($steps))) as $column) {
            $headers[] = $sheet->getCell(chr(64 + $column).'6')->getValue();
        }

        return [$headers, $sheet];
    };

    [$defaultHeaders, $defaultSheet] = $exportMonth('2026-09-02', []);
    expect($defaultHeaders)->toBe(['STORE MANAGER', 'AUDIT'])
        ->and($defaultSheet->getCell('K7')->getValue())->toBeNull()
        ->and($defaultSheet->getCell('L7')->getValue())->toBeNull();

    [$oneHeader, $oneSheet] = $exportMonth('2026-10-02', ['Supervisor']);
    expect($oneHeader)->toBe(['Supervisor', null])
        ->and($oneSheet->getCell('K7')->getValue())->toBeFalse();

    [$twoHeaders, $twoSheet] = $exportMonth('2026-11-02', ['SM', 'AUDIT']);
    expect($twoHeaders)->toBe(['SM', 'AUDIT'])
        ->and($twoSheet->getCell('K7')->getValue())->toBeTrue()
        ->and($twoSheet->getCell('L7')->getValue())->toBeFalse();

    [$threeHeaders, $threeSheet] = $exportMonth('2026-12-02', ['SM', 'AUDIT', 'GA']);
    expect($threeHeaders)->toBe(['SM', 'AUDIT', 'GA'])
        ->and($threeSheet->getCell('K7')->getValue())->toBeTrue()
        ->and($threeSheet->getCell('L7')->getValue())->toBeTrue()
        ->and($threeSheet->getCell('M7')->getValue())->toBeFalse();
});

/** @return array{WasteBrand, WasteOutlet, WasteCategory, array<int, WasteItem>, User} */
function reviewExportCatalog(string $brandCode, array $itemCodes): array
{
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true]);
    $brand->users()->attach($user);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true]);
    $items = [];
    foreach ($itemCodes as $code) {
        $items[] = WasteItem::query()->create([
            'brand_id' => $brand->id, 'code' => $code, 'name' => 'Barang '.$code,
            'unit'     => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true,
        ]);
    }

    return [$brand, $outlet, $category, $items, $user];
}

/** @param array<int, array<string, mixed>> $lines */
function reviewExportPayload(WasteBrand $brand, WasteOutlet $outlet, WasteCategory $category, array $lines): array
{
    return [
        'brand_id'      => $brand->id, 'outlet_id' => $outlet->id, 'event_date' => '2026-09-02',
        'reporter_name' => 'QA Excel', 'reporter_phone' => '0000000000', 'status' => 'approved',
        'events'        => [['category_id' => $category->id, 'reason' => 'Contoh Excel', 'lines' => $lines]],
    ];
}

function reviewExportWorkbook(WasteReportExport $export): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'waste-review-export-');

    try {
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));

        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}
