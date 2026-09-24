<?php

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Exports\WasteReportExport;
use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteMisReviewService;
use Cesa\Waste\Services\WasteReportService;
use Database\Factories\UserFactory;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('lets an item manager approve alternate units from the active shared master', function (): void {
    [$brand] = wasteAlternateUnitCatalog();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $milliliters = WasteUnit::query()->where('code', 'ML')->firstOrFail();
    $inactiveUnit = WasteUnit::query()->where('code', 'L')->firstOrFail();

    Livewire::test(ManageWasteItems::class)
        ->callAction(CreateAction::class, data: [
            'brand_id'       => $brand->id,
            'name'           => 'Saus',
            'code'           => 'B001-777',
            'unit'           => 'GR',
            'alternateUnits' => [$milliliters->id],
            'is_active'      => true,
        ])
        ->assertHasNoFormErrors();

    $item = WasteItem::query()->where('code', 'B001-777')->firstOrFail();

    expect($item->alternateUnits()->pluck('code')->all())->toBe(['ML']);

    Livewire::test(ManageWasteItems::class)
        ->callAction(CreateAction::class, data: [
            'brand_id'       => $brand->id,
            'name'           => 'Barang tak sah',
            'code'           => 'B001-778',
            'unit'           => 'GR',
            'alternateUnits' => [$inactiveUnit->id],
            'is_active'      => true,
        ])
        ->assertHasFormErrors(['alternateUnits.0']);

    expect(WasteItem::query()->where('code', 'B001-778')->exists())->toBeFalse();

    Livewire::test(ManageWasteItems::class)
        ->callAction(TestAction::make(EditAction::class)->table($item), data: [
            'alternateUnits' => [],
        ])
        ->assertHasNoFormErrors();

    expect($item->fresh()->alternateUnits()->count())->toBe(0);
});

it('allows a public line to use an approved unit and exports that exact unit', function (): void {
    [$brand, $outlet, $item, $category] = wasteAlternateUnitCatalog();

    $component = Livewire::test(PublicWasteReportForm::class, ['brand' => 'jchicken', 'outlet' => $outlet->slug]);
    expect($component->get('itemUnitOptions')[$item->id])->toBe(['GR', 'ML']);

    $component
        ->set('data.event_date', '2026-09-02')
        ->set('data.reporter_name', 'Petugas Ciledug')
        ->set('data.reporter_phone', '081234567890')
        ->call('nextStep')
        ->set('data.events.0.category_id', $category->id)
        ->set('data.events.0.reason', 'Saus tumpah')
        ->set('data.events.0.lines.0.item_id', $item->id)
        ->set('data.events.0.lines.0.quantity', '72,6')
        ->set('data.events.0.lines.0.unit', 'ML')
        ->set('photos.0.0', UploadedFile::fake()->image('bukti.jpg'))
        ->call('submit')
        ->assertHasNoErrors();

    $report = WasteReport::query()->sole();
    $line = $report->latestVersion->events->first()->lines->sole();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $templateRows = (new WasteReportExport('2026-09-01', '2026-09-30', 'all', $brand->id, $outlet->id, $user))
        ->sheets()[0]
        ->collection();

    expect($line->unit)->toBe('ML')
        ->and($line->quantity)->toBe('72.6000')
        ->and($templateRows[6][5])->toBe('ML')
        ->and($templateRows[6][4])->toBe(72.6);
});

it('keeps the same item in separate monthly totals when its selected units differ', function (): void {
    [$brand, $outlet, $item, $category] = wasteAlternateUnitCatalog();
    $reviewer = UserFactory::new()->createQuietly();
    $brand->users()->attach($reviewer);
    $payload = wasteAlternateUnitPayload($item, $category, 'GR');
    $payload['brand_id'] = $brand->id;
    $payload['outlet_id'] = $outlet->id;
    $payload['events'][0]['lines'] = [
        ['item_id' => $item->id, 'quantity' => '200', 'unit' => 'GR', 'sm_checked' => '1', 'audit_checked' => '1'],
        ['item_id' => $item->id, 'quantity' => '75', 'unit' => 'ML', 'sm_checked' => '0', 'audit_checked' => '1'],
    ];
    $report = app(WasteReportService::class)->saveByAdmin($payload, null, $reviewer);
    app(WasteMisReviewService::class)->approve($report, $reviewer);

    $summaryRows = (new WasteReportExport('2026-09-01', '2026-09-30', 'approved', $brand->id, $outlet->id, $reviewer))
        ->summaryRows()->mapWithKeys(fn (array $row): array => [$row[5] => $row[6]])->all();

    expect($summaryRows)->toBe(['GR' => 200.0, 'ML' => 75.0]);
});

