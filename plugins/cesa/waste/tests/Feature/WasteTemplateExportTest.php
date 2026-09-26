<?php

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
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Webkul\Security\Models\User;

it('exports Jchicken and Luuca in their own monthly template layouts with saved item values', function (): void {
    $user = UserFactory::new()->create();
    [$jchicken, $jOutlet, $jItem, $jCategory] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', 'Chicken', 'J-001');
    [$luuca, $lOutlet, $lItem, $lCategory] = wasteTemplateCatalog('LUUCA', 'Luuca', 'Milk', 'L-001');
    $jchicken->users()->attach($user);
    $luuca->users()->attach($user);
    WasteSection::query()->create(['brand_id' => $jchicken->id, 'code' => 'COOK', 'name' => 'COOK', 'is_active' => true]);
    $otherOutlet = WasteOutlet::query()->create([
        'brand_id' => $jchicken->id, 'name' => 'Kemang', 'code' => 'KEMANG',
        'slug'     => 'jchicken-kemang', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);

    wasteTemplateApprovedReport(wasteTemplatePayload($jchicken, $jOutlet, $jItem, $jCategory, '2026-09-23', 'J Sep Ciledug', 'COOK'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($jchicken, $otherOutlet, $jItem, $jCategory, '2026-09-24', 'J Sep Kemang', 'COOK'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($jchicken, $jOutlet, $jItem, $jCategory, '2026-10-01', 'J Oct Ciledug', 'COOK'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($luuca, $lOutlet, $lItem, $lCategory, '2026-09-25', 'L Sep Ciledug'), $user);
    $jItem->update(['name' => 'Renamed chicken', 'code' => 'J-NEW', 'unit' => 'KG', 'item_type' => 'produk']);
    $lItem->update(['name' => 'Renamed milk', 'code' => 'L-NEW', 'unit' => 'LTR', 'item_type' => 'produk']);

    $workbook = wasteTemplateWorkbook(new WasteReportExport(status: 'approved', user: $user));
    $sheets = wasteTemplateSheets($workbook);
    $byReason = collect($sheets)->keyBy(fn (Worksheet $sheet): string => (string) $sheet->getCell('G7')->getValue());

    expect($workbook->getSheetCount())->toBe(6)
        ->and($workbook->getSheetNames())->toContain('Master data JCHICKEN', 'Master data LUUCA')
        ->and($sheets)->toHaveCount(4)
        ->and(collect($sheets)->every(fn (Worksheet $sheet): bool => ! str_contains($sheet->getTitle(), 'APPROVED')))->toBeTrue()
        ->and($byReason->keys()->sort()->values()->all())->toBe(['J Oct Ciledug', 'J Sep Ciledug', 'J Sep Kemang', 'L Sep Ciledug']);

    foreach (['J Sep Ciledug', 'J Sep Kemang', 'J Oct Ciledug'] as $reason) {
        $sheet = $byReason->get($reason);
        $month = $reason === 'J Oct Ciledug' ? 'OKTOBER 2026' : 'SEPTEMBER 2026';
        expect(wasteTemplateHeader($sheet, 6, 10))->toBe([
            'TANGGAL', 'NAMA PRODUK', 'KODE CSA', 'JENIS', 'JUMLAH', 'SATUAN CSA',
            'ALASAN WASTE', 'USER', 'SECTION', 'KATEGORI',
        ])
            ->and($sheet->getCell('B7')->getValue())->toBe('Chicken')
            ->and($sheet->getCell('C7')->getValue())->toBe('J-001')
            ->and($sheet->getCell('D7')->getValue())->toBe('bahan baku')
            ->and($sheet->getCell('F7')->getValue())->toBe('PCS')
            ->and($sheet->getCell('I7')->getValue())->toBe('COOK')
            ->and($sheet->getCell('J7')->getValue())->toBe('Waste')
            ->and($sheet->getCell('A4')->getValue())->toBe('BULAN, TAHUN: '.$month)
            ->and($sheet->getCell('A7')->getDataType())->toBe(DataType::TYPE_NUMERIC)
            ->and($sheet->getCell('E7')->getDataType())->toBe(DataType::TYPE_NUMERIC);
    }

    $luucaSheet = $byReason->get('L Sep Ciledug');
    expect(wasteTemplateHeader($luucaSheet, 6, 10))->toBe([
        'TANGGAL', 'NAMA PRODUK', 'KODE CSA', 'JENIS', 'JUMLAH', 'SATUAN CSA',
        'ALASAN WASTE', 'USER', 'KATEGORI', 'AUDIT',
    ])
        ->and($luucaSheet->getCell('B7')->getValue())->toBe('Milk')
        ->and($luucaSheet->getCell('C7')->getValue())->toBe('L-001')
        ->and($luucaSheet->getCell('F7')->getValue())->toBe('PCS')
        ->and($luucaSheet->getCell('I7')->getValue())->toBe('Waste')
        ->and($luucaSheet->getCell('A4')->getValue())->toBe('BULAN, TAHUN: SEPTEMBER 2026');
});

it('ships a master data sheet for every exported brand next to the monthly sheets', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', 'Chicken', 'J-001');
    $brand->users()->attach($user);
    WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => '0007', 'name' => 'Sauce Sachet',
        'unit'     => 'SHT', 'source_unit_label' => 'SHT', 'item_type' => 'others', 'is_active' => true,
    ]);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', 'Sep report'), $user);

    $workbook = wasteTemplateWorkbook(new WasteReportExport(status: 'approved', user: $user));
    $master = $workbook->getSheetByName('Master data JCHICKEN');

    expect($workbook->getSheetCount())->toBe(2)
        ->and($master)->not->toBeNull()
        ->and(wasteTemplateHeader($master, 1, 4))->toBe(['Nama Item', 'Kode Item', 'Satuan', 'JENIS'])
        ->and($master->getHighestRow())->toBe(3)
        ->and($master->getCell('A2')->getValue())->toBe('Chicken')
        ->and($master->getCell('C2')->getValue())->toBe('PCS')
        ->and($master->getCell('B3')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($master->getCell('B3')->getValue())->toBe('0007')
        ->and($master->getCell('C3')->getValue())->toBe('SHT')
        ->and($master->getCell('D3')->getValue())->toBe('others');
});

it('formats whole quantities without a trailing decimal separator', function (string $brandCode, string $quantityColumn, int $firstDataRow): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog($brandCode, $brandCode, 'Sample item', $brandCode.'-001');
    $brand->users()->attach($user);

    $payload = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-24', 'Sample waste');
    $payload['events'][0]['lines'] = [
        ['item_id' => $item->id, 'quantity' => '2'],
        ['item_id' => $item->id, 'quantity' => '1.25'],
    ];

    if ($brandCode === 'MOMOYO') {
        $payload['events'][0]['pip_item_id'] = $item->id;
        $payload['events'][0]['pip_quantity'] = '2';
        $payload['events'][] = [
            'category_id'  => $category->id,
            'reason'       => 'Another sample waste',
            'pip_item_id'  => $item->id,
            'pip_quantity' => '1.25',
            'lines'        => [['item_id' => $item->id, 'quantity' => '1']],
        ];
    }

    wasteTemplateApprovedReport($payload, $user);

    $sheet = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport(
        status: 'approved', brandId: $brand->id, outletId: $outlet->id, user: $user,
    )))[0];
    $wholeCell = "{$quantityColumn}{$firstDataRow}";
    $fractionCell = "{$quantityColumn}".($firstDataRow + 1);

    expect($sheet->getCell($wholeCell)->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and((float) $sheet->getCell($wholeCell)->getValue())->toBe(2.0)
        ->and($sheet->getStyle($wholeCell)->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($sheet->getCell($wholeCell)->getFormattedValue())->toBe('2')
        ->and($sheet->getCell($fractionCell)->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and((float) $sheet->getCell($fractionCell)->getValue())->toBe(1.25)
        ->and($sheet->getStyle($fractionCell)->getNumberFormat()->getFormatCode())->toBe('#,##0.####');

    if ($brandCode === 'MOMOYO') {
        expect($sheet->getCell('E6')->getDataType())->toBe(DataType::TYPE_NUMERIC)
            ->and($sheet->getStyle('E6')->getNumberFormat()->getFormatCode())->toBe('#,##0')
            ->and($sheet->getCell('E8')->getDataType())->toBe(DataType::TYPE_NUMERIC)
            ->and((float) $sheet->getCell('E8')->getValue())->toBe(1.25)
            ->and($sheet->getStyle('E8')->getNumberFormat()->getFormatCode())->toBe('#,##0.####')
            ->and($sheet->getStyle('F8')->getNumberFormat()->getFormatCode())->toBe('#,##0');
    }
})->with([
    'Jchicken' => ['JCHICKEN', 'E', 7],
    'Luuca'    => ['LUUCA', 'E', 7],
    'Momoyo'   => ['MOMOYO', 'F', 6],
]);

it('keeps QA reporter provenance in storage but leaves a missing source user blank in the template', function (): void {
    $reviewer = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', 'Chicken', 'J-001');
    $brand->users()->attach($reviewer);
    WasteSection::query()->create(['brand_id' => $brand->id, 'code' => 'COOK', 'name' => 'COOK', 'is_active' => true]);

    $qaPayload = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-01', 'QA example', 'COOK');
    $qaPayload['reporter_name'] = 'Petugas QA TPS (USER Excel kosong)';
    $qaPayload['reporter_email'] = 'qa-waste-202609-jchicken-row7@example.test';
    $qaReport = wasteTemplateApprovedReport($qaPayload, $reviewer);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-02', 'Real report', 'COOK'), $reviewer);

    $sheet = wasteTemplateWorkbook(new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))
        ->getActiveSheet();

    expect($qaReport->fresh()->reporter_name)->toBe('Petugas QA TPS (USER Excel kosong)')
        ->and($qaReport->reporterNameForDisplay())->toBe(__('waste::waste.qa_reporter'))
        ->and($sheet->getCell('H7')->getValue())->toBeNull()
        ->and($sheet->getCell('H8')->getValue())->toBe('Field User');
});

it('shows the date once per day for Jchicken and Luuca across lines and submissions', function (): void {
    $user = UserFactory::new()->create();
    foreach (['JCHICKEN', 'LUUCA'] as $brandCode) {
        [$brand, $outlet, $item, $category] = wasteTemplateCatalog($brandCode, ucfirst(strtolower($brandCode)), 'First item', $brandCode.'-1');
        $brand->users()->attach($user);
        $secondItem = WasteItem::query()->create([
            'brand_id' => $brand->id, 'code' => $brandCode.'-2', 'name' => 'Second item',
            'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
        ]);

        $first = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-01', 'First report');
        $first['events'][0]['lines'][] = ['item_id' => $secondItem->id, 'quantity' => '2'];
        wasteTemplateApprovedReport($first, $user);
        wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-01', 'Second report'), $user);
        wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-02', 'Next day'), $user);

        $sheet = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport(
            status: 'approved', brandId: $brand->id, outletId: $outlet->id, user: $user,
        )))[0];

        expect($sheet->getCell('C7')->getValue())->toBe($brandCode.'-1')
            ->and($sheet->getCell('C8')->getValue())->toBe($brandCode.'-2')
            ->and($sheet->getCell('C9')->getValue())->toBe($brandCode.'-1')
            ->and($sheet->getCell('C10')->getValue())->toBe($brandCode.'-1')
            ->and($sheet->getCell('A8')->getValue())->toBeNull()
            ->and($sheet->getCell('A9')->getValue())->toBeNull();

        if ($brandCode === 'JCHICKEN') {
            expect($sheet->getCell('A7')->getValue())->toBe(1)
                ->and($sheet->getCell('A10')->getValue())->toBe(2);
        } else {
            expect(SpreadsheetDate::excelToDateTimeObject((float) $sheet->getCell('A7')->getValue())->format('Y-m-d'))->toBe('2026-09-01')
                ->and(SpreadsheetDate::excelToDateTimeObject((float) $sheet->getCell('A10')->getValue())->format('Y-m-d'))->toBe('2026-09-02');
        }
    }
});

