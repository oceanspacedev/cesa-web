<?php

use Cesa\Waste\Filament\Resources\WasteOutletResource\Pages\ManageWasteOutlets;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Models\WasteWorkflow;
use Database\Factories\UserFactory;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }
});

it('fills codes, slugs, and approval names from the names an admin types', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'is_active' => true]);
    $section = WasteSection::query()->create(['brand_id' => $brand->id, 'name' => 'Dining', 'is_active' => true]);
    $category = WasteCategory::query()->create(['brand_id' => $brand->id, 'name' => 'Test food/Kalibrasi', 'is_active' => true]);
    $unit = WasteUnit::query()->create(['name' => 'Gram', 'is_active' => true]);
    $workflow = WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'outlet_id' => $outlet->id,
        'steps'     => [['label' => 'Supervisor', 'name' => 'Ayu']],
        'is_active' => true,
    ]);
    $duplicate = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'is_active' => true]);
    $custom = WasteOutlet::query()->create([
        'brand_id' => $brand->id,
        'name'     => 'Serpong',
        'code'     => 'SRP',
        'slug'     => 'momoyo-serpong-khusus',
        'is_active'=> true,
    ]);

    expect($brand->code)->toBe('MOMOYO')
        ->and($outlet->code)->toBe('CILEDUG')
        ->and($outlet->slug)->toBe('momoyo-ciledug')
        ->and($outlet->timezone)->toBe('Asia/Jakarta')
        ->and($section->code)->toBe('DINING')
        ->and($category->code)->toBe('TEST_FOOD_KALIBRASI')
        ->and($unit->code)->toBe('GR')
        ->and($workflow->name)->toBe('Persetujuan Ciledug')
        ->and($duplicate->code)->toBe('CILEDUG_2')
        ->and($duplicate->slug)->toBe('momoyo-ciledug-2')
        ->and($custom->code)->toBe('SRP')
        ->and($custom->slug)->toBe('momoyo-serpong-khusus');
});

it('creates an outlet from the form without asking for a code or slug', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    Livewire::test(ManageWasteOutlets::class)
        ->callAction(CreateAction::class, data: [
            'brand_id'  => $brand->id,
            'name'      => 'Ciledug',
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $outlet = WasteOutlet::query()->where('name', 'Ciledug')->first();

    expect($outlet)->not->toBeNull()
        ->and($outlet->code)->toBe('CILEDUG')
        ->and($outlet->slug)->toBe('momoyo-ciledug');
});