it('rejects units not approved for the specific item or deactivated in the master', function (string $unit): void {
    [$brand, $outlet, $item, $category] = wasteAlternateUnitCatalog();

    expect(fn (): array => app(WasteReportService::class)->submit(
        $brand,
        $outlet,
        wasteAlternateUnitPayload($item, $category, $unit),
        [0 => [UploadedFile::fake()->image('bukti.jpg')]],
    ))->toThrow(ValidationException::class);

    expect(WasteReport::query()->count())->toBe(0);
})->with(['approved for another item' => ['PCS'], 'inactive alternative' => ['L'], 'unknown unit' => ['ZZZ']]);

it('keeps the selected alternate unit when an admin corrects a report', function (): void {
    [$brand, $outlet, $item, $category] = wasteAlternateUnitCatalog();
    $user = UserFactory::new()->createQuietly();
    $brand->users()->attach($user);
    $service = app(WasteReportService::class);

    $data = wasteAlternateUnitPayload($item, $category, 'ML');
    $data['brand_id'] = $brand->id;
    $data['outlet_id'] = $outlet->id;
    $data['status'] = 'approved';

    $report = $service->saveByAdmin($data, null, $user);
    $data['events'][0]['lines'][0]['quantity'] = '3.5';
    $service->saveByAdmin($data, $report, $user);

    $line = $report->fresh()->latestVersion->events->first()->lines->sole();
    expect($line->unit)->toBe('ML')
        ->and($line->quantity)->toBe('3.5000');
});

it('prefills and retains the selected alternate unit when a rejected public report is revised', function (): void {
    [$brand, $outlet, $item, $category] = wasteAlternateUnitCatalog();
    $result = app(WasteReportService::class)->submit(
        $brand,
        $outlet,
        wasteAlternateUnitPayload($item, $category, 'ML'),
        [0 => [UploadedFile::fake()->image('bukti.jpg')]],
    );
    $report = $result['report'];
    $report->update(['status' => WasteReportStatus::Rejected]);
    $report->latestVersion->update(['status' => WasteReportStatus::Rejected]);

    Livewire::test(PublicWasteReportForm::class, [
        'brand'       => 'jchicken',
        'outlet'      => $outlet->slug,
        'manageToken' => $result['manage_token'],
    ])
        ->assertSet('data.events.0.lines.0.unit', 'ML')
        ->call('nextStep')
        ->set('data.events.0.lines.0.quantity', '4')
        ->call('submit')
        ->assertHasNoErrors();

    expect($report->fresh()->latestVersion->version_number)->toBe(2)
        ->and($report->fresh()->latestVersion->events->first()->lines->sole()->unit)->toBe('ML');
});

/**
 * @return array{WasteBrand, WasteOutlet, WasteItem, WasteCategory}
 */
function wasteAlternateUnitCatalog(): array
{
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001-100', 'name' => 'Saus',
        'unit'     => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->id, 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => true]);
    $milliliters = WasteUnit::query()->create(['code' => 'ML', 'name' => 'Milliliter', 'is_active' => true]);
    $pieces = WasteUnit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
    $inactiveUnit = WasteUnit::query()->create(['code' => 'L', 'name' => 'Liter', 'is_active' => false]);
    $item->alternateUnits()->attach([$milliliters->id, $inactiveUnit->id]);
    $otherItem = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'B001-101', 'name' => 'Kotak',
        'unit'     => 'GR', 'item_type' => 'Bahan Baku', 'is_active' => true,
    ]);
    $otherItem->alternateUnits()->attach($pieces->id);

    return [$brand, $outlet, $item, $category];
}

/**
 * @return array<string, mixed>
 */
function wasteAlternateUnitPayload(WasteItem $item, WasteCategory $category, string $unit): array
{
    return [
        'event_date'     => '2026-09-02',
        'reporter_name'  => 'Petugas Ciledug',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->id,
            'reason'      => 'Saus tumpah',
            'lines'       => [['item_id' => $item->id, 'quantity' => '2.5', 'unit' => $unit]],
        ]],
    ];
}