it('writes Momoyo PIP quantity once for multiple components and reads only the latest version', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $component, $category] = wasteTemplateCatalog('MOMOYO', 'Momoyo', 'Black tea', 'BB-1');
    $brand->users()->attach($user);
    $secondComponent = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'BB-2', 'name' => 'Creamer', 'unit' => 'GR', 'item_type' => 'bahan baku', 'is_active' => true]);
    $pip = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'PIP-1', 'name' => 'Milk Tea PIP', 'unit' => 'PCS', 'item_type' => 'PIP', 'is_active' => true]);
    $payload = wasteTemplatePayload($brand, $outlet, $component, $category, '2026-09-23', 'PIP pecah');
    $payload['events'] = [
        [
            'category_id' => $category->id, 'reason' => 'PIP pecah', 'pip_item_id' => $pip->id, 'pip_quantity' => '2',
            'lines'       => [
                ['item_id' => $component->id, 'quantity' => '1.25'],
                ['item_id' => $secondComponent->id, 'quantity' => '0.5'],
            ],
        ],
        [
            'category_id' => $category->id, 'reason' => 'Non PIP tumpah',
            'lines'       => [['item_id' => $component->id, 'quantity' => '3']],
        ],
    ];
    $report = wasteTemplateApprovedReport($payload, $user);
    $oldVersion = $report->versions()->create(['version_number' => 0, 'status' => 'approved', 'workflow_snapshot' => []]);
    $oldEvent = $oldVersion->events()->create(['sequence' => 0, 'category_name' => 'Waste', 'reason' => 'Old version']);
    $oldEvent->lines()->create(['item_code' => 'OLD', 'item_name' => 'Old item', 'unit' => 'PCS', 'quantity' => '999', 'line_role' => 'direct']);

    $export = new WasteReportExport(status: 'approved', brandId: $brand->id, outletId: $outlet->id, user: $user);
    $workbook = wasteTemplateWorkbook($export);
    $sheets = wasteTemplateSheets($workbook);
    expect($sheets)->toHaveCount(1);
    $sheet = $sheets[0];

    expect(wasteTemplateHeader($sheet, 5, 8))->toBe([
        'TGL', 'NAMA PIP', 'NAMA BARANG', 'KODE ITEM', 'QTY PIP', 'QTY', 'UNIT', 'KETERANGAN',
    ])
        ->and($sheet->getCell('B6')->getValue())->toBe('Milk Tea PIP')
        ->and($sheet->getCell('C6')->getValue())->toBe('Black tea')
        ->and($sheet->getCell('D6')->getValue())->toBe('BB-1')
        ->and((float) $sheet->getCell('E6')->getValue())->toBe(2.0)
        ->and((float) $sheet->getCell('F6')->getValue())->toBe(1.25)
        ->and($sheet->getCell('B7')->getValue())->toBe('Milk Tea PIP')
        ->and($sheet->getCell('C7')->getValue())->toBe('Creamer')
        ->and($sheet->getCell('D7')->getValue())->toBe('BB-2')
        ->and($sheet->getCell('E7')->getValue())->toBeNull()
        ->and((float) $sheet->getCell('F7')->getValue())->toBe(0.5)
        ->and($sheet->getCell('A7')->getValue())->toBe($sheet->getCell('A6')->getValue())
        ->and($sheet->getCell('B8')->getValue())->toBe('NON PIP')
        ->and($sheet->getCell('A8')->getValue())->toBe($sheet->getCell('A6')->getValue())
        ->and($sheet->getCell('E8')->getValue())->toBeNull()
        ->and((float) $sheet->getCell('F8')->getValue())->toBe(3.0)
        ->and($sheet->getCell('C9')->getValue())->toBeNull()
        ->and($sheet->getCell('A6')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('E6')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('F6')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($sheet->getCell('A4')->getValue())->toBeNull()
        ->and($workbook->getSheetCount())->toBe(2)
        ->and($export->detailRows()->pluck(11)->all())->toBe([2.0, null, null]);
});

