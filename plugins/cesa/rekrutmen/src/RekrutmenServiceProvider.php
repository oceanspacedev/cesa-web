<?php

namespace Cesa\Rekrutmen;

use Cesa\DatabaseSnapshot\Services\DatabaseSnapshotManager;
use Cesa\Rekrutmen\Console\Commands\ProcessScheduledNotificationsCommand;
use Cesa\Rekrutmen\Console\Commands\RunWhatsAppEngineCommand;
use Cesa\Rekrutmen\Console\Commands\SyncCandidateCvCommand;
use Cesa\Rekrutmen\Console\Commands\SyncStorageToS3Command;
use Cesa\Rekrutmen\Database\Seeders\DatabaseSeeder;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerApprovalPage;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerForm;
use Cesa\Rekrutmen\Livewire\PublicRequestManPowerProgressPage;
use Cesa\Rekrutmen\Models\Approver;
use Cesa\Rekrutmen\Models\Division;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\JobApplicationHistory;
use Cesa\Rekrutmen\Models\JobPosting;
use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Policies\ApproverPolicy;
use Cesa\Rekrutmen\Policies\DivisionPolicy;
use Cesa\Rekrutmen\Policies\JobApplicationHistoryPolicy;
use Cesa\Rekrutmen\Policies\JobApplicationPolicy;
use Cesa\Rekrutmen\Policies\JobPostingPolicy;
use Cesa\Rekrutmen\Policies\RekrutmenPipelinePolicy;
use Cesa\Rekrutmen\Policies\RequestManPowerPolicy;
use Cesa\Rekrutmen\Services\MailThrottleService;
use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Cesa\Rekrutmen\Services\RequestManPowerApprovalWhatsAppNotifier;
use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Cesa\Rekrutmen\Services\WhatsAppEngineClient;
use Cesa\Rekrutmen\Services\WhatsAppEngineProcess;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Cesa\Rekrutmen\Services\WhatsAppThrottleService;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Security\Models\User;

class RekrutmenServiceProvider extends PackageServiceProvider
{
    public static string $name = 'rekrutmen';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web')
            ->hasRoute('api')
            ->hasMigrations([
                '2026_02_26_130000_rekrutmen_create_pipelines_table',
                '2026_02_26_130001_create_rekrutmen_stages_table',
                '2026_02_26_140000_rekrutmen_create_request_man_powers_table',
                '2026_02_26_140001_rekrutmen_create_job_postings_table',
                '2026_02_26_140002_rekrutmen_create_job_applications_table',
                '2026_02_26_140005_rekrutmen_create_job_application_histories_table',
                '2026_03_12_210000_rekrutmen_add_status_response_id_to_request_man_powers_table',
                '2026_03_31_224308_rekrutmen_update_job_applications_contact_and_document_fields',
                '2026_03_31_230839_rekrutmen_add_thumbnail_path_to_job_postings_table',
                '2026_03_31_234155_rekrutmen_add_position_to_job_applications_table',
                '2026_04_07_094912_rekrutmen_add_active_email_to_job_applications_table',
                '2026_04_18_120000_rekrutmen_add_active_whatsapp_to_job_applications_table',
                '2026_04_08_200000_rekrutmen_add_activity_fields_to_job_application_histories_table',
                '2026_04_08_210000_rekrutmen_add_performance_indexes',
                '2026_04_08_220000_rekrutmen_add_reporting_indexes',
                '2026_04_08_230000_rekrutmen_add_filter_indexes',
                '2026_04_09_114253_rekrutmen_fix_status_defaults_and_stage_constraints',
                '2026_04_14_144908_rekrutmen_normalize_job_application_pipeline_state',
                '2026_04_14_151437_rekrutmen_add_company_id_to_request_man_powers_table',
                '2026_04_15_120000_rekrutmen_create_approvers_table',
                '2026_04_15_130000_rekrutmen_create_divisions_table',
                '2026_04_15_140000_rekrutmen_add_division_id_to_request_man_powers_and_approvers',
                '2026_04_16_142720_rekrutmen_add_source_to_job_applications_table',
                '2026_04_16_143210_add_source_column_to_job_applications_table',
                '2026_04_16_150000_rekrutmen_add_approval_order_to_approvers_table',
                '2026_04_16_150100_rekrutmen_create_request_man_power_approvals_table',
                '2026_04_24_073355_rekrutmen_add_hold_audit_fields_to_request_man_powers_table',
                '2026_04_24_073401_rekrutmen_create_request_man_power_status_histories_table',
                '2026_04_27_000000_rekrutmen_add_public_job_listing_indexes',
                '2026_05_02_115102_rekrutmen_add_job_posting_id_to_request_man_powers_table',
                '2026_05_15_010600_add_creator_id_to_rekrutmen_tables',
                '2026_05_15_011200_rekrutmen_rename_legacy_creator_columns',
                '2026_09_02_000000_rekrutmen_create_scheduled_notifications_table',
                '2026_09_03_083000_rekrutmen_add_company_id_to_job_postings_table',
                '2026_09_03_140000_rekrutmen_create_mail_settings_table',
                '2026_09_03_140100_rekrutmen_create_whatsapp_gateway_tables',
                '2026_09_03_140200_rekrutmen_add_whatsapp_account_id_to_scheduled_notifications_table',
                '2026_09_08_000001_rekrutmen_add_candidate_schedules_to_scheduled_notifications_table',
                '2026_09_13_175849_rekrutmen_create_notification_deliveries_table',
                '2026_09_13_180128_rekrutmen_add_whatsapp_management_permission',
                '2026_09_13_181639_rekrutmen_add_connection_request_key_to_whatsapp_accounts',
                '2026_09_13_225310_rekrutmen_add_file_disks_to_recruitment_tables',
                '2026_09_14_093000_rekrutmen_grant_whatsapp_management_permission',
            ])
            ->runsMigrations()
            ->runsSeeders()
            ->hasSeeder(DatabaseSeeder::class)
            ->hasCommands([
                ProcessScheduledNotificationsCommand::class,
                RunWhatsAppEngineCommand::class,
                SyncCandidateCvCommand::class,
                SyncStorageToS3Command::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->runsMigrations()
                    ->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('rekrutmen');
    }

    public function packageRegistered(): void
    {
        Gate::define('manage_rekrutmen_whatsapp', fn (User $user): bool => $user->roles()->where('name', config('filament-shield.super_admin.name', 'super_admin'))->exists() || $user->checkPermissionTo('manage_rekrutmen_whatsapp'));
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(RekrutmenPlugin::make());
        });

