<?php

use Cesa\Waste\Database\Seeders\DatabaseSeeder;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEvent;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Models\WasteReportVersion;
use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeds every unit used by the source masters with a readable name', function (): void {
    config(['waste.master_sources' => []]);
    $this->seed(DatabaseSeeder::class);

    $units = WasteUnit::query()->pluck('name', 'code');

    expect($units->get('SHT'))->toBe('Sachet')
        ->and($units->get('PAI'))->toBe('Pail')
        ->and($units->get('SLC'))->toBe('Slice')
        ->and($units->get('CC'))->toBe('Sentimeter Kubik')
        ->and($units->get('LBR'))->toBe('Lembar')
        ->and($units->get('ROL'))->toBe('Roll')
        ->and($units->get('PCK'))->toBe('Pack')
        ->and($units->get('BTL'))->toBe('Botol')
        ->and($units->get('PSG'))->toBe('Porsi')
        ->and($units->get('GALON'))->toBe('Galon')
        ->and($units->get('BUAH'))->toBe('Buah')
        ->and($units->get('BAL'))->toBe('Bal')
        ->and($units->every(fn (string $name, string $code): bool => $name !== $code))->toBeTrue();
});

it('seeds only the categories and sections that appear in the monthly Excel reports', function (): void {
    config(['waste.master_sources' => []]);
    $this->seed(DatabaseSeeder::class);

    expect(WasteCategory::query()->where('name', 'Discontinued')->exists())->toBeFalse()
        ->and(WasteCategory::query()->pluck('name')->unique()->values()->all())->toEqualCanonicalizing([
            'Waste', 'Spoil', 'Training', 'Test food/Kalibrasi', 'Training/Trial', 'Pemotretan/Event/Talent',
        ])
        ->and(WasteSection::query()->where('name', 'DINING')->exists())->toBeFalse()
        ->and(WasteSection::query()->pluck('name')->all())->toEqualCanonicalizing(['BAR', 'COOK', 'ASSEMBLY', 'MP']);
});

it('canonicalizes unit aliases and misspelled category labels from the source Excel', function (): void {
    expect(WasteUnit::normalizeCode('PORSI'))->toBe('PRS')
        ->and(WasteUnit::normalizeCode('ROLL'))->toBe('ROL')
        ->and(WasteUnit::normalizeCode('PACK'))->toBe('PCK')
        ->and(WasteUnit::normalizeCode('GALON'))->toBe('GALON')
        ->and(WasteCategory::normalizedName('Tarining/Trial'))->toBe('Training/Trial')
        ->and(WasteCategory::normalizedName('Pemotreatan/Event/Talent'))->toBe('Pemotretan/Event/Talent')
        ->and(WasteCategory::normalizedName(' Training/Trial '))->toBe('Training/Trial')
        ->and(WasteCategory::normalizedName('Waste'))->toBe('Waste');
});

it('rewrites legacy alias units and misspelled category names when the canonicalization migration runs', function (): void {
    config(['waste.master_sources' => []]);
    $this->seed(DatabaseSeeder::class);

    $brand = WasteBrand::query()->where('code', 'JCHICKEN')->sole();
    $outlet = WasteOutlet::query()->where('brand_id', $brand->id)->sole();
    $canonical = WasteUnit::query()->where('code', 'ROL')->sole();
    $alias = WasteUnit::query()->create(['code' => 'ROLL', 'name' => 'ROLL', 'is_active' => true]);
    $item = WasteItem::query()->create([
        'brand_id'  => $brand->id,
        'code'      => 'X1',
        'name'      => 'Plastik Wrap',
        'unit'      => 'ROLL',
        'is_active' => true,
    ]);
    DB::table('waste_item_alternate_units')->insert([
        'item_id' => $item->id, 'unit_id' => $alias->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $report = WasteReport::query()->create([
        'uid'            => (string) Str::uuid(),
        'brand_id'       => $brand->id,
        'outlet_id'      => $outlet->id,
        'event_date'     => '2026-09-01',
        'reporter_name'  => 'QA',
        'reporter_phone' => '081234567890',
        'status'         => 'pending',
    ]);
    $version = WasteReportVersion::query()->create([
        'report_id'         => $report->id,
        'version_number'    => 1,
        'status'            => 'pending',
        'workflow_snapshot' => [],
    ]);
    $event = WasteEvent::query()->create([
        'version_id'    => $version->id,
        'sequence'      => 1,
        'category_name' => 'Tarining/Trial',
        'reason'        => 'sisa',
        'pip_unit'      => 'ROLL',
    ]);

    DB::table('migrations')->where('migration', 'like', '%canonicalize_waste_units%')->delete();
    $this->artisan('migrate', [
        '--path'           => 'plugins/cesa/waste/database/migrations/2026_09_26_000000_canonicalize_waste_units_and_category_names.php',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($item->fresh()->unit)->toBe('ROL')
        ->and($event->fresh()->pip_unit)->toBe('ROL')
        ->and($event->fresh()->category_name)->toBe('Training/Trial')
        ->and((float) DB::table('waste_item_alternate_units')->where('item_id', $item->id)->value('unit_id'))->toBe((float) $canonical->id)
        ->and(WasteUnit::query()->where('code', 'ROLL')->exists())->toBeFalse();
});