it('reconstructs the Momoyo Excel reference and NON PIP quantity columns', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $oolong, $category] = wasteTemplateCatalog('MOMOYO', 'Momoyo', 'Oolong Tea', 'BB-000075');
    $brand->users()->attach($user);
    $oolong->update(['unit' => 'GR', 'source_unit_label' => 'gram', 'item_type' => 'Bahan Baku']);
    $pip = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'PIP-000010', 'name' => 'Black Tea PIP', 'unit' => 'GR', 'source_unit_label' => 'gram', 'item_type' => 'PIP', 'is_active' => true]);
    $blackTea = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'BB-000001', 'name' => 'Black Tea', 'unit' => 'GR', 'source_unit_label' => 'Gram', 'item_type' => 'Bahan Baku', 'is_active' => true]);
    $creamer = WasteItem::query()->create(['brand_id' => $brand->id, 'code' => 'BB-000077', 'name' => 'Non Dairy Creamer', 'unit' => 'GR', 'source_unit_label' => 'Gram', 'item_type' => 'Bahan Baku', 'is_active' => true]);

    $payload = wasteTemplatePayload($brand, $outlet, $oolong, $category, '2026-09-01', 'Oolong Tea');
    $payload['events'] = [
        [
            'category_id' => $category->id, 'reason' => 'Oolong Tea',
            'pip_item_id' => $oolong->id, 'pip_quantity' => '2796',
            'lines'       => [['item_id' => $oolong->id, 'quantity' => '136.39']],
        ],
        [
            'category_id' => $category->id, 'reason' => 'Black Tea PIP',
            'pip_item_id' => $pip->id, 'pip_quantity' => '353',
            'lines'       => [['item_id' => $blackTea->id, 'quantity' => '5.88']],
        ],
        [
            'category_id'  => $category->id, 'reason' => 'NON PIP',
            'pip_quantity' => '99',
            'lines'        => [['item_id' => $creamer->id, 'quantity' => '7.82']],
        ],
    ];
    wasteTemplateApprovedReport($payload, $user);
    $oolong->update(['name' => 'Updated Oolong Tea', 'source_unit_label' => 'GRAM']);

    $sheet = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport(
        status: 'approved', brandId: $brand->id, outletId: $outlet->id, user: $user,
    )))[0];

    expect($sheet->getCell('B6')->getValue())->toBe('Oolong Tea')
        ->and($sheet->getCell('C6')->getValue())->toBe('Oolong Tea')
        ->and($sheet->getCell('D6')->getValue())->toBe('BB-000075')
        ->and((float) $sheet->getCell('E6')->getValue())->toBe(2796.0)
        ->and((float) $sheet->getCell('F6')->getValue())->toBe(136.39)
        ->and($sheet->getCell('G6')->getValue())->toBe('gram')
        ->and($sheet->getCell('B7')->getValue())->toBe('Black Tea PIP')
        ->and($sheet->getCell('C7')->getValue())->toBe('Black Tea')
        ->and((float) $sheet->getCell('E7')->getValue())->toBe(353.0)
        ->and((float) $sheet->getCell('F7')->getValue())->toBe(5.88)
        ->and($sheet->getCell('G7')->getValue())->toBe('Gram')
        ->and($sheet->getCell('B8')->getValue())->toBe('NON PIP')
        ->and($sheet->getCell('C8')->getValue())->toBe('Non Dairy Creamer')
        ->and((float) $sheet->getCell('E8')->getValue())->toBe(99.0)
        ->and((float) $sheet->getCell('F8')->getValue())->toBe(7.82)
        ->and($sheet->getCell('G8')->getValue())->toBe('Gram')
        ->and($sheet->getCell('E8')->getDataType())->toBe(DataType::TYPE_NUMERIC);
});

