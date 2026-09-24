<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteReportResource\Pages\ListWasteReports;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Webkul\Security\Models\User;

beforeEach(function (): void {
    Route::get('/_test/waste-reports', fn (): string => '')->name('filament.admin.resources.waste-reports.index');
    Route::get('/_test/waste-reports/create', fn (): string => '')->name('filament.admin.resources.waste-reports.create');
    Route::get('/_test/waste-reports/{record}', fn (): string => '')->name('filament.admin.resources.waste-reports.view');
    Route::get('/_test/waste-reports/{record}/edit', fn (): string => '')->name('filament.admin.resources.waste-reports.edit');
});

it('scopes export choices to accessible brands and the selected brand outlet', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$jchicken, $jchickenOutlet] = wasteExportActionCatalog('JCHICKEN');
    [$luuca, $luucaOutlet] = wasteExportActionCatalog('LUUCA');
    [$momoyo, $momoyoOutlet] = wasteExportActionCatalog('MOMOYO');
    $jchicken->users()->attach($user);
    $luuca->users()->attach($user);

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $component = Livewire::test(ListWasteReports::class)
        ->assertActionExists('export')
        ->mountAction('export');
    $schema = $component->instance()->getSchema('mountedActionSchema0');
    $brandField = collect($schema->getComponents())->first(fn ($field): bool => $field->getName() === 'brand_id');
    $outletField = collect($schema->getComponents())->first(fn ($field): bool => $field->getName() === 'outlet_id');

    expect($brandField)->toBeInstanceOf(Select::class)
        ->and(array_keys($brandField->getOptions()))->toEqualCanonicalizing([$jchicken->id, $luuca->id])
        ->and(array_keys($outletField->getOptions()))->toEqualCanonicalizing([$jchickenOutlet->id, $luucaOutlet->id])
        ->and($brandField->getOptions())->not->toHaveKey($momoyo->id)
        ->and($outletField->getOptions())->not->toHaveKey($momoyoOutlet->id);

    $component->set('mountedActions.0.data.brand_id', $jchicken->id);
    $outletField = collect($component->instance()->getSchema('mountedActionSchema0')->getComponents())
        ->first(fn ($field): bool => $field->getName() === 'outlet_id');
    expect(array_keys($outletField->getOptions()))->toBe([$jchickenOutlet->id]);

    $component->set('mountedActions.0.data.outlet_id', $jchickenOutlet->id)
        ->set('mountedActions.0.data.brand_id', $luuca->id)
        ->assertSet('mountedActions.0.data.outlet_id', null);
    $outletField = collect($component->instance()->getSchema('mountedActionSchema0')->getComponents())
        ->first(fn ($field): bool => $field->getName() === 'outlet_id');
    expect(array_keys($outletField->getOptions()))->toBe([$luucaOutlet->id]);
});

it('starts export with the month and outlet already selected in the report list', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet] = wasteExportActionCatalog('JCHICKEN');
    $brand->users()->attach($user);

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ListWasteReports::class)
        ->filterTable('month', ['month' => 7, 'year' => 2026])
        ->filterTable('brand_id', $brand->id)
        ->filterTable('outlet_id', $outlet->id)
        ->mountAction('export')
        ->assertSet('mountedActions.0.data.month', 7)
        ->assertSet('mountedActions.0.data.year', 2026)
        ->assertSet('mountedActions.0.data.brand_id', $brand->id)
        ->assertSet('mountedActions.0.data.outlet_id', $outlet->id);
});

it('rejects invalid export selections and warns when valid filters have no reports', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$jchicken, $jchickenOutlet] = wasteExportActionCatalog('JCHICKEN');
    [, $luucaOutlet] = wasteExportActionCatalog('LUUCA');
    $jchicken->users()->attach($user);

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $luucaOutlet->brand_id,
            'outlet_id' => $luucaOutlet->id,
            'month'     => 9,
            'year'      => 2026,
        ])
        ->assertHasFormErrors(['brand_id']);

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $jchicken->id,
            'outlet_id' => $luucaOutlet->id,
            'month'     => 9,
            'year'      => 2026,
        ])
        ->assertHasFormErrors(['outlet_id']);

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $jchicken->id,
            'outlet_id' => $jchickenOutlet->id,
            'month'     => 13,
            'year'      => 2026,
        ])
        ->assertHasFormErrors(['month']);

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $jchicken->id,
            'outlet_id' => $jchickenOutlet->id,
            'month'     => 9,
            'year'      => 2026,
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Belum ada laporan disetujui untuk bulan dan outlet terpilih.');
});

