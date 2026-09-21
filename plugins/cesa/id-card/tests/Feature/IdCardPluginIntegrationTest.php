<?php

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Cesa\IdCard\Filament\Resources\IdCardRequestResource;
use Cesa\IdCard\IdCardPlugin;
use Cesa\IdCard\IdCardServiceProvider;
use Cesa\IdCard\Models\IdCardRequest;
use Cesa\IdCard\Policies\IdCardRequestPolicy;
use Filament\Panel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

it('registers the package migrations configuration routes and policy', function (): void {
    $package = new Package;
    (new IdCardServiceProvider($this->app))->configureCustomPackage($package);

    $migrations = collect(glob(base_path('plugins/cesa/id-card/database/migrations/*.php')))
        ->map(fn (string $file): string => basename($file, '.php'))
        ->sort()
        ->values()
        ->all();

    expect(IdCardPlugin::make()->getId())->toBe('id-card')
        ->and($package->name)->toBe('id-card')
        ->and($package->configFileNames)->toContain('id-card')
        ->and($package->routeFileNames)->toContain('web')
        ->and($package->icon)->toBe('id-card')
        ->and($package->migrationFileNames)->toEqual($migrations)
        ->and($package->runsMigrations)->toBeTrue()
        ->and(config('id-card.submissions.max_attempts'))->toBe(5)
        ->and(Route::has('id-card.public.form'))->toBeTrue()
        ->and(Route::has('id-card.photos.show'))->toBeTrue()
        ->and(Gate::getPolicyFor(IdCardRequest::class))->toBeInstanceOf(IdCardRequestPolicy::class);

    expect(file_get_contents(resource_path('svg/id-card.svg')))
        ->toBe(file_get_contents(public_path('svg/id-card.svg')));
});

it('only registers admin resources when the plugin is installed', function (): void {
    $adminPanel = (new Panel)->id('admin');
    IdCardPlugin::make()->register($adminPanel);

    expect($adminPanel->getResources())->toContain(IdCardRequestResource::class);

    $publicPanel = (new Panel)->id('public');
    IdCardPlugin::make()->register($publicPanel);

    expect($publicPanel->getResources())->not->toContain(IdCardRequestResource::class);

    Plugin::query()->where('name', 'id-card')->update(['is_installed' => false]);
    Package::$plugins = [];

    $uninstalledPanel = (new Panel)->id('admin');
    IdCardPlugin::make()->register($uninstalledPanel);

    expect($uninstalledPanel->getResources())->not->toContain(IdCardRequestResource::class);
});

it('uses the actual shield permission generator for all configured policy abilities', function (): void {
    $abilities = config('filament-shield.resources.manage.'.IdCardRequestResource::class);
    $permissions = FilamentShield::getDefaultPermissionKeys(IdCardRequestResource::class, $abilities);
    $policy = new IdCardRequestPolicy;

    foreach ($abilities as $ability) {
        $method = Str::camel($ability);
        $user = $this->createIdCardUser([$ability]);

        expect($permissions[$method]['key'])->toBe($ability.'_id_card_id::card::request')
            ->and($policy->{$method}($user, new IdCardRequest))->toBeTrue();
    }
});

it('keeps indonesian and english labels in sync', function (): void {
    $english = require base_path('plugins/cesa/id-card/resources/lang/en/id-card.php');
    $indonesian = require base_path('plugins/cesa/id-card/resources/lang/id/id-card.php');

    expect(array_keys(Arr::dot($english)))
        ->toBe(array_keys(Arr::dot($indonesian)));
});
