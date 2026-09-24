<?php

namespace Cesa\Waste\Tests\Feature;

use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Policies\WasteReportPolicy;
use Cesa\Waste\Tests\WasteTestCase;
use Cesa\Waste\WastePlugin;
use Cesa\Waste\WasteServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Webkul\PluginManager\Package;

class WastePluginSmokeTest extends WasteTestCase
{
    public function test_it_registers_the_waste_package_and_public_routes(): void
    {
        $package = new Package;
        (new WasteServiceProvider($this->app))->configureCustomPackage($package);

        $this->assertSame('waste', WasteServiceProvider::$name);
        $this->assertSame('waste', app(WastePlugin::class)->getId());
        $this->assertContains('web', $package->routeFileNames);
        $this->assertContains('2026_09_22_000000_create_waste_tables', $package->migrationFileNames);
        $this->assertTrue(Route::has('waste.public.form'));
        $this->assertTrue(Route::has('waste.public.submitted'));
        $this->assertTrue(Route::has('waste.public.progress'));
        $this->assertTrue(Route::has('waste.public.manage'));
        $this->assertTrue(Route::has('waste.public.approval'));
        $this->assertTrue(Route::has('waste.public.evidence'));
        $this->assertInstanceOf(WasteReportPolicy::class, Gate::getPolicyFor(WasteReport::class));
    }
}