it('keeps a saved Momoyo unit label through admin edits and uses the canonical label for alternate units', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('MOMOYO', 'Momoyo', 'Black Tea', 'BB-000001');
    $brand->users()->attach($user);
    $item->update(['unit' => 'GR', 'source_unit_label' => 'Gram']);
    $alternateUnit = WasteUnit::query()->create(['code' => 'ML', 'name' => 'Milliliter', 'is_active' => true]);
    $item->alternateUnits()->attach($alternateUnit);

    $payload = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', 'Tumpah');
    $service = app(WasteReportService::class);
    $report = $service->saveByAdmin($payload, null, $user);
    expect($report->latestVersion->events->first()->lines->first()->unit_label)->toBe('Gram');

    $payload['events'][0]['id'] = $report->latestVersion->events->first()->getKey();
    $payload['events'][0]['lines'][0]['id'] = $report->latestVersion->events->first()->lines->first()->getKey();
    $item->update(['source_unit_label' => 'gram']);
    $payload['events'][0]['reason'] = 'Tumpah saat persiapan';
    $report = $service->saveByAdmin($payload, $report, $user);
    expect($report->latestVersion->events->first()->lines->first()->unit_label)->toBe('Gram');

    $payload['events'][0]['lines'][0]['unit'] = 'ML';
    $report = $service->saveByAdmin($payload, $report, $user);
    expect($report->latestVersion->events->first()->lines->first()->unit)->toBe('ML')
        ->and($report->latestVersion->events->first()->lines->first()->unit_label)->toBe('ML');

    $item->update(['unit' => 'GR', 'source_unit_label' => 'PRS']);
    unset($payload['events'][0]['lines'][0]['unit']);
    $report = $service->saveByAdmin($payload, $report, $user);
    expect($report->latestVersion->events->first()->lines->first()->unit_label)->toBe('GR');
});

