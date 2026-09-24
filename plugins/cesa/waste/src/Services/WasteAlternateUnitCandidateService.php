<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Enums\WasteAlternateUnitCandidateStatus;
use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Models\WasteItemUnitCandidate;
use Cesa\Waste\Models\WasteUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Webkul\Security\Models\User;

class WasteAlternateUnitCandidateService
{
    /**
     * @return array{found: int, created: int, refreshed: int, source_rows: int}
     */
    public function stageJchickenWorkbook(string $path, string $sheetName = 'SEPTEMBER 26'): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("File adjustment {$path} tidak ditemukan.");
        }

        $brand = WasteBrand::query()->where('code', 'JCHICKEN')->firstOrFail();
        $items = WasteItem::query()->where('brand_id', $brand->getKey())->get()->keyBy('code');
        $units = WasteUnit::query()->get()->keyBy('code');

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly($sheetName);
        $previousLibxmlState = libxml_use_internal_errors(true);
        try {
            $workbook = $reader->load($path);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        try {
            $sheet = $workbook->getSheetByName($sheetName);
            if (! $sheet) {
                throw new RuntimeException("Sheet {$sheetName} tidak ditemukan.");
            }

            if (trim((string) $sheet->getCell('C6')->getValue()) !== 'KODE CSA'
                || trim((string) $sheet->getCell('F6')->getValue()) !== 'SATUAN CSA') {
                throw new RuntimeException("Sheet {$sheetName} bukan form adjustment Jchicken yang didukung.");
            }

            $candidates = [];
            $missing = [];
            for ($row = 7; $row <= $sheet->getHighestRow(); $row++) {
                $codeCell = $sheet->getCell("C{$row}");
                $unitCell = $sheet->getCell("F{$row}");
                $codeValue = $this->cachedCellValue($codeCell);
                $quantityValue = $this->cachedCellValue($sheet->getCell("E{$row}"));
                $unitValue = $this->cachedCellValue($unitCell);
                $code = strtoupper(trim((string) $codeValue));
                $rawUnit = trim((string) $unitValue);

                if (is_numeric($quantityValue) && (float) $quantityValue > 0
                    && ($codeCell->isFormula() && $codeValue === null || $unitCell->isFormula() && $unitValue === null)) {
                    $missing[] = "C/F{$row}: hasil rumus Excel belum tersimpan";

                    continue;
                }

                if ($code === '' || ! is_numeric($quantityValue) || (float) $quantityValue <= 0 || $rawUnit === '') {
                    continue;
                }

                $unitCode = WasteUnit::normalizeCode(rtrim($rawUnit, " \t\n\r\0\x0B:"));
                $item = $items->get($code);
                if (! $item) {
                    $missing[] = "C{$row}: {$code} tidak ada di Master Barang";

                    continue;
                }

                if ($unitCode === null || $unitCode === $item->unit) {
                    continue;
                }

                $unit = $units->get($unitCode);
                if (! $unit) {
                    $missing[] = "F{$row}: {$rawUnit} tidak ada di Master Satuan";

                    continue;
                }

                $key = "{$item->getKey()}:{$unit->getKey()}";
                if (! isset($candidates[$key])) {
                    $candidates[$key] = [
                        'item_id'            => $item->getKey(),
                        'unit_id'            => $unit->getKey(),
                        'example_row'        => $row,
                        'source_row_count'   => 0,
                        'source_unit_labels' => [],
                    ];
                }

                $candidates[$key]['source_row_count']++;
                $candidates[$key]['source_unit_labels'][$rawUnit] = $rawUnit;
            }

            if ($missing !== []) {
                throw new RuntimeException('Impor dibatalkan karena mapping master belum lengkap: '.implode('; ', array_slice($missing, 0, 5)).'.');
            }

            return DB::transaction(function () use ($candidates, $path, $sheetName): array {
                $created = 0;
                $refreshed = 0;

                foreach ($candidates as $candidateData) {
                    $candidate = WasteItemUnitCandidate::query()->firstOrNew([
                        'item_id' => $candidateData['item_id'],
                        'unit_id' => $candidateData['unit_id'],
                    ]);
                    $created += $candidate->exists ? 0 : 1;
                    $refreshed += $candidate->exists ? 1 : 0;
                    $candidate->fill([
                        'source_file'        => basename($path),
                        'source_sheet'       => $sheetName,
                        'example_row'        => $candidateData['example_row'],
                        'source_row_count'   => $candidateData['source_row_count'],
                        'source_unit_labels' => array_values($candidateData['source_unit_labels']),
                    ]);
                    if (! $candidate->exists) {
                        $candidate->status = WasteAlternateUnitCandidateStatus::Pending;
                    }
                    $candidate->save();
                }

                return [
                    'found'       => count($candidates),
                    'created'     => $created,
                    'refreshed'   => $refreshed,
                    'source_rows' => array_sum(array_column($candidates, 'source_row_count')),
                ];
            });
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function approve(WasteItemUnitCandidate $candidate, User $reviewer): void
    {
        Gate::forUser($reviewer)->authorize('update', $candidate);

        DB::transaction(function () use ($candidate, $reviewer): void {
            $candidate = WasteItemUnitCandidate::query()->with(['item', 'unit'])->lockForUpdate()->findOrFail($candidate->getKey());
            $this->requirePending($candidate);

            if (! $candidate->item->is_active || ! $candidate->unit->is_active) {
                throw ValidationException::withMessages(['candidate' => 'Barang dan satuan harus aktif sebelum disetujui.']);
            }

            if ($candidate->item->unit === $candidate->unit->code) {
                throw ValidationException::withMessages(['candidate' => 'Satuan ini sudah menjadi satuan utama barang.']);
            }

            $candidate->item->alternateUnits()->syncWithoutDetaching([$candidate->unit_id]);
            $candidate->update([
                'status'      => WasteAlternateUnitCandidateStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => null,
            ]);
        });
    }

    public function reject(WasteItemUnitCandidate $candidate, User $reviewer, string $reason): void
    {
        Gate::forUser($reviewer)->authorize('update', $candidate);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['review_note' => 'Alasan penolakan wajib diisi.']);
        }

        DB::transaction(function () use ($candidate, $reviewer, $reason): void {
            $candidate = WasteItemUnitCandidate::query()->with('item')->lockForUpdate()->findOrFail($candidate->getKey());
            $this->requirePending($candidate);
            $candidate->item->alternateUnits()->detach($candidate->unit_id);
            $candidate->update([
                'status'      => WasteAlternateUnitCandidateStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);
        });
    }

    public function reopen(WasteItemUnitCandidate $candidate, User $reviewer): void
    {
        Gate::forUser($reviewer)->authorize('update', $candidate);

        DB::transaction(function () use ($candidate): void {
            $candidate = WasteItemUnitCandidate::query()->with('item')->lockForUpdate()->findOrFail($candidate->getKey());
            if ($candidate->status === WasteAlternateUnitCandidateStatus::Pending) {
                return;
            }

            $candidate->item->alternateUnits()->detach($candidate->unit_id);
            $candidate->update([
                'status'      => WasteAlternateUnitCandidateStatus::Pending,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ]);
        });
    }

    protected function requirePending(WasteItemUnitCandidate $candidate): void
    {
        if ($candidate->status !== WasteAlternateUnitCandidateStatus::Pending) {
            throw ValidationException::withMessages(['candidate' => 'Kandidat ini sudah ditinjau. Buka ulang sebelum mengubah keputusan.']);
        }
    }

    protected function cachedCellValue(Cell $cell): mixed
    {
        return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
    }
}
