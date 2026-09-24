<?php

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Services\WasteMasterImportService;
use Cesa\Waste\Services\WasteReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

it('imports Luuca item types from the master JENIS column', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    importLuucaTypes([
        ['BUAH STRAWBERRY', 'BB-100500', 'B004', 'GR', 'BAHAN BAKU LUUCA'],
        ['WIP LUUCA', 'WIP-001', 'P004', 'GR', 'PRODUKSI STOK LUUCA'],
    ]);

    expect(WasteItem::query()->where('brand_id', $brand->id)->where('code', 'BB-100500')->value('item_type'))->toBe('BAHAN BAKU LUUCA')
        ->and(WasteItem::query()->where('brand_id', $brand->id)->where('code', 'WIP-001')->value('item_type'))->toBe('PRODUKSI STOK LUUCA');
});

it('fills missing report item types after import without replacing saved snapshots', function (): void {
    $brand = WasteBrand::query()->create(['name' => 'Luuca', 'code' => 'LUUCA', 'is_active' => true]);
    $outlet = WasteOutlet::query()->create([
        'brand_id' => $brand->getKey(), 'name' => 'Ciledug', 'code' => 'CILEDUG',
        'slug'     => 'luuca-ciledug', 'timezone' => 'Asia/Jakarta', 'is_active' => true,
    ]);
    $item = WasteItem::query()->create([
        'brand_id' => $brand->getKey(), 'code' => 'BB-100500', 'name' => 'BUAH STRAWBERRY',
        'unit'     => 'GR', 'item_type' => null, 'is_active' => true,
    ]);
    $category = WasteCategory::query()->create([
        'brand_id' => $brand->getKey(), 'code' => 'WASTE', 'name' => 'Waste', 'is_active' => true,
    ]);
    $service = app(WasteReportService::class);

    $missingReport = $service->saveByAdmin(itemTypeSnapshotPayload($brand, $outlet, $item, $category), null, null);
    $missingLineId = $missingReport->latestVersion->events->first()->lines->first()->getKey();

    $item->update(['item_type' => 'JENIS HISTORIS']);
    $savedReport = $service->saveByAdmin(itemTypeSnapshotPayload($brand, $outlet, $item, $category), null, null);
    $savedLineId = $savedReport->latestVersion->events->first()->lines->first()->getKey();
    $item->update(['item_type' => null]);

    importLuucaTypes([
        ['BUAH STRAWBERRY', 'BB-100500', 'B004', 'GR', 'BAHAN BAKU LUUCA'],
    ]);

    expect(DB::table('waste_event_lines')->where('id', $missingLineId)->value('item_type'))->toBe('BAHAN BAKU LUUCA')
        ->and(DB::table('waste_event_lines')->where('id', $savedLineId)->value('item_type'))->toBe('JENIS HISTORIS')
        ->and($item->fresh()->item_type)->toBe('BAHAN BAKU LUUCA');
});

it('keeps the reported item type when the master changes later', function (): void {
    [$brand, $outlet, $item, $category] = itemTypeSnapshotCatalog();
    $report = app(WasteReportService::class)->saveByAdmin(itemTypeSnapshotPayload($brand, $outlet, $item, $category), null, null);
    $line = $report->latestVersion->events->first()->lines->first();

    $item->update(['item_type' => 'barang jadi & bahan baku']);

    expect($line->fresh()->item_type)->toBe('bahan baku')
        ->and($item->fresh()->item_type)->toBe('barang jadi & bahan baku');
});

it('backfills item types for existing report lines when the migration runs', function (): void {
    [$brand, $outlet, $item, $category] = itemTypeSnapshotCatalog();
    $report = app(WasteReportService::class)->saveByAdmin(itemTypeSnapshotPayload($brand, $outlet, $item, $category), null, null);
    $lineId = $report->latestVersion->events->first()->lines->first()->getKey();
    $migration = require __DIR__.'/../../database/migrations/2026_09_24_081340_add_item_type_to_waste_event_lines_table.php';

    $migration->down();
    expect(Schema::hasColumn('waste_event_lines', 'item_type'))->toBeFalse();

    $migration->up();

    expect(DB::table('waste_event_lines')->where('id', $lineId)->value('item_type'))->toBe('bahan baku');
});

/**
 * @return array{WasteBrand, WasteOutlet, WasteItem, WasteCategory}
 */
function itemTypeSnapshotCatalog(): array
{
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

    return [$brand, $outlet, $item, $category];
}

/**
 * @return array<string, mixed>
 */
function itemTypeSnapshotPayload(WasteBrand $brand, WasteOutlet $outlet, WasteItem $item, WasteCategory $category): array
{
    return [
        'brand_id'       => $brand->getKey(),
        'outlet_id'      => $outlet->getKey(),
        'event_date'     => '2026-09-23',
        'status'         => 'approved',
        'reporter_name'  => 'Admin',
        'reporter_phone' => '081234567890',
        'events'         => [[
            'category_id' => $category->getKey(),
            'reason'      => 'Kualitas tidak layak',
            'lines'       => [['item_id' => $item->getKey(), 'quantity' => '2.5']],
        ]],
    ];
}

/**
 * @param  array<int, array<int, string>>  $rows
 */
function importLuucaTypes(array $rows): void
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()
        ->setTitle('MASTER DATA')
        ->fromArray([
            ['Nama Item', 'Kode Item', 'Kelompok', 'Sat', 'JENIS'],
            ...$rows,
        ]);
    $path = tempnam(sys_get_temp_dir(), 'waste-luuca-types-');
    (new Xlsx($spreadsheet))->save($path);

    try {
        app(WasteMasterImportService::class)->import('LUUCA', $path);
    } finally {
        @unlink($path);
        $spreadsheet->disconnectWorksheets();
    }
}
