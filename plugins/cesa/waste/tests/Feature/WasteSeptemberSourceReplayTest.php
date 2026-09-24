<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEvidence;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    config(['waste.submissions.max_attempts' => 200]);
    app()->setLocale('id');

    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('replays all September source lines through public input, MIS review, and monthly Excel', function (string $brandCode, int $expectedLines): void {
    $source = json_decode(file_get_contents(__DIR__.'/../Fixtures/september_2026_source_replay.json'), true, 512, JSON_THROW_ON_ERROR);
    $fixture = $source['brands'][$brandCode];
    $rows = $fixture['rows'];
    expect($source['period'])->toBe('2026-09')->and($rows)->toHaveCount($expectedLines);

    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($brandCode)), 'code' => $brandCode, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => strtolower($brandCode).'-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $this->actingAs($reviewer);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $categories = [];
    foreach (array_unique(array_column($rows, 'category')) as $categoryName) {
        $categories[$categoryName] = WasteCategory::query()->create([
            'brand_id' => $brand->id, 'code' => Str::upper(Str::slug($categoryName, '_')),
            'name'     => $categoryName, 'is_active' => true,
        ]);
    }
    foreach (array_unique(array_filter(array_column($rows, 'section'))) as $sectionName) {
        WasteSection::query()->create([
            'brand_id' => $brand->id, 'code' => $sectionName, 'name' => $sectionName, 'is_active' => true,
        ]);
    }

    $unitCodes = collect($fixture['items'])->pluck('unit')
        ->merge(collect($fixture['alternate_candidates'])->flatten())
        ->unique()->values()->all();
    $units = [];
    foreach ($unitCodes as $unitCode) {
        $units[$unitCode] = WasteUnit::query()->create(['code' => $unitCode, 'name' => $unitCode, 'is_active' => true]);
    }

    $items = [];
    foreach ($fixture['items'] as $code => $itemData) {
        $items[$code] = WasteItem::query()->create([
            'brand_id'  => $brand->id, 'code' => $code, 'name' => $itemData['name'],
            'unit'      => $itemData['unit'], 'source_unit_label' => $itemData['source_unit_label'],
            'item_type' => $itemData['item_type'], 'is_active' => true,
        ]);
    }

    $candidateCount = 0;
    foreach ($fixture['alternate_candidates'] as $code => $alternateCodes) {
        $candidateCount += count($alternateCodes);
        expect($items[$code]->alternateUnits()->count())->toBe(0);
        Livewire::test(ManageWasteItems::class)
            ->callAction(TestAction::make(EditAction::class)->table($items[$code]), data: [
                'alternateUnits' => array_map(fn (string $unitCode): int => $units[$unitCode]->id, $alternateCodes),
            ])
            ->assertHasNoFormErrors();
        expect($items[$code]->fresh()->alternateUnits()->pluck('code')->sort()->values()->all())->toBe($alternateCodes);
    }
    expect($candidateCount)->toBe($brandCode === 'JCHICKEN' ? 11 : 0);

    $corrections = collect($rows)->flatMap(fn (array $row): array => $row['corrections'] ?? [])->countBy()->all();
    if ($brandCode === 'JCHICKEN') {
        expect($corrections['unit_ml_colon_to_ml'] ?? 0)->toBe(1)
            ->and($corrections['primary_unit_corrected_from_master'] ?? 0)->toBe(1);
    } elseif ($brandCode === 'LUUCA') {
        expect($corrections['date_star_to_sep_07'] ?? 0)->toBe(1)
            ->and($corrections['date_2029_typo_to_2026'] ?? 0)->toBe(1);
    } else {
        expect($corrections['shifted_unit_to_master'] ?? 0)->toBe(4)
            ->and($corrections['category_typo_to_training_trial'] ?? 0)->toBe(12);
    }

    foreach ($rows as $row) {
        $event = [
            'source_event_id' => null,
            'section'         => $row['section'] ?? '',
            'category_id'     => $categories[$row['category']]->id,
            'category_name'   => '',
            'reason'          => $row['reason'] ?? '',
            'pip_item_id'     => isset($row['pip_code']) ? $items[$row['pip_code']]->id : null,
            'pip_quantity'    => $row['pip_quantity'] ?? null,
            'lines'           => [[
                'item_id'  => $items[$row['item_code']]->id,
                'quantity' => $row['quantity'],
                'unit'     => $row['unit'],
            ]],
        ];
        Livewire::test(PublicWasteReportForm::class, ['brand' => strtolower($brandCode), 'outlet' => $outlet->slug])
            ->set('data.event_date', $row['date'])
            ->set('data.reporter_name', 'Petugas QA TPS')
            ->set('data.reporter_phone', '081234567890')
            ->call('nextStep')
            ->set('data.events', [$event])
            ->set('photos.0.0', UploadedFile::fake()->image('bukti-qa.jpg'))
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();
    }

    expect(WasteReport::query()->count())->toBe($expectedLines)
        ->and(WasteReport::query()->where('status', WasteReportStatus::Pending)->count())->toBe($expectedLines)
        ->and(WasteEvidence::query()->count())->toBe($expectedLines)
        ->and((new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))->hasReports())->toBeFalse();

    foreach (WasteReport::query()->with('latestVersion.events.lines')->orderBy('id')->get() as $index => $report) {
        $row = $rows[$index];
        if ($brandCode !== 'MOMOYO') {
            $event = $report->latestVersion->events->sole();
            $line = $event->lines->sole();
            $reviewedLine = [
                'id'   => $line->id, 'item_id' => $line->item_id, 'quantity' => $line->quantity,
                'unit' => $line->unit, 'audit_checked' => $row['audit'],
            ];
            if ($brandCode === 'JCHICKEN') {
                $reviewedLine['sm_checked'] = $row['sm'];
            }
            app(WasteReportService::class)->saveByAdmin([
                'brand_id'       => $brand->id, 'outlet_id' => $outlet->id, 'event_date' => $row['date'],
                'reporter_name'  => $report->reporter_name, 'reporter_phone' => $report->reporter_phone,
                'reporter_email' => $report->reporter_email,
                'events'         => [[
                    'id'          => $event->id, 'section' => $event->section,
                    'category_id' => $event->category_id, 'reason' => $event->reason,
                    'pip_item_id' => $event->pip_item_id, 'pip_quantity' => $event->pip_quantity,
                    'lines'       => [$reviewedLine],
                ]],
            ], $report, $reviewer);
        }
        expect(app(WasteMisReviewService::class)->approve($report, $reviewer)->status)->toBe(WasteReportStatus::Approved);
    }

    $export = new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer);
    expect($export->detailRows())->toHaveCount($expectedLines)
        ->and(WasteReport::query()->where('status', WasteReportStatus::Approved)->count())->toBe($expectedLines);
    $path = tempnam(sys_get_temp_dir(), 'waste-september-replay-');
    try {
        file_put_contents($path, Excel::raw($export, ExcelFormat::XLSX));
        Cell::setValueBinder(new DefaultValueBinder);
        $sheet = IOFactory::load($path)->getSheet(0);
    } finally {
        @unlink($path);
    }

    $firstDataRow = $brandCode === 'MOMOYO' ? 6 : 7;
    $this->assertSame($expectedLines + $firstDataRow - 1, $sheet->getHighestDataRow());
    $lastExportDate = null;
    foreach ($rows as $index => $row) {
        $excelRow = $firstDataRow + $index;
        $context = "{$brandCode} sumber {$row['source_row']} / ekspor {$excelRow}";
        $dateCell = $sheet->getCell("A{$excelRow}")->getValue();
        if ($brandCode === 'JCHICKEN' && $dateCell !== null) {
            $lastExportDate = sprintf('2026-09-%02d', (int) $dateCell);
        } elseif ($brandCode === 'LUUCA' && $dateCell !== null) {
            $lastExportDate = SpreadsheetDate::excelToDateTimeObject((float) $dateCell)->format('Y-m-d');
        } elseif ($brandCode === 'MOMOYO') {
            $lastExportDate = SpreadsheetDate::excelToDateTimeObject((float) $dateCell)->format('Y-m-d');
        }
        $this->assertSame($row['date'], $lastExportDate, $context.' tanggal');

        $catalogItem = $fixture['items'][$row['item_code']];
        if ($brandCode === 'MOMOYO') {
            $reference = isset($row['pip_code']) ? $fixture['items'][$row['pip_code']]['name'] : 'NON PIP';
            $this->assertSame($reference, $sheet->getCell("B{$excelRow}")->getValue(), $context.' referensi PIP');
            $this->assertSame($catalogItem['name'], $sheet->getCell("C{$excelRow}")->getValue(), $context.' nama');
            $this->assertSame($row['item_code'], $sheet->getCell("D{$excelRow}")->getValue(), $context.' kode');
            $pipValue = $sheet->getCell("E{$excelRow}")->getValue();
            if (isset($row['pip_quantity'])) {
                $this->assertEqualsWithDelta((float) $row['pip_quantity'], (float) $pipValue, 0.0001, $context.' QTY PIP');
            } else {
                $this->assertNull($pipValue, $context.' QTY PIP');
            }
            $this->assertEqualsWithDelta((float) $row['quantity'], (float) $sheet->getCell("F{$excelRow}")->getValue(), 0.0001, $context.' QTY');
            $this->assertSame($catalogItem['source_unit_label'], $sheet->getCell("G{$excelRow}")->getValue(), $context.' satuan terkoreksi');
            $this->assertSame($row['category'], $sheet->getCell("H{$excelRow}")->getValue(), $context.' keterangan');
        } else {
            $this->assertSame($catalogItem['name'], $sheet->getCell("B{$excelRow}")->getValue(), $context.' nama');
            $this->assertSame($row['item_code'], $sheet->getCell("C{$excelRow}")->getValue(), $context.' kode');
            $this->assertSame($catalogItem['item_type'], $sheet->getCell("D{$excelRow}")->getValue(), $context.' jenis');
            $this->assertEqualsWithDelta((float) $row['quantity'], (float) $sheet->getCell("E{$excelRow}")->getValue(), 0.0001, $context.' jumlah');
            $this->assertSame($row['unit'], $sheet->getCell("F{$excelRow}")->getValue(), $context.' satuan');
            $this->assertSame($row['reason'], $sheet->getCell("G{$excelRow}")->getValue(), $context.' alasan');
            $this->assertSame('Petugas QA TPS', $sheet->getCell("H{$excelRow}")->getValue(), $context.' pelapor sintetis');
            if ($brandCode === 'JCHICKEN') {
                $this->assertSame($row['section'], $sheet->getCell("I{$excelRow}")->getValue(), $context.' section');
                $this->assertSame($row['category'], $sheet->getCell("J{$excelRow}")->getValue(), $context.' kategori');
                $this->assertSame($row['sm'], $sheet->getCell("K{$excelRow}")->getValue(), $context.' SM');
                $this->assertSame($row['audit'], $sheet->getCell("L{$excelRow}")->getValue(), $context.' AUDIT');
            } else {
                $this->assertSame($row['category'], $sheet->getCell("I{$excelRow}")->getValue(), $context.' kategori');
                $this->assertSame($row['audit'], $sheet->getCell("J{$excelRow}")->getValue(), $context.' AUDIT');
            }
        }
    }

    $expectedTotals = collect($rows)
        ->groupBy(fn (array $row): string => implode('|', [$row['category'], $row['item_code'], $row['unit']]))
        ->map(fn ($group): float => round($group->sum(fn (array $row): float => (float) $row['quantity']), 4))
        ->sortKeys()->all();
    $actualTotals = $export->summaryRows()
        ->mapWithKeys(fn (array $row): array => [implode('|', [$row[2], $row[3], $row[5]]) => (float) $row[6]])
        ->sortKeys()->all();
    $this->assertSame($expectedTotals, $actualTotals);
})->with([
    'Jchicken September 2026' => ['JCHICKEN', 122],
    'Luuca September 2026'    => ['LUUCA', 74],
    'Momoyo September 2026'   => ['MOMOYO', 107],
]);
