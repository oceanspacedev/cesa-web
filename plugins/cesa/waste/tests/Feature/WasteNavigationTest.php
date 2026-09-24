<?php

use Cesa\Waste\Filament\Clusters\Configurations;
use Cesa\Waste\Filament\Pages\WasteDashboard;
use Cesa\Waste\Filament\Resources\WasteBrandResource;
use Cesa\Waste\Filament\Resources\WasteCategoryResource;
use Cesa\Waste\Filament\Resources\WasteItemResource;
use Cesa\Waste\Filament\Resources\WasteItemUnitCandidateResource;
use Cesa\Waste\Filament\Resources\WasteOutletResource;
use Cesa\Waste\Filament\Resources\WasteReportResource;
use Cesa\Waste\Filament\Resources\WasteSectionResource;
use Cesa\Waste\Filament\Resources\WasteUnitResource;
use Cesa\Waste\Filament\Resources\WasteWorkflowResource;
use Cesa\Waste\Filament\Resources\WasteWorkflowResource\Pages\ManageWasteWorkflows;
use Database\Factories\UserFactory;
use Filament\Forms\Components\Field;
use Filament\Navigation\NavigationItem;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;

it('lists laporan waste and the dashboard beside pengaturan like form transfer', function (): void {
    app()->setLocale('id');
    $this->actingAs(UserFactory::new()->createQuietly());
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $masters = [
        WasteBrandResource::class,
        WasteOutletResource::class,
        WasteItemResource::class,
        WasteUnitResource::class,
        WasteSectionResource::class,
        WasteCategoryResource::class,
        WasteWorkflowResource::class,
    ];

    $wasteGroup = collect(filament()->getCurrentPanel()->getNavigationGroups())
        ->first(fn ($group): bool => $group->getLabel() === 'Waste');

    expect($wasteGroup?->getIcon())->toBe('icon-waste')
        ->and(WasteReportResource::getNavigationGroup())->toBe('Waste')
        ->and(WasteReportResource::getNavigationLabel())->toBe('Laporan waste')
        ->and(WasteDashboard::getNavigationGroup())->toBe('Waste')
        ->and(WasteDashboard::getNavigationLabel())->toBe('Dasbor')
        ->and(Configurations::getNavigationGroup())->toBe('Waste')
        ->and(Configurations::getNavigationLabel())->toBe('Pengaturan');

    foreach ($masters as $page) {
        expect($page::getCluster())->toBe(Configurations::class);
    }

    $configurationOrder = collect([
        WasteBrandResource::class,
        WasteOutletResource::class,
        WasteSectionResource::class,
        WasteCategoryResource::class,
        WasteUnitResource::class,
        WasteItemResource::class,
        WasteItemUnitCandidateResource::class,
        WasteWorkflowResource::class,
    ])->sortBy(fn (string $page): int => (int) $page::getNavigationSort())
        ->map(fn (string $page): string => $page::getNavigationLabel())
        ->values()
        ->all();

    expect($configurationOrder)->toBe([
        'Brand',
        'Master Satuan',
        'Outlet',
        'Section',
        'Kategori',
        'Barang',
        'Kandidat satuan',
        'Approval',
    ])->and(WasteBrandResource::getNavigationGroup())->toBe('Pengaturan Global')
        ->and(WasteUnitResource::getNavigationGroup())->toBe('Pengaturan Global')
        ->and(WasteOutletResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteSectionResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteCategoryResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteItemResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteItemUnitCandidateResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteWorkflowResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand');

    $html = Blade::render(
        '<x-filament-panels::sidebar.group :label="$label" icon="icon-waste" :items="$items" :sidebar-collapsible="false" />',
        [
            'label' => 'Waste',
            'items' => [
                NavigationItem::make(WasteReportResource::getNavigationLabel())->url('/admin'),
                NavigationItem::make(WasteDashboard::getNavigationLabel())->url('/admin'),
                NavigationItem::make(Configurations::getNavigationLabel())->url('/admin'),
            ],
        ],
    );

    expect($html)->toContain('Laporan waste')
        ->and($html)->toContain('Dasbor')
        ->and($html)->toContain('Pengaturan');
});

it('explains that a disabled approval route still waits for MIS review', function (): void {
    $activeField = collect(WasteWorkflowResource::form(Schema::make(new ManageWasteWorkflows))->getComponents())
        ->first(fn ($component): bool => $component->getName() === 'is_active');

    $helper = $activeField->getChildComponents(Field::BELOW_CONTENT_SCHEMA_KEY)[0];

    $outletColumn = WasteWorkflowResource::table(Table::make(new ManageWasteWorkflows))
        ->getColumns()['outlet.name'];

    expect((string) $helper->getContent())->toContain('menunggu tinjauan MIS')
        ->and($outletColumn->getPlaceholder())->toBe('Semua outlet');
});
