<?php

use Cesa\Waste\Filament\Resources\WasteItemResource;
use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Filament\Resources\WasteUnitResource\Pages\ManageWasteUnits;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Policies\WasteUnitPolicy;
use Cesa\Waste\Services\WasteMasterImportService;
use Database\Factories\UserFactory;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Webkul\Security\Models\Permission;
use Webkul\Security\PermissionRegistrar;

beforeEach(function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('backfills each existing item unit without changing the items', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_09_24_080109_create_waste_units_table.php';
    $migration->down();

    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    foreach (['GR', 'PCS', 'GR', null] as $index => $unit) {
        WasteItem::query()->create([
            'brand_id'  => $brand->id,
            'code'      => 'BB-'.$index,
            'name'      => 'Barang '.$index,
            'unit'      => $unit,
            'is_active' => true,
        ]);
    }

    $migration->up();

    expect(WasteUnit::query()->orderBy('code')->pluck('code')->all())->toBe(['GR', 'PCS'])
        ->and(WasteItem::query()->orderBy('code')->pluck('unit')->all())->toBe(['GR', 'PCS', 'GR', null]);
});

it('registers normalized imported units once without reactivating retired units', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => false]);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Master data');
    $sheet->fromArray([
        ['Nama Item', 'Kode Item', 'Unit', 'Harga pokok', 'KELOMPOK', 'JENIS'],
        ['Beras', 'B001', 'gram', null, 'B001', 'bahan baku'],
        ['Minyak', 'B002', 'ML', null, 'B002', 'bahan baku'],
        ['Es krim', 'B003', 'GR.', null, 'B003', 'produk'],
        ['Tanpa satuan', 'B004', null, null, 'B004', 'bahan baku'],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'waste-unit-master-');
    (new Xlsx($spreadsheet))->save($path);

    try {
        $importer = app(WasteMasterImportService::class);
        $importer->import('JCHICKEN', $path);
        $importer->import('JCHICKEN', $path);
    } finally {
        @unlink($path);
        $spreadsheet->disconnectWorksheets();
    }

    expect(WasteUnit::query()->orderBy('code')->pluck('code')->all())->toBe(['GR', 'ML'])
        ->and((bool) WasteUnit::query()->where('code', 'GR')->value('is_active'))->toBeFalse()
        ->and((bool) WasteUnit::query()->where('code', 'ML')->value('is_active'))->toBeTrue()
        ->and(WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B001')->value('unit'))->toBe('GR')
        ->and(WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B003')->value('unit'))->toBe('GR')
        ->and((bool) WasteItem::query()->where('brand_id', $brand->id)->where('code', 'B004')->value('is_active'))->toBeFalse();
});

it('retains each Momoyo master unit label alongside its canonical unit code', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Master data');
    $sheet->fromArray([
        ['Nama Barang', 'Kode Item', 'Unit', 'Jenis'],
        [null, null, null, null],
        ['Black Tea', 'BB-000001', 'Gram', 'Bahan Baku'],
        ['Oolong Tea', 'BB-000075', 'gram', 'Bahan Baku'],
        ['Cup', 'BB-000099', 'Pcs', 'Bahan Baku'],
        ['Water', 'BB-000100', 'Ml', 'Bahan Baku'],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'waste-momoyo-units-');
    (new Xlsx($spreadsheet))->save($path);

    try {
        app(WasteMasterImportService::class)->import('MOMOYO', $path);
    } finally {
        @unlink($path);
        $spreadsheet->disconnectWorksheets();
    }

    $items = WasteItem::query()->where('brand_id', $brand->id)->get()->keyBy('code');

    expect($items['BB-000001']->unit)->toBe('GR')
        ->and($items['BB-000001']->source_unit_label)->toBe('Gram')
        ->and($items['BB-000075']->unit)->toBe('GR')
        ->and($items['BB-000075']->source_unit_label)->toBe('gram')
        ->and($items['BB-000099']->unit)->toBe('PCS')
        ->and($items['BB-000099']->source_unit_label)->toBe('Pcs')
        ->and($items['BB-000100']->unit)->toBe('ML')
        ->and($items['BB-000100']->source_unit_label)->toBe('Ml');
});

it('offers active units on new items and preserves a retired unit when editing its item', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => true]);
    WasteUnit::query()->create(['code' => 'PRS', 'name' => 'Porsi', 'is_active' => false]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'BB-1',
        'name'      => 'Barang lama',
        'unit'      => 'PRS',
        'is_active' => true,
    ]);

    $newItemUnitField = collect(WasteItemResource::form(Schema::make())->getComponents())
        ->first(fn ($component): bool => $component->getName() === 'unit');
    $existingItemUnitField = collect(WasteItemResource::form(Schema::make()->record($item))->getComponents())
        ->first(fn ($component): bool => $component->getName() === 'unit');

    expect($newItemUnitField)->toBeInstanceOf(Select::class)
        ->and(array_keys($newItemUnitField->getOptions()))->toBe(['GR'])
        ->and($existingItemUnitField)->toBeInstanceOf(Select::class)
        ->and(array_keys($existingItemUnitField->getOptions()))->toBe(['GR', 'PRS']);
});

it('accepts a master unit and rejects a forged unit code when an admin creates an item', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $brand->users()->attach($user);
    WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => true]);

    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ManageWasteItems::class)
        ->callAction(CreateAction::class, data: [
            'brand_id'  => $brand->id,
            'name'      => 'Barang sah',
            'code'      => 'BB-99',
            'unit'      => 'GR',
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    Livewire::test(ManageWasteItems::class)
        ->callAction(CreateAction::class, data: [
            'brand_id'  => $brand->id,
            'name'      => 'Item palsu',
            'code'      => 'BB-100',
            'unit'      => 'UNKNOWN',
            'is_active' => true,
        ])
        ->assertHasFormErrors(['unit']);

    expect(WasteItem::query()->where('code', 'BB-99')->value('unit'))->toBe('GR')
        ->and(WasteItem::query()->where('code', 'BB-100')->exists())->toBeFalse();
});

it('lets a global waste admin create and deactivate a unit', function (): void {
    $this->artisan('migrate', [
        '--path'           => 'database/migrations/2024_11_04_132945_create_permission_tables.php',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = UserFactory::new()->createQuietly();
    $user->givePermissionTo(Permission::findOrCreate('view_any_waste_waste::report', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ManageWasteUnits::class)
        ->callAction(CreateAction::class, data: [
            'code'      => 'L',
            'name'      => 'Liter',
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $unit = WasteUnit::query()->where('code', 'L')->firstOrFail();

    Livewire::test(ManageWasteUnits::class)
        ->callAction(TestAction::make(EditAction::class)->table($unit), data: [
            'name'      => 'Liter',
            'is_active' => false,
        ])
        ->assertHasNoFormErrors();

    expect($unit->fresh()->is_active)->toBeFalse();
});

it('does not let a brand manager change or delete a shared unit', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $brand->users()->attach($user);
    $unit = WasteUnit::query()->create(['code' => 'GR', 'name' => 'Gram', 'is_active' => true]);

    expect(app(WasteUnitPolicy::class)->create($user))->toBeFalse()
        ->and(app(WasteUnitPolicy::class)->update($user, $unit))->toBeFalse()
        ->and(app(WasteUnitPolicy::class)->delete($user, $unit))->toBeFalse();
});
