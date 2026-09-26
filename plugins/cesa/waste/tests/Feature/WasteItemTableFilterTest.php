<?php

use Cesa\Waste\Filament\Resources\WasteItemResource\Pages\ManageWasteItems;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('filters the item table by active state, brand, and jenis', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $otherBrand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $brand->users()->attach($user);
    $otherBrand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $active = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'A1', 'name' => 'Active tea',
        'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
        'notes'    => 'Diimpor dari master sumber.',
    ]);
    $inactive = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'A2', 'name' => 'Wrong item',
        'unit'     => 'GR', 'item_type' => 'others', 'is_active' => false,
        'notes'    => 'Dinonaktifkan karena penanda SALAH pada master sumber.',
    ]);
    $noType = WasteItem::query()->create([
        'brand_id' => $brand->id, 'code' => 'A3', 'name' => 'Untyped item',
        'unit'     => 'GR', 'item_type' => null, 'is_active' => true,
    ]);
    $foreign = WasteItem::query()->create([
        'brand_id' => $otherBrand->id, 'code' => 'B1', 'name' => 'Other brand item',
        'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);

    Livewire::test(ManageWasteItems::class)
        ->assertCanSeeTableRecords([$active, $inactive, $noType, $foreign])
        ->assertSee('Catatan');

    Livewire::test(ManageWasteItems::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active, $noType, $foreign])
        ->assertCanNotSeeTableRecords([$inactive]);

    Livewire::test(ManageWasteItems::class)
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active, $noType, $foreign]);

    Livewire::test(ManageWasteItems::class)
        ->filterTable('brand', $brand->id)
        ->assertCanSeeTableRecords([$active, $inactive, $noType])
        ->assertCanNotSeeTableRecords([$foreign]);

    Livewire::test(ManageWasteItems::class)
        ->filterTable('item_type', 'bahan baku')
        ->assertCanSeeTableRecords([$active, $foreign])
        ->assertCanNotSeeTableRecords([$inactive, $noType]);

    Livewire::test(ManageWasteItems::class)
        ->filterTable('item_type', '')
        ->assertCanSeeTableRecords([$noType])
        ->assertCanNotSeeTableRecords([$active, $inactive, $foreign]);
});
