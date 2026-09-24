<?php

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Cesa\Waste\Filament\Pages\WasteDashboard;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Policies\WasteBrandPolicy;
use Cesa\Waste\Policies\WasteCategoryPolicy;
use Cesa\Waste\Policies\WasteItemPolicy;
use Cesa\Waste\Policies\WasteOutletPolicy;
use Cesa\Waste\Policies\WasteReportPolicy;
use Cesa\Waste\Policies\WasteSectionPolicy;
use Cesa\Waste\Policies\WasteWorkflowPolicy;
use Cesa\Waste\Services\WasteAccessService;
use Database\Factories\UserFactory;
use Illuminate\Validation\ValidationException;
use Webkul\PluginManager\PermissionManager;
use Webkul\Security\Models\Permission;
use Webkul\Security\PermissionRegistrar;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path'           => 'database/migrations/2024_11_04_132945_create_permission_tables.php',
        '--no-interaction' => true,
    ])->assertSuccessful();
    app(PermissionManager::class)->managePermissions();
});

it('allows a global manager to open and initialize empty waste resources', function (): void {
    $user = UserFactory::new()->createQuietly();
    $permissions = FilamentShield::getDefaultPermissionKeys(WasteReportResource::class, ['viewAny']);
    $user->givePermissionTo(Permission::findOrCreate($permissions['viewAny']['key'], 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($user);

    expect(WasteBrand::query()->count())->toBe(0)
        ->and(app(WasteAccessService::class)->canManageAnyBrand($user))->toBeTrue()
        ->and(app(WasteReportPolicy::class)->viewAny($user))->toBeTrue()
        ->and(WasteDashboard::canAccess())->toBeTrue();

    foreach ([WasteBrandPolicy::class, WasteOutletPolicy::class, WasteItemPolicy::class, WasteSectionPolicy::class, WasteCategoryPolicy::class, WasteWorkflowPolicy::class] as $policy) {
        expect(app($policy)->viewAny($user))->toBeTrue()
            ->and(app($policy)->create($user))->toBeTrue();
    }
});

it('allows assigned brand managers to initialize workflows and view empty reporting', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $brand->users()->attach($user);
    $this->actingAs($user);

    expect(app(WasteAccessService::class)->canManageAnyBrand($user))->toBeTrue()
        ->and(app(WasteBrandPolicy::class)->viewAny($user))->toBeTrue()
        ->and(app(WasteBrandPolicy::class)->create($user))->toBeFalse()
        ->and(app(WasteReportPolicy::class)->viewAny($user))->toBeTrue()
        ->and(WasteDashboard::canAccess())->toBeTrue();

    foreach ([WasteOutletPolicy::class, WasteItemPolicy::class, WasteSectionPolicy::class, WasteCategoryPolicy::class, WasteWorkflowPolicy::class] as $policy) {
        expect(app($policy)->viewAny($user))->toBeTrue()
            ->and(app($policy)->create($user))->toBeTrue();
    }
});

it('allows outlet managers to view empty reporting without managing brand masters', function (): void {
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'ciledug']);
    $outlet->users()->attach($user);
    $this->actingAs($user);

    expect(app(WasteAccessService::class)->canManageAnyBrand($user))->toBeFalse()
        ->and(app(WasteOutletPolicy::class)->viewAny($user))->toBeTrue()
        ->and(app(WasteOutletPolicy::class)->create($user))->toBeFalse()
        ->and(app(WasteReportPolicy::class)->viewAny($user))->toBeTrue()
        ->and(WasteDashboard::canAccess())->toBeTrue();

    foreach ([WasteBrandPolicy::class, WasteItemPolicy::class, WasteSectionPolicy::class, WasteCategoryPolicy::class, WasteWorkflowPolicy::class] as $policy) {
        expect(app($policy)->viewAny($user))->toBeFalse()
            ->and(app($policy)->create($user))->toBeFalse();
    }
});

