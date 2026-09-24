<?php

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Cesa\Waste\Database\Seeders\DatabaseSeeder;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Policies\WasteBrandPolicy;
use Cesa\Waste\Policies\WasteReportPolicy;
use Cesa\Waste\Services\WasteAccessService;
use Database\Factories\UserFactory;
use Webkul\PluginManager\PermissionManager;
use Webkul\Security\Models\Permission;
use Webkul\Security\PermissionRegistrar;

it('grants global waste access through the permission generated during installation', function (): void {
    $this->artisan('migrate', [
        '--path'           => 'database/migrations/2024_11_04_132945_create_permission_tables.php',
        '--no-interaction' => true,
    ])->assertSuccessful();

    app(PermissionManager::class)->managePermissions();
    $permissions = FilamentShield::getDefaultPermissionKeys(WasteReportResource::class, ['viewAny']);
    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $access = app(WasteAccessService::class);

    expect($access->canManageBrand($user, $brand))->toBeFalse()
        ->and(app(WasteBrandPolicy::class)->create($user))->toBeFalse();

    $user->givePermissionTo(Permission::findOrCreate($permissions['viewAny']['key'], 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($access->canManageBrand($user, $brand))->toBeTrue()
        ->and($access->scopeBrands(WasteBrand::query(), $user)->pluck('id')->all())->toBe([$brand->id])
        ->and(app(WasteBrandPolicy::class)->create($user))->toBeTrue()
        ->and(app(WasteReportPolicy::class)->viewAny($user))->toBeTrue();
});

it('seeds brand references without activating invented items or approvers', function (): void {
    config(['waste.master_sources' => []]);

    $this->seed(DatabaseSeeder::class);

    expect(WasteBrand::query()->count())->toBe(3)
        ->and(WasteItem::query()->count())->toBe(0)
        ->and(WasteWorkflow::query()->count())->toBe(0);
});

it('registers the item type snapshot migration for installed sites', function (): void {
    expect(collect(app('migrator')->paths())->contains(
        fn (string $path): bool => str_contains($path, '2026_09_24_081340_add_item_type_to_waste_event_lines_table.php'),
    ))->toBeTrue();
});
