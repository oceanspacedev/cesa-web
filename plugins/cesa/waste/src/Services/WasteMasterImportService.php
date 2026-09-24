<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class WasteMasterImportService
{
    /**
     * @return array{brand: string, imported: int, inactive: int, skipped: int}
     */
    public function import(string $brandCode, string $path): array
    {
        $brandCode = Str::upper(trim($brandCode));
        $definition = $this->definition($brandCode);

        if (! is_file($path)) {
            throw new RuntimeException("File master {$path} tidak ditemukan.");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($definition['sheet']);
        $previousLibxmlState = libxml_use_internal_errors(true);
        try {
            $workbook = $reader->load($path);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }
        $sheet = $workbook->getSheetByName($definition['sheet']);

        if (! $sheet) {
            throw new RuntimeException("Sheet {$definition['sheet']} tidak ditemukan untuk {$brandCode}.");
        }

        $brand = WasteBrand::query()->where('code', $brandCode)->first();
        if (! $brand) {
            throw new RuntimeException("Brand {$brandCode} belum terdaftar.");
        }

        $counts = DB::transaction(function () use ($sheet, $definition, $brand): array {
            $imported = 0;
            $inactive = 0;
            $skipped = 0;

            for ($rowNumber = $definition['start_row']; $rowNumber <= $sheet->getHighestRow(); $rowNumber++) {
                $values = [];
                foreach (range('A', 'F') as $column) {
                    $cell = $sheet->getCell("{$column}{$rowNumber}");
                    $values[] = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                }
                $item = $this->normalizeRow($brand->code, $values, $definition);

                if (! $item) {
                    $skipped++;

                    continue;
                }

                if ($item['unit'] !== null) {
                    WasteUnit::query()->firstOrCreate(['code' => $item['unit']], [
                        'name'      => $item['unit'],
                        'is_active' => true,
                    ]);
                }

                $importedItem = WasteItem::query()->updateOrCreate(
                    ['brand_id' => $brand->getKey(), 'code' => $item['code']],
                    [
                        'name'              => $item['name'],
                        'unit'              => $item['unit'],
                        'source_unit_label' => $item['source_unit_label'],
                        'item_type'         => $item['item_type'],
                        'source_status'     => $item['source_status'],
                        'is_active'         => $item['is_active'],
                        'notes'             => $item['notes'],
                    ],
                );

                if ($item['item_type'] !== null) {
                    $importedItem->eventLines()
                        ->whereNull('item_type')
                        ->update(['item_type' => $item['item_type']]);
                }

                $imported++;
                $inactive += $item['is_active'] ? 0 : 1;
            }

            return compact('imported', 'inactive', 'skipped');
        });

        return ['brand' => $brand->code, ...$counts];
    }

    /**
     * @return array{sheet: string, start_row: int, name: int, code: int, unit: int, type: ?int}
     */
    protected function definition(string $brandCode): array
    {
        return match ($brandCode) {
            'JCHICKEN' => ['sheet' => 'Master data', 'start_row' => 2, 'name' => 0, 'code' => 1, 'unit' => 2, 'type' => 5],
            'LUUCA'    => ['sheet' => 'MASTER DATA', 'start_row' => 2, 'name' => 0, 'code' => 1, 'unit' => 3, 'type' => 4],
            'MOMOYO'   => ['sheet' => 'Master data', 'start_row' => 3, 'name' => 0, 'code' => 1, 'unit' => 2, 'type' => 3],
            default    => throw new RuntimeException("Brand {$brandCode} belum memiliki mapping master."),
        };
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array{sheet: string, start_row: int, name: int, code: int, unit: int, type: ?int}  $definition
     * @return array<string, mixed>|null
     */
    protected function normalizeRow(string $brandCode, array $values, array $definition): ?array
    {
        $name = trim((string) ($values[$definition['name']] ?? ''));
        $code = strtoupper(trim((string) ($values[$definition['code']] ?? '')));

        if ($name === '' || $code === '' || $name === 'NON PIP') {
            return null;
        }

        $rawSourceUnit = trim((string) ($values[$definition['unit']] ?? ''));
        $sourceUnit = WasteUnit::normalizeCode($rawSourceUnit);
        $unit = $this->correctUnitForAdjustment($brandCode, $code, $sourceUnit);
        $unitCorrected = $unit !== $sourceUnit;
        $wrong = Str::contains(Str::upper($name), 'SALAH');
        $sourceStatus = 'imported';
        $notes = null;
        if ($wrong) {
            $sourceStatus = 'inactive_salah';
            $notes = 'Dinonaktifkan karena penanda SALAH pada master sumber.';
        } elseif ($unit === null) {
            $sourceStatus = 'inactive_missing_unit';
            $notes = 'Dinonaktifkan karena satuan kosong pada master sumber.';
        } elseif ($unitCorrected) {
            $sourceStatus = 'corrected_unit';
            $notes = "Satuan adjustment diperbaiki dari {$sourceUnit} menjadi {$unit} berdasarkan riwayat form.";
        }

        $itemType = $definition['type'] === null
            ? null
            : $this->normalizeItemType($brandCode, $values[$definition['type']] ?? null);

        return [
            'name'              => $name,
            'code'              => $code,
            'unit'              => $unit,
            'source_unit_label' => $sourceUnit === null ? null : $rawSourceUnit,
            'item_type'         => $itemType,
            'source_status'     => $sourceStatus,
            'is_active'         => ! $wrong && $unit !== null,
            'notes'             => $notes,
        ];
    }

    /**
     * The JCHICKEN inventory master lists P004-031 in PRS, while SATUAN CSA and adjustment records use GR.
     */
    protected function correctUnitForAdjustment(string $brandCode, string $code, ?string $unit): ?string
    {
        if ($brandCode === 'JCHICKEN' && $code === 'P004-031' && $unit === 'PRS') {
            return 'GR';
        }

        return $unit;
    }

    protected function normalizeItemType(string $brandCode, mixed $value): ?string
    {
        $type = trim((string) $value);
        if ($type === '') {
            return null;
        }

        if (in_array(Str::upper($type), ['#N/A', 'N/A'], true)) {
            return null;
        }

        if ($brandCode === 'MOMOYO') {
            return Str::upper($type) === 'PIP' ? 'PIP' : 'Bahan Baku';
        }

        return $type;
    }
}
