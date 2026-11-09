<?php

namespace Cesa\FormTransfer;

use Cesa\DatabaseSnapshot\Services\DatabaseSnapshotManager;
use Cesa\FormTransfer\Livewire\PublicCategoryIndex;
use Cesa\FormTransfer\Livewire\PublicTransferApprovalPage;
use Cesa\FormTransfer\Livewire\PublicTransferProgressPage;
use Cesa\FormTransfer\Livewire\PublicTransferRequestForm;
use Cesa\FormTransfer\Livewire\PublicTransferRequestIndex;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\FormTransferPublicCategory;
use Cesa\FormTransfer\Models\TransferApprovalWorkflow;
use Cesa\FormTransfer\Models\TransferBank;
use Cesa\FormTransfer\Models\TransferDivision;
use Cesa\FormTransfer\Models\TransferReferenceNote;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Observers\TransferApprovalWorkflowObserver;
use Cesa\FormTransfer\Observers\TransferBankObserver;
use Cesa\FormTransfer\Observers\TransferDivisionObserver;
use Cesa\FormTransfer\Observers\TransferReferenceNoteObserver;
use Cesa\FormTransfer\Policies\FormTransferPolicy;
use Cesa\FormTransfer\Policies\FormTransferPublicCategoryPolicy;
use Cesa\FormTransfer\Policies\TransferApprovalWorkflowPolicy;
use Cesa\FormTransfer\Policies\TransferBankPolicy;
use Cesa\FormTransfer\Policies\TransferDivisionPolicy;
use Cesa\FormTransfer\Policies\TransferReferenceNotePolicy;
use Cesa\FormTransfer\Policies\TransferRequestPolicy;
use Cesa\FormTransfer\Repositories\TransferRequestRepository;
use Cesa\FormTransfer\Services\ApprovalWorkflowService;
use Cesa\FormTransfer\Services\EmailNotifier;
use Cesa\FormTransfer\Services\MailThrottleService;
use Cesa\FormTransfer\Services\RateLimitGuard;
use Cesa\FormTransfer\Services\RecaptchaValidator;
use Cesa\FormTransfer\Services\ReferenceDataService;
use Cesa\FormTransfer\Services\TemplateRenderer;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Services\WhatsAppNotifier;
use Cesa\FormTransfer\Services\WhatsAppThrottleService;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class FormTransferServiceProvider extends PackageServiceProvider
{
    public static string $name = 'form-transfer';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile('form-transfer')
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web')
            ->hasRoute('api')
            ->hasMigrations([
                '2025_10_22_043040_form_transfer_create_tables',
                '2026_02_05_000001_create_form_transfer_user_accesses_table',
                '2026_04_22_130000_add_public_catalog_fields_to_form_transfers_table',
                '2026_04_22_150000_drop_public_open_in_new_tab_from_form_transfers_table',
                '2026_04_24_064244_create_form_transfer_request_realizations_table',
                '2026_05_15_010100_add_creator_id_to_form_transfer_support_tables',
                '2026_06_06_141951_create_form_transfer_public_categories_tables',
                '2026_06_07_000001_normalize_form_transfer_builtin_public_categories',
                '2026_11_09_064051_add_requester_whatsapp_to_form_transfer_requests_table',
            ])
            ->runsMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->runsMigrations();
            })
            ->hasUninstallCommand(function (UninstallCommand $command) {})
            ->icon('form-transfer');
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        // Register model observers for cache invalidation
        TransferBank::observe(TransferBankObserver::class);
        TransferDivision::observe(TransferDivisionObserver::class);
        TransferReferenceNote::observe(TransferReferenceNoteObserver::class);
        TransferApprovalWorkflow::observe(TransferApprovalWorkflowObserver::class);

        Livewire::component('cesa.form-transfer.livewire.public-transfer-request-form', PublicTransferRequestForm::class);
        Livewire::component('cesa.form-transfer.livewire.public-transfer-request-index', PublicTransferRequestIndex::class);
        Livewire::component('cesa.form-transfer.livewire.public-category-index', PublicCategoryIndex::class);
        Livewire::component('cesa.form-transfer.livewire.public-transfer-approval', PublicTransferApprovalPage::class);
        Livewire::component('cesa.form-transfer.livewire.public-transfer-progress', PublicTransferProgressPage::class);

        Gate::policy(FormTransfer::class, FormTransferPolicy::class);
        Gate::policy(FormTransferPublicCategory::class, FormTransferPublicCategoryPolicy::class);
        Gate::policy(TransferRequest::class, TransferRequestPolicy::class);
        Gate::policy(TransferDivision::class, TransferDivisionPolicy::class);
        Gate::policy(TransferBank::class, TransferBankPolicy::class);
        Gate::policy(TransferReferenceNote::class, TransferReferenceNotePolicy::class);
        Gate::policy(TransferApprovalWorkflow::class, TransferApprovalWorkflowPolicy::class);

        // Register snapshot metadata for database backup/restore
        $this->registerSnapshotMetadata();
    }

    protected function registerSnapshotMetadata(): void
    {
        if (! app()->bound(DatabaseSnapshotManager::class)) {
            return;
        }

        $manager = app(DatabaseSnapshotManager::class);

        $manager->registerPlugin('form-transfer', [
            'version' => '1.0.0',
            'tables'  => [
                'form_transfers',
                'form_transfer_banks',
                'form_transfer_divisions',
                'form_transfer_reference_notes',
                'form_transfer_approval_workflows',
                'form_transfer_requests',
                'form_transfer_request_realizations',
                'form_transfer_user_accesses',
                'form_transfer_public_categories',
                'form_transfer_public_category_assignments',
            ],
        ]);
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(FormTransferPlugin::make());
        });

        $this->app->singleton(ReferenceDataService::class);
        $this->app->singleton(TransferRequestRepository::class);
        $this->app->singleton(ApprovalWorkflowService::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(EmailNotifier::class);
        $this->app->singleton(MailThrottleService::class);
        $this->app->singleton(WhatsAppNotifier::class);
        $this->app->singleton(WhatsAppThrottleService::class);
        $this->app->singleton(RecaptchaValidator::class);
        $this->app->singleton(RateLimitGuard::class);
        $this->app->singleton(TransferApprovalNotificationService::class);
        $this->app->alias(TransferApprovalNotificationService::class, 'transfer-approval.notifier');
    }

    public function provides(): array
    {
        return [
            ReferenceDataService::class,
            TransferRequestRepository::class,
            ApprovalWorkflowService::class,
            TemplateRenderer::class,
            EmailNotifier::class,
            MailThrottleService::class,
            WhatsAppNotifier::class,
            WhatsAppThrottleService::class,
            RecaptchaValidator::class,
            RateLimitGuard::class,
            TransferApprovalNotificationService::class,
        ];
    }
}