it('respects status, date, brand, outlet and user access when selecting related report rows', function (): void {
    $user = UserFactory::new()->create();
    $otherUser = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', 'Chicken', 'J-001');
    [$otherBrand, $otherOutlet, $otherItem, $otherCategory] = wasteTemplateCatalog('LUUCA', 'Luuca', 'Milk', 'L-001');
    $brand->users()->attach($user);
    $otherBrand->users()->attach($otherUser);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', 'Included'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-08-23', 'Outside date'), $user);
    $pending = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-24', 'Pending');
    app(WasteReportService::class)->saveByAdmin($pending, null, $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($otherBrand, $otherOutlet, $otherItem, $otherCategory, '2026-09-25', 'Outside access'), $otherUser);

    $approved = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user)));
    expect($approved)->toHaveCount(1)
        ->and($approved[0]->getCell('G7')->getValue())->toBe('Included')
        ->and($approved[0]->getCell('B8')->getValue())->toBeNull();

    expect(fn (): WasteReportExport => new WasteReportExport('2026-09-01', '2026-09-30', 'all', $brand->id, $otherOutlet->id, $user))
        ->toThrow(ValidationException::class);
    expect(fn (): WasteReportExport => new WasteReportExport('2026-10-01', '2026-09-30', 'all', $brand->id, $outlet->id, $user))
        ->toThrow(ValidationException::class);
    expect(fn (): WasteReportExport => new WasteReportExport('2026-09-01', '2026-09-30', 'all', $otherBrand->id, null, $user))
        ->toThrow(ValidationException::class);

    $allVisible = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport('2026-09-01', '2026-09-30', 'all', null, null, $user)));
    $reasons = collect($allVisible)->flatMap(fn (Worksheet $sheet): array => array_filter([
        $sheet->getCell('G7')->getValue(), $sheet->getCell('G8')->getValue(),
    ]))->sort()->values()->all();
    expect($allVisible)->toHaveCount(2)
        ->and($reasons)->toBe(['Included', 'Pending'])
        ->and(collect($allVisible)->first(fn (Worksheet $sheet): bool => $sheet->getCell('G7')->getValue() === 'Included')->getCell('A4')->getValue())->toBe('BULAN, TAHUN: SEPTEMBER 2026')
        ->and(collect($allVisible)->first(fn (Worksheet $sheet): bool => $sheet->getCell('G7')->getValue() === 'Pending')->getCell('A4')->getValue())->toBe('BULAN, TAHUN: SEPTEMBER 2026 | STATUS: PENDING');
});