it('downloads only approved incidents inside the selected calendar month', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet] = wasteExportActionCatalog('JCHICKEN');
    $brand->users()->attach($user);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001', 'name' => 'Chicken',
        'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    $payload = [
        'brand_id'       => $brand->id,
        'outlet_id'      => $outlet->id,
        'event_date'     => '2026-09-01',
        'reporter_name'  => 'Field User',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Awal September',
            'lines'       => [['item_id' => $item->id, 'quantity' => '2']],
        ]],
    ];
    $service = app(WasteReportService::class);
    $firstReport = $service->saveByAdmin($payload, null, $user);
    expect($firstReport->status)->toBe(WasteReportStatus::Pending);
    wasteExportActionApprove($firstReport, $user);
    foreach ([
        ['2026-07-31', 'Akhir Juli', 'approved'],
        ['2026-08-31', 'Akhir Agustus', 'approved'],
        ['2026-09-15', 'Belum disetujui', 'pending'],
        ['2026-09-30', 'Akhir September', 'approved'],
        ['2026-10-01', 'Awal Oktober', 'approved'],
    ] as [$date, $reason, $status]) {
        $nextPayload = $payload;
        $nextPayload['event_date'] = $date;
        $nextPayload['events'][0]['reason'] = $reason;
        $nextReport = $service->saveByAdmin($nextPayload, null, $user);
        if ($status === 'approved') {
            wasteExportActionApprove($nextReport, $user);
        }
    }

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    Excel::fake();
    Excel::matchByRegex();

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $brand->id,
            'outlet_id' => $outlet->id,
            'month'     => 9,
            'year'      => 2026,
        ])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();

    Excel::assertDownloaded('/^waste-bulanan-2026-09-.*\.xlsx$/', fn (WasteReportExport $export): bool => $export->detailRows()
        ->pluck(9)
        ->all() === ['Awal September', 'Akhir September']);

    Livewire::test(ListWasteReports::class)
        ->callAction('export', data: [
            'brand_id'  => $brand->id,
            'outlet_id' => $outlet->id,
            'month'     => 7,
            'year'      => 2026,
        ])
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();

    Excel::assertDownloaded('/^waste-bulanan-2026-07-.*\.xlsx$/', fn (WasteReportExport $export): bool => $export->detailRows()
        ->pluck(9)
        ->all() === ['Akhir Juli']);
});

it('filters the admin report list to the selected calendar month', function (): void {
    $user = UserFactory::new()->createQuietly();
    [$brand, $outlet] = wasteExportActionCatalog('JCHICKEN');
    $brand->users()->attach($user);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001', 'name' => 'Chicken',
        'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    $reports = collect(['2026-08-31', '2026-09-01', '2026-09-30', '2026-10-01'])
        ->map(fn (string $date) => app(WasteReportService::class)->saveByAdmin([
            'brand_id'      => $brand->id, 'outlet_id' => $outlet->id,
            'event_date'    => $date,
            'reporter_name' => 'Field User', 'reporter_phone' => '081234567890',
            'events'        => [[
                'category_id' => $category->id, 'reason' => $date,
                'lines'       => [['item_id' => $item->id, 'quantity' => '2']],
            ]],
        ], null, $user));

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ListWasteReports::class)
        ->filterTable('month', ['month' => 9, 'year' => 2026])
        ->assertCanSeeTableRecords($reports->slice(1, 2))
        ->assertCanNotSeeTableRecords(collect([$reports->first(), $reports->last()]));
});

/** @return array{WasteBrand, WasteOutlet} */
function wasteExportActionCatalog(string $code): array
{
    $brand = WasteBrand::query()->create(['name' => ucfirst(strtolower($code)), 'code' => $code, 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id'  => $brand->id,
        'name'      => 'Ciledug',
        'code'      => 'CILEDUG',
        'slug'      => strtolower($code).'-ciledug',
        'timezone'  => 'Asia/Jakarta',
        'is_active' => true,
    ]);

    return [$brand, $outlet];
}

function wasteExportActionApprove(WasteReport $report, User $reviewer): WasteReport
{
    foreach ($report->latestVersion->events as $event) {
        foreach ($event->lines as $line) {
            $line->update(['sm_checked' => false, 'audit_checked' => false]);
        }
    }

    return app(WasteMisReviewService::class)->approve($report, $reviewer);
}
