<?php

namespace Cesa\Waste\Exports;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WasteMasterSheet extends WasteTextValueBinder implements FromCollection, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(
        protected WasteBrand $brand,
        protected string $sheetTitle,
    ) {}

    public function collection(): Collection
    {
        return $this->brand->items()
            ->orderBy('name')
            ->orderBy('code')
            ->get(['name', 'code', 'source_unit_label', 'unit', 'item_type'])
            ->map(fn (WasteItem $item): array => [
                $item->name,
                $item->code,
                $item->source_unit_label ?: $item->unit,
                $item->item_type,
            ]);
    }

    public function headings(): array
    {
        return ['Nama Item', 'Kode Item', 'Satuan', 'JENIS'];
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '1D4ED8']],
            ],
        ];
    }
}