it('limits laporan, dasbor, and pengaturan to the assigned brand', function (): void {
    $user = UserFactory::new()->createQuietly();
    $permissions = FilamentShield::getDefaultPermissionKeys(WasteReportResource::class, ['viewAny']);
    $user->givePermissionTo(Permission::findOrCreate($permissions['viewAny']['key'], 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $momoyo = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $jchicken = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $momoyo->users()->attach($user);
    $momoyoOutlet = WasteOutlet::query()->create(['brand_id' => $momoyo->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'momoyo-ciledug']);
    $jchickenOutlet = WasteOutlet::query()->create(['brand_id' => $jchicken->id, 'name' => 'Ciledug', 'code' => 'CILEDUG', 'slug' => 'jchicken-ciledug']);
    $momoyoReport = WasteReport::query()->create([
        'uid'        => 'momoyo-report', 'brand_id' => $momoyo->id, 'outlet_id' => $momoyoOutlet->id,
        'event_date' => '2026-09-23', 'reporter_name' => 'A', 'reporter_phone' => '081', 'status' => 'approved',
    ]);
    $jchickenReport = WasteReport::query()->create([
        'uid'        => 'jchicken-report', 'brand_id' => $jchicken->id, 'outlet_id' => $jchickenOutlet->id,
        'event_date' => '2026-09-23', 'reporter_name' => 'B', 'reporter_phone' => '082', 'status' => 'approved',
    ]);
    $this->actingAs($user);

    $access = app(WasteAccessService::class);

    expect($access->scopeReports(WasteReport::query(), $user)->pluck('id')->all())->toBe([$momoyoReport->id])
        ->and($access->scopeBrands(WasteBrand::query(), $user)->pluck('code')->all())->toBe(['MOMOYO'])
        ->and(app(WasteReportPolicy::class)->view($user, $momoyoReport))->toBeTrue()
        ->and(app(WasteReportPolicy::class)->update($user, $momoyoReport))->toBeTrue()
        ->and(app(WasteReportPolicy::class)->view($user, $jchickenReport))->toBeFalse()
        ->and(app(WasteReportPolicy::class)->delete($user, $jchickenReport))->toBeFalse()
        ->and(WasteDashboard::canAccess())->toBeTrue();
});

it('denies waste navigation and master creation to unassigned users', function (): void {
    $user = UserFactory::new()->createQuietly();
    WasteCategory::query()->create(['name' => 'Shared', 'code' => 'SHARED', 'is_active' => true]);
    $this->actingAs($user);

    expect(app(WasteAccessService::class)->canManageAnyBrand($user))->toBeFalse()
        ->and(app(WasteReportPolicy::class)->viewAny($user))->toBeFalse()
        ->and(WasteDashboard::canAccess())->toBeFalse();

    foreach ([WasteBrandPolicy::class, WasteOutletPolicy::class, WasteItemPolicy::class, WasteSectionPolicy::class, WasteCategoryPolicy::class, WasteWorkflowPolicy::class] as $policy) {
        expect(app($policy)->viewAny($user))->toBeFalse()
            ->and(app($policy)->create($user))->toBeFalse();
    }
});

it('lets a momoyo manager crud only momoyo master data', function (): void {
    $user = UserFactory::new()->createQuietly();
    $momoyo = WasteBrand::query()->create(['name' => 'Momoyo', 'code' => 'MOMOYO', 'is_active' => true]);
    $jchicken = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $momoyo->users()->attach($user);
    $momoyoItem = WasteItem::query()->create(['brand_id' => $momoyo->id, 'code' => 'M1', 'name' => 'Tea', 'unit' => 'PCS', 'is_active' => true]);
    $jchickenItem = WasteItem::query()->create(['brand_id' => $jchicken->id, 'code' => 'J1', 'name' => 'Chicken', 'unit' => 'PCS', 'is_active' => true]);
    $momoyoSection = WasteSection::query()->create(['brand_id' => $momoyo->id, 'code' => 'BAR', 'name' => 'BAR', 'is_active' => true]);
    $jchickenSection = WasteSection::query()->create(['brand_id' => $jchicken->id, 'code' => 'COOK', 'name' => 'COOK', 'is_active' => true]);
    $this->actingAs($user);

    $access = app(WasteAccessService::class);

    expect($access->scopeBrands(WasteBrand::query(), $user)->pluck('code')->all())->toBe(['MOMOYO'])
        ->and($access->scopeItems(WasteItem::query(), $user)->pluck('id')->all())->toBe([$momoyoItem->id])
        ->and(app(WasteItemPolicy::class)->update($user, $momoyoItem))->toBeTrue()
        ->and(app(WasteItemPolicy::class)->delete($user, $momoyoItem))->toBeTrue()
        ->and(app(WasteItemPolicy::class)->view($user, $jchickenItem))->toBeFalse()
        ->and(app(WasteItemPolicy::class)->delete($user, $jchickenItem))->toBeFalse()
        ->and(app(WasteSectionPolicy::class)->update($user, $momoyoSection))->toBeTrue()
        ->and(app(WasteSectionPolicy::class)->delete($user, $jchickenSection))->toBeFalse()
        ->and(app(WasteBrandPolicy::class)->create($user))->toBeFalse()
        ->and(app(WasteBrandPolicy::class)->delete($user, $momoyo))->toBeFalse();

    $momoyoItem->update(['name' => 'Black Tea']);

    expect($momoyoItem->fresh()->name)->toBe('Black Tea')
        ->and(fn () => $jchickenItem->update(['name' => 'Diubah']))->toThrow(ValidationException::class);
});
