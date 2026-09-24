<?php

namespace Cesa\Waste\Tests;

use Cesa\Waste\WasteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\UsesSqliteInMemoryDatabase;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

abstract class WasteTestCase extends TestCase
{
    use RefreshDatabase;
    use UsesSqliteInMemoryDatabase;

    protected function setUp(): void
    {
        $this->useSqliteInMemoryDatabase();
        parent::setUp();
        $this->withoutVite();

        $this->artisan('migrate', [
            '--path'           => 'database/migrations/0001_01_01_000000_create_users_table.php',
            '--no-interaction' => true,
        ]);

        $this->artisan('migrate', [
            '--path'           => 'plugins/webkul/plugin-manager/database/migrations/2024_11_05_105102_create_plugins_table.php',
            '--no-interaction' => true,
        ]);

        Plugin::query()->updateOrCreate(['name' => 'waste'], [
            'author'       => 'tests',
            'summary'      => 'tests',
            'description'  => 'tests',
            'is_active'    => true,
            'is_installed' => true,
        ]);

        Package::$plugins = [];
        $this->app->register(WasteServiceProvider::class, true);
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
        $this->artisan('migrate', [
            '--path'           => 'plugins/cesa/waste/database/migrations',
            '--no-interaction' => true,
        ]);

        Storage::fake('local');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Package::$plugins = [];
        parent::tearDown();
    }
}
