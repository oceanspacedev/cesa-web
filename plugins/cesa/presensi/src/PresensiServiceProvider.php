<?php

namespace Cesa\Presensi;

use App\Models\User;
use Cesa\Presensi\Console\Commands\MigratePresensiData;
use Cesa\Presensi\Models\Attendance;
use Cesa\Presensi\Models\Leave;
use Cesa\Presensi\Models\Office;
use Cesa\Presensi\Models\Overtime;
use Cesa\Presensi\Models\Schedule;
use Cesa\Presensi\Models\Shift;
use Cesa\Presensi\Policies\AttendancePolicy;
use Cesa\Presensi\Policies\LeavePolicy;
use Cesa\Presensi\Policies\OfficePolicy;
use Cesa\Presensi\Policies\OvertimePolicy;
use Cesa\Presensi\Policies\SchedulePolicy;
use Cesa\Presensi\Policies\ShiftPolicy;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class PresensiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'presensi';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasRoute('api')
            ->hasMigrations([
                '2024_06_25_062004_presensi_create_offices_table',
                '2024_06_25_062224_presensi_create_shifts_table',
                '2024_06_25_062414_presensi_create_schedules_table',
                '2024_06_25_063427_presensi_create_attendances_table',
                '2024_07_25_044208_presensi_create_leaves_table',
                '2024_10_05_000000_presensi_create_overtimes_table',
                '2026_03_12_020000_presensi_drop_user_presensi_image_column',
                '2026_05_15_010500_add_creator_id_to_presensi_tables',
            ])
            ->hasCommand(MigratePresensiData::class)
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->installDependencies()
                    ->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('presensi');
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(PresensiPlugin::make());
        });
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        $userModel = config('auth.providers.users.model', User::class);

        $userModel::resolveRelationUsing('schedules', function ($user) {
            return $user->hasMany(Schedule::class);
        });

        $userModel::resolveRelationUsing('leaves', function ($user) {
            return $user->hasMany(Leave::class)->withTrashed();
        });

        $userModel::resolveRelationUsing('overtimes', function ($user) {
            return $user->hasMany(Overtime::class)->withTrashed();
        });

        Gate::policy(Office::class, OfficePolicy::class);
        Gate::policy(Shift::class, ShiftPolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(Attendance::class, AttendancePolicy::class);
        Gate::policy(Leave::class, LeavePolicy::class);
        Gate::policy(Overtime::class, OvertimePolicy::class);
    }
}
