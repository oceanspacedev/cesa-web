<?php

namespace Cesa\Payroll;

use Cesa\Payroll\Models\PayrollPeriod;
use Cesa\Payroll\Models\PayrollRecord;
use Cesa\Payroll\Policies\PayrollPeriodPolicy;
use Cesa\Payroll\Policies\PayrollRecordPolicy;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class PayrollServiceProvider extends PackageServiceProvider
{
    public static string $name = 'payroll';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasRoute('api')
            ->hasDependencies([
                'presensi',
            ])
            ->hasMigrations([
                '2026_02_09_000000_create_payroll_settings',
                '2026_02_09_000001_payroll_create_periods_table',
                '2026_02_09_000002_create_payroll_records_table',
                '2026_05_15_010400_add_creator_id_to_payroll_tables',
            ])
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->installDependencies()
                    ->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {});
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(PayrollPlugin::make());
        });
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Gate::policy(PayrollPeriod::class, PayrollPeriodPolicy::class);
        Gate::policy(PayrollRecord::class, PayrollRecordPolicy::class);
    }
}