        $this->app->singleton(MailThrottleService::class);
        $this->app->singleton(WhatsAppThrottleService::class);
        $this->app->singleton(RekrutmenMailer::class);
        $this->app->singleton(WhatsAppEngineClient::class);
        $this->app->singleton(WhatsAppEngineProcess::class);
        $this->app->singleton(WhatsAppGateway::class);
        $this->app->singleton(RequestManPowerApprovalWhatsAppNotifier::class);
        $this->app->singleton(ScheduledNotificationService::class);
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Livewire::component('cesa.rekrutmen.livewire.public-man-power-request-form', PublicRequestManPowerForm::class);
        Livewire::component('cesa.rekrutmen.livewire.public-man-power-approval-page', PublicRequestManPowerApprovalPage::class);
        Livewire::component('cesa.rekrutmen.livewire.public-man-power-progress-page', PublicRequestManPowerProgressPage::class);
        Gate::policy(Approver::class, ApproverPolicy::class);
        Gate::policy(Division::class, DivisionPolicy::class);
        Gate::policy(RekrutmenPipeline::class, RekrutmenPipelinePolicy::class);
        Gate::policy(JobPosting::class, JobPostingPolicy::class);
        Gate::policy(JobApplication::class, JobApplicationPolicy::class);
        Gate::policy(JobApplicationHistory::class, JobApplicationHistoryPolicy::class);
        Gate::policy(RequestManPower::class, RequestManPowerPolicy::class);

        // Register snapshot metadata for database backup/restore
        $this->registerSnapshotMetadata();
    }

    protected function registerSnapshotMetadata(): void
    {
        if (! app()->bound(DatabaseSnapshotManager::class)) {
            return;
        }

        $manager = app(DatabaseSnapshotManager::class);

        $manager->registerPlugin('rekrutmen', [
            'version' => '1.0.0',
            'tables'  => [
                'rekrutmen_pipelines',
                'rekrutmen_stages',
                'rekrutmen_approvers',
                'rekrutmen_divisions',
                'rekrutmen_request_man_powers',
                'rekrutmen_request_man_power_approvals',
                'rekrutmen_request_man_power_status_histories',
                'rekrutmen_job_postings',
                'rekrutmen_job_applications',
                'rekrutmen_job_application_histories',
                'rekrutmen_scheduled_notifications',
                'rekrutmen_notification_deliveries',
                'rekrutmen_mail_settings',
                'rekrutmen_whatsapp_settings',
                'rekrutmen_whatsapp_accounts',
            ],
        ]);
    }
}