it('keeps text that looks like an Excel formula as text while quantities stay numeric', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', '=2+3', '0007');
    $brand->users()->attach($user);
    $payload = wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', '=HYPERLINK("https://example.test","open")');
    $payload['reporter_name'] = '=SUM(1,2)';
    wasteTemplateApprovedReport($payload, $user);

    $export = new WasteReportExport(status: 'approved', user: $user);
    $workbook = wasteTemplateWorkbook($export);
    $sheet = wasteTemplateSheets($workbook)[0];
    foreach (['B7', 'C7', 'G7', 'H7'] as $cell) {
        expect($sheet->getCell($cell)->getDataType())->toBe(DataType::TYPE_STRING);
    }
    expect($sheet->getCell('B7')->getValue())->toBe('=2+3')
        ->and($sheet->getCell('C7')->getValue())->toBe('0007')
        ->and($sheet->getCell('G7')->getValue())->toBe('=HYPERLINK("https://example.test","open")')
        ->and($sheet->getCell('H7')->getValue())->toBe('=SUM(1,2)')
        ->and($sheet->getCell('E7')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($workbook->getSheetCount())->toBe(2)
        ->and($export->detailRows()->first()[9])->toBe('=HYPERLINK("https://example.test","open")');
});

it('uses the related master type only when an old report has no saved type', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('LUUCA', 'Luuca', 'Add On Serve Matcha', 'L001-081');
    $brand->users()->attach($user);
    $item->update(['item_type' => null]);
    $report = wasteTemplateApprovedReport(
        wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', 'Jenis belum ada'),
        $user,
    );
    $line = $report->latestVersion->events->first()->lines->first();
    expect($line->item_type)->toBeNull();

    $item->update(['item_type' => 'PRODUKSI STOK LUUCA']);
    $sheet = wasteTemplateSheets(wasteTemplateWorkbook(new WasteReportExport(status: 'approved', user: $user)))[0];

    expect($sheet->getCell('D7')->getValue())->toBe('PRODUKSI STOK LUUCA')
        ->and($line->fresh()->item_type)->toBeNull();
});

