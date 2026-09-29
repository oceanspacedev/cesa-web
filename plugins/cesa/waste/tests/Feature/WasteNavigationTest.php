<?php

use Cesa\Waste\Filament\Clusters\Configurations;
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
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Database\Factories\UserFactory;
use Filament\Forms\Components\Field;
use Filament\Navigation\NavigationItem;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('lists laporan waste beside pengaturan like form transfer', function (): void {
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
        ->and(WasteWorkflowResource::getNavigationGroup())->toBe('Pengaturan Khusus Brand')
        ->and(WasteBrandResource::getNavigationIcon())->toBe(Heroicon::OutlinedBuildingStorefront)
        ->and(WasteUnitResource::getNavigationIcon())->toBe(Heroicon::OutlinedScale)
        ->and(WasteOutletResource::getNavigationIcon())->toBe(Heroicon::OutlinedMapPin)
        ->and(WasteSectionResource::getNavigationIcon())->toBe(Heroicon::OutlinedRectangleGroup)
        ->and(WasteCategoryResource::getNavigationIcon())->toBe(Heroicon::OutlinedTag)
        ->and(WasteItemResource::getNavigationIcon())->toBe(Heroicon::OutlinedCube)
        ->and(WasteItemUnitCandidateResource::getNavigationIcon())->toBe(Heroicon::OutlinedQueueList)
        ->and(WasteWorkflowResource::getNavigationIcon())->toBe(Heroicon::OutlinedCheckBadge);

    $activeColumn = WasteBrandResource::table(Table::make(new ManageWasteWorkflows))->getColumn('is_active');

    expect($activeColumn)->toBeInstanceOf(TextColumn::class)
        ->and($activeColumn->formatState(true))->toBe('Aktif')
        ->and($activeColumn->formatState(false))->toBe('Nonaktif');

    $html = Blade::render(
        '<x-filament-panels::sidebar.group :label="$label" icon="icon-waste" :items="$items" :sidebar-collapsible="false" />',
        [
            'label' => 'Waste',
            'items' => [
                NavigationItem::make(WasteReportResource::getNavigationLabel())->url('/admin'),
                NavigationItem::make(Configurations::getNavigationLabel())->url('/admin'),
            ],
        ],
    );

    expect($html)->toContain('Laporan waste')
        ->and($html)->toContain('Pengaturan');
});

it('explains that a disabled approval route approves the report when no other flow is active', function (): void {
    $activeField = collect(WasteWorkflowResource::form(Schema::make(new ManageWasteWorkflows))->getComponents())
        ->first(fn ($component): bool => $component->getName() === 'is_active');

    $helper = $activeField->getChildComponents(Field::BELOW_CONTENT_SCHEMA_KEY)[0];

    $outletColumn = WasteWorkflowResource::table(Table::make(new ManageWasteWorkflows))
        ->getColumns()['outlet.name'];

    expect((string) $helper->getContent())->toContain('laporan langsung disetujui')
        ->and($outletColumn->getPlaceholder())->toBe('Semua outlet');
});

it('counts approval people in the langkah column', function (): void {
    if (! Route::has('filament.admin.waste.configurations')) {
        Route::get('/_test/waste/configurations', static fn (): string => '')
            ->name('filament.admin.waste.configurations');
    }

    $user = UserFactory::new()->createQuietly();
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create(['brand_id' => $brand->id, 'name' => 'Ciledug', 'is_active' => true]);
    $brand->users()->attach($user);
    $this->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));

    $onePerson = WasteWorkflow::query()->create([
        'brand_id'  => $brand->id,
        'outlet_id' => $outlet->id,
        'name'      => 'Persetujuan Ciledug',
        'steps'     => [[
            'label' => 'SM',
            'name'  => 'APRI',
            'phone' => '0895636786435',
            'email' => null,
        ]],
        'is_active' => true,
    ]);
    $twoPeople = WasteWorkflow::query()->create([
        'brand_id' => $brand->id,
        'name'     => 'Persetujuan Jchicken',
        'steps'    => [
            ['label' => 'SM', 'name' => 'APRI', 'phone' => '0895636786435', 'email' => null],
            ['label' => 'Audit', 'name' => 'Bima', 'phone' => null, 'email' => 'bima@example.com'],
        ],
        'is_active' => true,
    ]);

    $column = Livewire::test(ManageWasteWorkflows::class)->instance()->getTable()->getColumn('steps');

    $column->record($onePerson);
    $column->clearCachedState();
    expect(trim(strip_tags($column->toEmbeddedHtml())))->toBe('1');

    $column->record($twoPeople);
    $column->clearCachedState();
    expect(trim(strip_tags($column->toEmbeddedHtml())))->toBe('2');
});
