<?php

namespace Cesa\ExitClearance;

use Cesa\DatabaseSnapshot\Services\DatabaseSnapshotManager;
use Cesa\ExitClearance\Database\Seeders\DatabaseSeeder;
use Cesa\ExitClearance\Livewire\PublicExitClearanceApprovalPage;
use Cesa\ExitClearance\Livewire\PublicExitClearanceProgressPage;
use Cesa\ExitClearance\Livewire\PublicExitClearanceRequestForm;
use Cesa\ExitClearance\Models\Approver;
use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Models\Request;
use Cesa\ExitClearance\Policies\ApproverPolicy;
use Cesa\ExitClearance\Policies\DepartmentPolicy;
use Cesa\ExitClearance\Policies\RequestPolicy;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Cesa\ExitClearance\Services\MailThrottleService;
use Cesa\ExitClearance\Services\WhatsAppThrottleService;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class ExitClearanceServiceProvider extends PackageServiceProvider
{
    public static string $name = 'exit-clearance';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigrations([
                '2025_01_17_140000_exit_clearance_create_ec_departments_table',
                '2026_01_17_071553_exit_clearance_create_ec_approvers_table',
                '2026_01_17_071554_exit_clearance_create_ec_department_approver_table',
                '2026_01_17_080000_exit_clearance_create_ec_requests_table',
                '2026_01_17_090000_exit_clearance_create_ec_request_approver_table',
                '2026_05_15_011100_exit_clearance_rename_legacy_creator_columns',
                '2026_08_11_170000_exit_clearance_drop_email_unique_from_ec_approvers_table',
                '2026_09_13_173706_exit_clearance_add_company_id_to_ec_requests_table',
            ])
            ->runsMigrations()
            ->runsSeeders()
            ->hasSeeder(DatabaseSeeder::class)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('exit-clearance');
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Livewire::component('cesa.exit-clearance.livewire.public-exit-clearance-request-form', PublicExitClearanceRequestForm::class);
        Livewire::component('cesa.exit-clearance.livewire.public-exit-clearance-approval', PublicExitClearanceApprovalPage::class);
        Livewire::component('cesa.exit-clearance.livewire.public-exit-clearance-progress', PublicExitClearanceProgressPage::class);

        Gate::policy(Request::class, RequestPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Approver::class, ApproverPolicy::class);

        $this->registerSnapshotMetadata();
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(ExitClearancePlugin::make());
        });

        $this->app->singleton(ExitClearanceRequestService::class);
        $this->app->singleton(ExitClearanceNotificationService::class);
        $this->app->singleton(MailThrottleService::class);
        $this->app->singleton(WhatsAppThrottleService::class);

        // Load translation files
        $this->loadTranslationsFrom(
            __DIR__.'/../resources/lang',
            'exit-clearance'
        );
    }

    protected function registerSnapshotMetadata(): void
    {
        if (! app()->bound(DatabaseSnapshotManager::class)) {
            return;
        }

        $manager = app(DatabaseSnapshotManager::class);

        $manager->registerPlugin('exit-clearance', [
            'version' => '1.0.0',
            'tables'  => [
                'exit_clearance_departments',
                'exit_clearance_approvers',
                'exit_clearance_department_approver',
                'exit_clearance_requests',
                'exit_clearance_request_approver',
            ],
        ]);
    }
}
