<?php

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteEventLine;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('registers nullable per-line MIS review columns for installed sites', function (): void {
    expect(collect(app('migrator')->paths())->contains(
        fn (string $path): bool => str_contains($path, '2026_09_24_091118_add_mis_review_flags_to_waste_event_lines_table.php'),
    ))->toBeTrue()
        ->and(Schema::hasColumn('waste_event_lines', 'sm_checked'))->toBeTrue()
        ->and(Schema::hasColumn('waste_event_lines', 'audit_checked'))->toBeTrue();
});

it('keeps unreviewed, checked, and explicitly unchecked states independently on each line', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Jchicken', 'code' => 'JCHICKEN', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->getKey(), 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'jchicken-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->getKey(), 'code' => 'B001-036', 'name' => 'Daun Mint',
        'unit'     => 'GR', 'item_type' => 'bahan baku', 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->getKey(), 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);

    $report = app(WasteReportService::class)->saveByAdmin([
        'brand_id'       => $brand->getKey(),
        'outlet_id'      => $outlet->getKey(),
        'event_date'     => '2026-09-23',
        'status'         => 'approved',
        'reporter_name'  => 'Petugas TPS',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->getKey(),
            'reason'      => 'Layu',
            'lines'       => [['item_id' => $item->getKey(), 'quantity' => '58']],
        ]],
    ], null, null);

    $line = $report->latestVersion->events->first()->lines->first();

    expect($line->sm_checked)->toBeNull()
        ->and($line->audit_checked)->toBeNull();

    $line->update(['sm_checked' => false, 'audit_checked' => true]);

    expect($line->fresh()->sm_checked)->toBeFalse()
        ->and($line->fresh()->audit_checked)->toBeTrue()
        ->and(DB::table('waste_event_lines')->where('id', $line->getKey())->value('sm_checked'))->toBe(0)
        ->and(DB::table('waste_event_lines')->where('id', $line->getKey())->value('audit_checked'))->toBe(1);

    WasteEventLine::query()->findOrFail($line->getKey())->update(['audit_checked' => false]);

    expect($line->fresh()->sm_checked)->toBeFalse()
        ->and($line->fresh()->audit_checked)->toBeFalse();
});
