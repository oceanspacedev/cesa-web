<?php

namespace Cesa\Document;

use Cesa\DatabaseSnapshot\Services\DatabaseSnapshotManager;
use Cesa\Document\Models\Document as DocumentModel;
use Cesa\Document\Policies\DocumentPolicy;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class DocumentServiceProvider extends PackageServiceProvider
{
    public static string $name = 'document';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasTranslations()
            ->hasMigrations([
                '2025_08_25_000001_create_documents_table',
                '2026_05_15_010000_add_creator_id_to_documents_table',
            ])
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command): void {});
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Gate::policy(DocumentModel::class, DocumentPolicy::class);

        // Register snapshot metadata for database backup/restore
        $this->registerSnapshotMetadata();
    }

    protected function registerSnapshotMetadata(): void
    {
        if (! app()->bound(DatabaseSnapshotManager::class)) {
            return;
        }

        $manager = app(DatabaseSnapshotManager::class);

        $manager->registerPlugin('document', [
            'version' => '1.0.0',
            'tables'  => [
                'documents',
            ],
        ]);
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(DocumentPlugin::make());
        });
    }
}
