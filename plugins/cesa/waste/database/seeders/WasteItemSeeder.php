<?php

namespace Cesa\Waste\Database\Seeders;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Illuminate\Database\Seeder;

class WasteItemSeeder extends Seeder
{
    /**
     * Data master item per brand diekstrak dari workbook sumber yang telah
     * diverifikasi QA; baris tanpa satuan atau bertanda SALAH disimpan
     * nonaktif dengan catatannya masing-masing.
     */
    public function run(): void
    {
        $items = require __DIR__.'/data/waste-items.php';

        foreach ($items as $brandCode => $rows) {
            $brandId = WasteBrand::query()->where('code', $brandCode)->value('id');

            if (! $brandId) {
                continue;
            }

            foreach ($rows as [$name, $code, $unit, $itemType, $sourceUnitLabel, $sourceStatus, $notes, $isActive]) {
                WasteItem::query()->updateOrCreate(
                    ['brand_id' => $brandId, 'code' => $code],
                    [
                        'name'              => $name,
                        'unit'              => $unit,
                        'item_type'         => $itemType,
                        'source_unit_label' => $sourceUnitLabel,
                        'source_status'     => $sourceStatus,
                        'notes'             => $notes,
                        'is_active'         => $isActive,
                    ],
                );
            }
        }
    }
}
