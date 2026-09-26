<?php

namespace Cesa\Waste;

use Cesa\Waste\Console\Commands\ImportWasteMaster;
use Cesa\Waste\Console\Commands\QaReviewSeptember;
use Cesa\Waste\Console\Commands\ReplayWasteSeptemberQa;
use Cesa\Waste\Console\Commands\StageWasteAlternateUnits;
use Cesa\Waste\Database\Seeders\DatabaseSeeder;
use Cesa\Waste\Livewire\PublicWasteApprovalPage;
use Cesa\Waste\Livewire\PublicWasteProgressPage;
use Cesa\Waste\Livewire\PublicWasteReportForm;
use Cesa\Waste\Livewire\PublicWasteRevisionPage;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteItemUnitCandidate;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Policies\WasteBrandPolicy;
use Cesa\Waste\Policies\WasteCategoryPolicy;
use Cesa\Waste\Policies\WasteItemPolicy;
use Cesa\Waste\Policies\WasteItemUnitCandidatePolicy;
use Cesa\Waste\Policies\WasteOutletPolicy;
use Cesa\Waste\Policies\WasteReportPolicy;
use Cesa\Waste\Policies\WasteSectionPolicy;
use Cesa\Waste\Policies\WasteUnitPolicy;
use Cesa\Waste\Policies\WasteWorkflowPolicy;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Console\Commands\UninstallCommand;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class WasteServiceProvider extends PackageServiceProvider
{
    public static string $name = 'waste';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigrations([
                '2026_09_22_000000_create_waste_tables',
                '2026_09_23_000000_move_waste_sections_into_their_own_table',
                '2026_09_24_080109_create_waste_units_table',
                '2026_09_24_081340_add_item_type_to_waste_event_lines_table',
                '2026_09_24_091118_add_mis_review_flags_to_waste_event_lines_table',
                '2026_09_24_091231_create_waste_item_alternate_units_table',
                '2026_09_24_094016_add_source_unit_labels_to_waste_items_and_event_lines',
                '2026_09_24_103013_create_waste_item_unit_candidates_table',
                '2026_09_26_000000_canonicalize_waste_units_and_category_names',
            ])
            ->hasCommand(ImportWasteMaster::class)
            ->hasCommand(QaReviewSeptember::class)
            ->hasCommand(ReplayWasteSeptemberQa::class)
            ->hasCommand(StageWasteAlternateUnits::class)
            ->runsMigrations()
            ->runsSeeders()
            ->hasSeeder(DatabaseSeeder::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->runsMigrations()->runsSeeders();
            })
            ->hasUninstallCommand(function (UninstallCommand $command): void {})
            ->icon('waste');
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(WastePlugin::make());
        });
    }

    public function packageBooted(): void
    {
        if (! ($this->package->isCore || $this->package->isInstalled())) {
            return;
        }

        Livewire::component('cesa.waste.livewire.public-waste-report-form', PublicWasteReportForm::class);
        Livewire::component('cesa.waste.livewire.public-waste-revision-page', PublicWasteRevisionPage::class);
        Livewire::component('cesa.waste.livewire.public-waste-progress-page', PublicWasteProgressPage::class);
        Livewire::component('cesa.waste.livewire.public-waste-approval-page', PublicWasteApprovalPage::class);
        Gate::policy(WasteReport::class, WasteReportPolicy::class);
        Gate::policy(WasteBrand::class, WasteBrandPolicy::class);
        Gate::policy(WasteOutlet::class, WasteOutletPolicy::class);
        Gate::policy(WasteItem::class, WasteItemPolicy::class);
        Gate::policy(WasteItemUnitCandidate::class, WasteItemUnitCandidatePolicy::class);
        Gate::policy(WasteCategory::class, WasteCategoryPolicy::class);
        Gate::policy(WasteSection::class, WasteSectionPolicy::class);
        Gate::policy(WasteUnit::class, WasteUnitPolicy::class);
        Gate::policy(WasteWorkflow::class, WasteWorkflowPolicy::class);
    }
}
