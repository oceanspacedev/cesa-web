<?php

namespace Cesa\IdCard;

use Cesa\DatabaseSnapshot\Services\DatabaseSnapshotManager;
use Cesa\IdCard\Livewire\PublicIdCardRequestForm;
use Cesa\IdCard\Models\IdCardRequest;
use Cesa\IdCard\Policies\IdCardRequestPolicy;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class IdCardServiceProvider extends PackageServiceProvider
{
    public static string $name = 'id-card';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigrations([
                '2026_09_21_065716_create_id_card_requests_table',
            ])
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command): void {})
            ->icon('id-card');
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(IdCardPlugin::make());
        });
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Livewire::component('cesa.id-card.livewire.public-id-card-request-form', PublicIdCardRequestForm::class);
        Gate::policy(IdCardRequest::class, IdCardRequestPolicy::class);

        if (app()->bound(DatabaseSnapshotManager::class)) {
            app(DatabaseSnapshotManager::class)->registerPlugin('id-card', [
                'version' => '1.0.0',
                'tables'  => ['id_card_requests'],
            ]);
        }
    }
}
