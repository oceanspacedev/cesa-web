<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteMisReviewService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    config(['waste.submissions.max_attempts' => 100]);
    app()->setLocale('id');
});

it('reconstructs September source rows from public submissions through MIS review into monthly Excel sheets', function (): void {
    $user = UserFactory::new()->create();

    [$jchicken, $jOutlet, $jItems, $jCategories] = publicExcelCatalog('JCHICKEN', [
        'B001-036' => ['Daun Mint', 'GR', 'bahan baku'],
        'B001-088' => ['Tepung Crispy Breading', 'GR', 'bahan baku'],
        'P004-038' => ['WIP BURGER BUN', 'PRS', 'barang jadi & bahan baku'],
    ], ['Waste', 'Spoil'], ['BAR', 'COOK', 'MP']);
    [$luuca, $lOutlet, $lItems, $lCategories] = publicExcelCatalog('LUUCA', [
        'BB-100500' => ['BUAH STRAWBERRY', 'GR', 'BAHAN BAKU LUUCA'],
        'BB-100022' => ['BUAH LONGAN 565GR', 'GR', 'BAHAN BAKU LUUCA'],
        'BB-100023' => ['NATA DE COCO KARA 1KGX6', 'GR', 'BAHAN BAKU LUUCA'],
    ], ['Waste']);
    [$momoyo, $mOutlet, $mItems, $mCategories] = publicExcelCatalog('MOMOYO', [
        'BB-000075'  => ['Oolong Tea', 'gram', 'Bahan Baku'],
        'PIP-000010' => ['Black Tea PIP', 'gram', 'PIP'],
        'BB-000001'  => ['Black Tea', 'Gram', 'Bahan Baku'],
        'BB-000019'  => ['Non Dairy Creamer', 'Gram', 'Bahan Baku'],
    ], ['Waste']);

    foreach ([$jchicken, $luuca, $momoyo] as $brand) {
        $brand->users()->attach($user);
    }

    foreach ([[$jchicken, $jOutlet], [$luuca, $lOutlet], [$momoyo, $mOutlet]] as [$brand, $outlet]) {
        $this->get(route('waste.public.form', [
            'brand'  => strtolower($brand->code),
            'outlet' => $outlet->slug,
        ]))->assertSuccessful();
    }

    Livewire::test(PublicWasteReportForm::class, ['brand' => 'momoyo', 'outlet' => $mOutlet->slug])
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Simulasi')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->assertSeeText(__('waste::waste.step_hints.momoyo'))
        ->assertDontSeeText(__('waste::waste.fields.reason'));

    publicExcelSubmit($jOutlet, '2026-09-01', $jCategories['Waste'], 'layu', [[$jItems['B001-036'], '58']], 'BAR');
    publicExcelSubmit($jOutlet, '2026-09-01', $jCategories['Waste'], 'bekas tapisan', [[$jItems['B001-088'], '1320']], 'COOK');
    publicExcelSubmit($jOutlet, '2026-09-02', $jCategories['Spoil'], 'gosong', [[$jItems['P004-038'], '1']], 'MP');

    publicExcelSubmit($lOutlet, '2026-09-01', $lCategories['Waste'], 'Daun', [[$lItems['BB-100500'], '72,6']]);
    publicExcelSubmit($lOutlet, '2026-09-02', $lCategories['Waste'], 'Air', [
        [$lItems['BB-100022'], '494,1'],
        [$lItems['BB-100023'], '584,5'],
    ]);

    publicExcelSubmit($mOutlet, '2026-09-02', $mCategories['Waste'], null, [
        [$mItems['BB-000075'], '136.39'],
    ], pipItem: $mItems['BB-000075'], pipQuantity: '2796');
    publicExcelSubmit($mOutlet, '2026-09-02', $mCategories['Waste'], null, [
        [$mItems['BB-000001'], '5.88'],
    ], pipItem: $mItems['PIP-000010'], pipQuantity: '353');
    publicExcelSubmit($mOutlet, '2026-09-03', $mCategories['Waste'], null, [
        [$mItems['BB-000019'], '7.82'],
    ], pipQuantity: '99');

    expect(WasteReport::query()->count())->toBe(8)
        ->and(WasteReport::query()->where('status', WasteReportStatus::Pending)->count())->toBe(8)
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $jchicken->id, $jOutlet->id, $user))->hasReports())->toBeFalse()
        ->and(WasteReport::query()->where('brand_id', $jchicken->id)->whereDate('event_date', '2026-09-01')->count())->toBe(2)
        ->and(WasteReport::query()->where('brand_id', $luuca->id)->whereDate('event_date', '2026-09-02')->count())->toBe(1);

    expect(WasteReport::query()->where('brand_id', $momoyo->id)->with('latestVersion.events')->get()
        ->flatMap(fn (WasteReport $report) => $report->latestVersion->events->pluck('reason'))
        ->unique()->all())->toBe(['Waste']);

    $firstJchickenReport = WasteReport::query()->where('brand_id', $jchicken->id)->orderBy('id')->firstOrFail();
    expect(fn () => app(WasteMisReviewService::class)->approve($firstJchickenReport, $user))
        ->toThrow(ValidationException::class);

    publicExcelApprovePending($jchicken, $user, [
        'B001-036' => [false, false],
        'B001-088' => [false, false],
        'P004-038' => [false, false],
    ]);
    publicExcelApprovePending($luuca, $user, [
        'BB-100500' => [null, true],
        'BB-100022' => [null, true],
        'BB-100023' => [null, true],
    ]);
    publicExcelApprovePending($momoyo, $user);

    expect(WasteReport::query()->where('status', WasteReportStatus::Approved)->count())->toBe(8);

    publicExcelSubmit($jOutlet, '2026-10-01', $jCategories['Waste'], 'di luar bulan', [[$jItems['B001-036'], '7']], 'BAR');
    publicExcelApprovePending($jchicken, $user, ['B001-036' => [false, false]]);
    WasteWorkflow::query()->create([
        'brand_id'  => $jchicken->id,
        'name'      => 'Approval supervisor',
        'steps'     => [['label' => 'Supervisor', 'name' => 'Supervisor', 'phone' => '081234567890']],
        'is_active' => true,
    ]);
    publicExcelSubmit($jOutlet, '2026-09-03', $jCategories['Waste'], 'menunggu approval', [[$jItems['B001-036'], '12']], 'BAR');

    expect(WasteReport::query()->where('status', WasteReportStatus::Pending)->count())->toBe(1);

    $jExport = publicExcelExport($jchicken, $jOutlet, $user);
    $lExport = publicExcelExport($luuca, $lOutlet, $user);
    $mExport = publicExcelExport($momoyo, $mOutlet, $user);
    expect($jExport->detailRows())->toHaveCount(3)
        ->and($lExport->detailRows())->toHaveCount(3)
        ->and($mExport->detailRows())->toHaveCount(3);

    expect($jExport->sheets()[0]->collection()->get(6)[10])->toBeFalse()
        ->and($jExport->sheets()[0]->collection()->get(6)[11])->toBeFalse()
        ->and($lExport->sheets()[0]->collection()->get(6)[9])->toBeTrue();

    $jSheet = publicExcelTemplateSheet(publicExcelWorkbook($jExport));
    expect($jSheet->getCell('A4')->getValue())->toBe('BULAN, TAHUN: SEPTEMBER 2026')
        ->and($jSheet->getCell('A7')->getValue())->toBe(1)
        ->and($jSheet->getCell('A8')->getValue())->toBeNull()
        ->and($jSheet->getCell('A9')->getValue())->toBe(2)
        ->and($jSheet->getCell('B7')->getValue())->toBe('Daun Mint')
        ->and($jSheet->getCell('C7')->getValue())->toBe('B001-036')
        ->and($jSheet->getCell('D7')->getValue())->toBe('bahan baku')
        ->and((float) $jSheet->getCell('E7')->getValue())->toBe(58.0)
        ->and($jSheet->getCell('F7')->getValue())->toBe('GR')
        ->and($jSheet->getCell('G7')->getValue())->toBe('layu')
        ->and($jSheet->getCell('I7')->getValue())->toBe('BAR')
        ->and($jSheet->getCell('J7')->getValue())->toBe('Waste')
        ->and($jSheet->getCell('K7')->getValue())->toBeFalse()
        ->and($jSheet->getCell('L7')->getValue())->toBeFalse()
        ->and($jSheet->getCell('B8')->getValue())->toBe('Tepung Crispy Breading')
        ->and($jSheet->getCell('C8')->getValue())->toBe('B001-088')
        ->and((float) $jSheet->getCell('E8')->getValue())->toBe(1320.0)
        ->and($jSheet->getCell('I8')->getValue())->toBe('COOK')
        ->and($jSheet->getCell('K8')->getValue())->toBeFalse()
        ->and($jSheet->getCell('L8')->getValue())->toBeFalse()
        ->and($jSheet->getCell('B9')->getValue())->toBe('WIP BURGER BUN')
        ->and($jSheet->getCell('C9')->getValue())->toBe('P004-038')
        ->and($jSheet->getCell('D9')->getValue())->toBe('barang jadi & bahan baku')
        ->and((float) $jSheet->getCell('E9')->getValue())->toBe(1.0)
        ->and($jSheet->getCell('F9')->getValue())->toBe('PRS')
        ->and($jSheet->getCell('I9')->getValue())->toBe('MP')
        ->and($jSheet->getCell('J9')->getValue())->toBe('Spoil')
        ->and($jSheet->getCell('K9')->getValue())->toBeFalse()
        ->and($jSheet->getCell('L9')->getValue())->toBeFalse()
        ->and($jSheet->getCell('B10')->getValue())->toBeNull()
        ->and($jSheet->getCell('E8')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    $lSheet = publicExcelTemplateSheet(publicExcelWorkbook($lExport));
    expect($lSheet->getCell('A4')->getValue())->toBe('BULAN, TAHUN: SEPTEMBER 2026')
        ->and(SpreadsheetDate::excelToDateTimeObject((float) $lSheet->getCell('A7')->getValue())->format('Y-m-d'))->toBe('2026-09-01')
        ->and(SpreadsheetDate::excelToDateTimeObject((float) $lSheet->getCell('A8')->getValue())->format('Y-m-d'))->toBe('2026-09-02')
        ->and($lSheet->getCell('A9')->getValue())->toBeNull()
        ->and($lSheet->getCell('B7')->getValue())->toBe('BUAH STRAWBERRY')
        ->and($lSheet->getCell('C7')->getValue())->toBe('BB-100500')
        ->and($lSheet->getCell('D7')->getValue())->toBe('BAHAN BAKU LUUCA')
        ->and((float) $lSheet->getCell('E7')->getValue())->toBe(72.6)
        ->and($lSheet->getCell('F7')->getValue())->toBe('GR')
        ->and($lSheet->getCell('G7')->getValue())->toBe('Daun')
        ->and($lSheet->getCell('I7')->getValue())->toBe('Waste')
        ->and($lSheet->getCell('J7')->getValue())->toBeTrue()
        ->and($lSheet->getCell('B8')->getValue())->toBe('BUAH LONGAN 565GR')
        ->and($lSheet->getCell('C8')->getValue())->toBe('BB-100022')
        ->and((float) $lSheet->getCell('E8')->getValue())->toBe(494.1)
        ->and($lSheet->getCell('J8')->getValue())->toBeTrue()
        ->and($lSheet->getCell('B9')->getValue())->toBe('NATA DE COCO KARA 1KGX6')
        ->and($lSheet->getCell('C9')->getValue())->toBe('BB-100023')
        ->and((float) $lSheet->getCell('E9')->getValue())->toBe(584.5)
        ->and($lSheet->getCell('G9')->getValue())->toBe('Air')
        ->and($lSheet->getCell('J9')->getValue())->toBeTrue()
        ->and($lSheet->getCell('B10')->getValue())->toBeNull()
        ->and($lSheet->getCell('E7')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    $mSheet = publicExcelTemplateSheet(publicExcelWorkbook($mExport));
    expect($mSheet->getCell('A3')->getValue())->toBe('BULAN : SEPTEMBER')
        ->and(SpreadsheetDate::excelToDateTimeObject((float) $mSheet->getCell('A6')->getValue())->format('Y-m-d'))->toBe('2026-09-02')
        ->and($mSheet->getCell('B6')->getValue())->toBe('Oolong Tea')
        ->and($mSheet->getCell('C6')->getValue())->toBe('Oolong Tea')
        ->and($mSheet->getCell('D6')->getValue())->toBe('BB-000075')
        ->and((float) $mSheet->getCell('E6')->getValue())->toBe(2796.0)
        ->and((float) $mSheet->getCell('F6')->getValue())->toBe(136.39)
        ->and($mSheet->getCell('G6')->getValue())->toBe('gram')
        ->and($mSheet->getCell('H6')->getValue())->toBe('Waste')
        ->and(SpreadsheetDate::excelToDateTimeObject((float) $mSheet->getCell('A7')->getValue())->format('Y-m-d'))->toBe('2026-09-02')
        ->and($mSheet->getCell('B7')->getValue())->toBe('Black Tea PIP')
        ->and($mSheet->getCell('C7')->getValue())->toBe('Black Tea')
        ->and($mSheet->getCell('D7')->getValue())->toBe('BB-000001')
        ->and((float) $mSheet->getCell('E7')->getValue())->toBe(353.0)
        ->and((float) $mSheet->getCell('F7')->getValue())->toBe(5.88)
        ->and($mSheet->getCell('G7')->getValue())->toBe('Gram')
        ->and($mSheet->getCell('H7')->getValue())->toBe('Waste')
        ->and(SpreadsheetDate::excelToDateTimeObject((float) $mSheet->getCell('A8')->getValue())->format('Y-m-d'))->toBe('2026-09-03')
        ->and($mSheet->getCell('B8')->getValue())->toBe('NON PIP')
        ->and($mSheet->getCell('C8')->getValue())->toBe('Non Dairy Creamer')
        ->and($mSheet->getCell('D8')->getValue())->toBe('BB-000019')
        ->and((float) $mSheet->getCell('E8')->getValue())->toBe(99.0)
        ->and((float) $mSheet->getCell('F8')->getValue())->toBe(7.82)
        ->and($mSheet->getCell('G8')->getValue())->toBe('Gram')
        ->and($mSheet->getCell('H8')->getValue())->toBe('Waste')
        ->and($mSheet->getCell('C9')->getValue())->toBeNull()
        ->and($mSheet->getCell('E6')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($mSheet->getCell('E8')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and($mSheet->getCell('F6')->getDataType())->toBe(DataType::TYPE_NUMERIC);

    $october = new WasteReportExport('2026-10-01', '2026-10-31', 'approved', $jchicken->id, $jOutlet->id, $user);
    expect($october->detailRows())->toHaveCount(1)
        ->and(publicExcelTemplateSheet(publicExcelWorkbook($october))->getCell('G7')->getValue())->toBe('di luar bulan');
});

/**
 * @param  array<string, array{0: string, 1: string, 2: string}>  $itemRows
 * @param  array<int, string>  $categoryNames
 * @param  array<int, string>  $sectionNames
 * @return array{WasteBrand, WasteOutlet, array<string, WasteItem>, array<string, WasteCategory>}
 */
function publicExcelCatalog(string $brandCode, array $itemRows, array $categoryNames, array $sectionNames = []): array
{
    $brand = WasteBrand::query()->create([
        'name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true,
    ]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);

    $items = [];
    foreach ($itemRows as $code => [$name, $unit, $type]) {
        $items[$code] = WasteItem::query()->create([
            'brand_id' => $brand->id, 'code' => $code, 'name' => $name,
            'unit'     => $unit, 'item_type' => $type, 'is_active' => true,
        ]);
    }

    $categories = [];
    foreach ($categoryNames as $name) {
        $categories[$name] = WasteCategory::query()->create([
            'brand_id' => $brand->id, 'code' => strtoupper($name), 'name' => $name, 'is_active' => true,
        ]);
    }

    foreach ($sectionNames as $name) {
        WasteSection::query()->create([
            'brand_id' => $brand->id, 'code' => $name, 'name' => $name, 'is_active' => true,
        ]);
    }

    return [$brand, $outlet, $items, $categories];
}

/**
 * @param  array<int, array{0: WasteItem, 1: string}>  $lines
 */
function publicExcelSubmit(
    WasteOutlet $outlet,
    string $date,
    WasteCategory $category,
    ?string $reason,
    array $lines,
    ?string $section = null,
    ?WasteItem $pipItem = null,
    ?string $pipQuantity = null,
): void {
    $component = Livewire::test(PublicWasteReportForm::class, [
        'brand' => strtolower($outlet->brand->code), 'outlet' => $outlet->slug,
    ])
        ->set('data.event_date', $date)
        ->set('data.reporter_name', 'Petugas Simulasi')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.section', $section ?? '');

    if ($reason !== null) {
        $component->set('data.events.0.reason', $reason);
    }

    if ($pipItem) {
        $component->set('data.events.0.pip_item_id', $pipItem->id);
    }

    if ($pipQuantity !== null) {
        $component->set('data.events.0.pip_quantity', $pipQuantity);
    }

    foreach ($lines as $lineIndex => [$item, $quantity]) {
        if ($lineIndex > 0) {
            $component->call('addLine', 0);
        }

        $component->set("data.events.0.lines.{$lineIndex}.item_id", $item->id)
            ->set("data.events.0.lines.{$lineIndex}.quantity", $quantity);
    }

    $component->set('photos.0.0', UploadedFile::fake()->image('bukti-simulasi.jpg'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();
}

/**
 * @param  array<string, array{0: ?bool, 1: bool}>  $sourceChecks
 */
function publicExcelApprovePending(WasteBrand $brand, User $reviewer, array $sourceChecks = []): void
{
    $reports = WasteReport::query()
        ->with('latestVersion.events.lines')
        ->where('brand_id', $brand->id)
        ->where('status', WasteReportStatus::Pending)
        ->orderBy('id')
        ->get();

    foreach ($reports as $report) {
        foreach ($report->latestVersion->events as $event) {
            foreach ($event->lines as $line) {
                if ($brand->code === 'JCHICKEN') {
                    [$smChecked, $auditChecked] = $sourceChecks[$line->item_code]
                        ?? throw new LogicException("Missing source checks for {$line->item_code}");
                    $line->update(['sm_checked' => $smChecked, 'audit_checked' => $auditChecked]);
                } elseif ($brand->code === 'LUUCA') {
                    [, $auditChecked] = $sourceChecks[$line->item_code]
                        ?? throw new LogicException("Missing source audit for {$line->item_code}");
                    $line->update(['audit_checked' => $auditChecked]);
                }
            }
        }

        expect(app(WasteMisReviewService::class)->approve($report, $reviewer)->status)
            ->toBe(WasteReportStatus::Approved);
    }
}

function publicExcelExport(WasteBrand $brand, WasteOutlet $outlet, object $user): WasteReportExport
{
    return new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $user);
}

function publicExcelWorkbook(WasteReportExport $export): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'waste-public-export-');

    try {
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        Cell::setValueBinder(new DefaultValueBinder);

        return IOFactory::load($path);
    } finally {
        @unlink($path);
    }
}

function publicExcelTemplateSheet(Spreadsheet $workbook): Worksheet
{
    expect($workbook->getSheetCount())->toBe(2);

    return $workbook->getSheet(0);
}
