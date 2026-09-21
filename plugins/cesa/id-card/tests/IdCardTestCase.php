<?php

namespace Cesa\IdCard\Tests;

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Cesa\IdCard\IdCardServiceProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesSqliteInMemoryDatabase;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;

abstract class IdCardTestCase extends TestCase
{
    use UsesSqliteInMemoryDatabase;

    protected function setUp(): void
    {
        $this->useSqliteInMemoryDatabase();

        Package::$plugins = [];

        parent::setUp();

        $this->withoutVite();

        foreach ([
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2024_11_04_132945_create_permission_tables.php',
            'database/migrations/2024_11_26_053234_add_resource_permission_column_to_users_table.php',
            'plugins/webkul/security/database/migrations/2024_11_12_125715_create_teams_table.php',
            'plugins/webkul/security/database/migrations/2024_11_12_130019_create_user_team_table.php',
            'plugins/webkul/plugin-manager/database/migrations/2024_11_05_105102_create_plugins_table.php',
            'plugins/cesa/id-card/database/migrations',
        ] as $migrationPath) {
            $this->artisan('migrate', [
                '--path'           => $migrationPath,
                '--no-interaction' => true,
            ])->assertSuccessful();
        }

        Plugin::query()->create([
            'name'         => 'id-card',
            'author'       => 'tests',
            'summary'      => 'tests',
            'description'  => 'tests',
            'is_active'    => true,
            'is_installed' => true,
        ]);

        Package::$plugins = [];

        $this->app->register(IdCardServiceProvider::class, true);

        $panel = Filament::getPanel('admin');
        $panel->resources([IdCardRequestResource::class]);
        Filament::setCurrentPanel($panel);

        Route::prefix('admin')->name('filament.admin.')->group(function () use ($panel): void {
            IdCardRequestResource::registerRoutes($panel);
        });

        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        $this->app['url']->setRoutes(Route::getRoutes());

        Storage::fake('local');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Package::$plugins = [];

        parent::tearDown();
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function createIdCardUser(array $permissions = [], PermissionType $scope = PermissionType::GLOBAL): User
    {
        $baseUser = User::factory()->createQuietly(['resource_permission' => $scope->value]);
        $user = User::query()->findOrFail($baseUser->getKey());

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission.'_id_card_id::card::request', 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * @return array<string, string>
     */
    protected function idCardFormData(): array
    {
        return [
            'full_name'        => 'Andi Saputra',
            'shipping_address' => 'Jl. Merdeka No. 10, Bandung',
            'business_entity'  => 'smi',
            'position'         => 'sales',
            'phone'            => '081234567890',
        ];
    }
}