it('keeps separate summary totals for outlets and categories that share names', function (): void {
    $user = UserFactory::new()->create();
    [$brand, $outlet, $item, $category] = wasteTemplateCatalog('JCHICKEN', 'Jchicken', 'Chicken', 'J-001');
    $brand->users()->attach($user);
    $sameNameOutlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG2',
        'slug'     => 'jchicken-ciledug-2', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $sameNameCategory = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE2', 'name' => 'Waste', 'is_active' => true,
    ]);

    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $category, '2026-09-23', 'First'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $sameNameOutlet, $item, $category, '2026-09-23', 'Second'), $user);
    wasteTemplateApprovedReport(wasteTemplatePayload($brand, $outlet, $item, $sameNameCategory, '2026-09-23', 'Third'), $user);

    $summary = (new WasteReportExport(status: 'approved', user: $user))->summaryRows();
    expect($summary)->toHaveCount(3)
        ->and($summary->pluck(6)->all())->toBe([1.25, 1.25, 1.25]);
});

/** @return array{WasteBrand, WasteOutlet, WasteItem, WasteCategory} */
function wasteTemplateCatalog(string $brandCode, string $brandName, string $itemName, string $itemCode): array
{
    $brand = WasteBrand::query()->create(['name' => $brandName, 'code' => $brandCode, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => $itemCode, 'name' => $itemName,
        'unit'     => 'PCS', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true]);

    return [$brand, $outlet, $item, $category];
}

/** @return array<string, mixed> */
function wasteTemplatePayload(WasteBrand $brand, WasteOutlet $outlet, WasteItem $item, WasteCategory $category, string $date, string $reason, ?string $section = null): array
{
    return [
        'brand_id'      => $brand->id, 'outlet_id' => $outlet->id, 'event_date' => $date,
        'reporter_name' => 'Field User', 'reporter_phone' => '081234567890',
        'events'        => [[
            'section' => $section, 'category_id' => $category->id, 'reason' => $reason,
            'lines'   => [['item_id' => $item->id, 'quantity' => '1.25']],
        ]],
    ];
}

function wasteTemplateApprovedReport(array $payload, User $reviewer): WasteReport
{
    $report = app(WasteReportService::class)->saveByAdmin($payload, null, $reviewer);
    $brandCode = strtoupper((string) $report->brand->code);

    foreach ($report->latestVersion->events as $event) {
        foreach ($event->lines as $line) {
            if ($brandCode === 'JCHICKEN') {
                $line->update(['sm_checked' => false, 'audit_checked' => false]);
            } elseif ($brandCode === 'LUUCA') {
                $line->update(['audit_checked' => false]);
            }
        }
    }

    return app(WasteMisReviewService::class)->approve($report, $reviewer);
}

function wasteTemplateWorkbook(WasteReportExport $export): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'waste-export-');

    try {
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        Cell::setValueBinder(new DefaultValueBinder);

        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

/** @return array<int, Worksheet> */
function wasteTemplateSheets(Spreadsheet $workbook): array
{
    return array_values(array_filter(
        $workbook->getAllSheets(),
        fn (Worksheet $sheet): bool => (string) $sheet->getCell('A6')->getValue() === 'TANGGAL'
            || (string) $sheet->getCell('A5')->getValue() === 'TGL',
    ));
}

/** @return array<int, mixed> */
function wasteTemplateHeader(Worksheet $sheet, int $row, int $columnCount): array
{
    return array_map(fn (int $column): mixed => $sheet->getCell([$column, $row])->getValue(), range(1, $columnCount));
}
