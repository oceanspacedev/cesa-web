<?php

namespace Cesa\Waste\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WasteDetailSheet extends WasteTextValueBinder implements FromCollection, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    public function __construct(protected Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Referensi', 'Versi', 'Tanggal Kejadian', 'Brand', 'Outlet', 'Status',
            'Kejadian ke', 'Section', 'Kategori', 'Alasan', 'PIP', 'Jumlah PIP', 'Satuan PIP',
            'Kode Barang', 'Nama Barang', 'Satuan', 'Jumlah', 'Peran Baris', 'Pelapor', 'SM', 'AUDIT',
        ];
    }

    public function title(): string
    {
        return 'Detail';
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
