<?php

namespace Cesa\Waste\Exports;

use Cesa\Waste\Enums\WasteReportStatus;
use Cesa\Waste\Models\WasteEventLine;
use Cesa\Waste\Models\WasteReport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WasteTemplateSheet extends WasteTextValueBinder implements FromCollection, WithColumnWidths, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  Collection<int, WasteReport>  $reports
     */
    public function __construct(
        protected Collection $reports,
        protected string $sheetTitle,
    ) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function collection(): Collection
    {
        $report = $this->reports->first();
        $brandCode = strtoupper($report->brand->code);
        $rows = $this->titleRows($report, $brandCode);
        $lastWrittenDate = null;

        foreach ($this->reports as $report) {
            foreach ($report->latestVersion?->events->sortBy('sequence') ?? [] as $event) {
                foreach ($event->lines->sortBy('id')->values() as $lineIndex => $line) {
                    $date = $report->event_date;
                    $dateKey = $date->format('Y-m-d');
                    $showDate = $lastWrittenDate !== $dateKey;
                    $quantity = (float) $line->quantity;

                    $rows[] = match ($brandCode) {
                        'JCHICKEN' => [
                            $showDate ? $date->day : null,
                            $line->item_name,
                            $line->item_code,
                            $this->itemType($line, $report),
                            $quantity,
                            $line->unit,
                            $event->reason,
                            $report->reporterNameForTemplate(),
                            $event->section,
                            $event->category_name,
                            $line->sm_checked,
                            $line->audit_checked,
                        ],
                        'LUUCA' => [
                            $showDate ? Date::dateTimeToExcel($date) : null,
                            $line->item_name,
                            $line->item_code,
                            $this->itemType($line, $report),
                            $quantity,
                            $line->unit,
                            $event->reason,
                            $report->reporterNameForTemplate(),
                            $event->category_name,
                            $line->audit_checked,
                        ],
                        'MOMOYO' => [
                            Date::dateTimeToExcel($date),
                            $event->pip_item_name ?: 'NON PIP',
                            $line->item_name,
                            $line->item_code,
                            $event->pip_quantity !== null && $lineIndex === 0
                                ? (float) $event->pip_quantity
                                : null,
                            $quantity,
                            $line->unit_label ?: $line->unit,
                            $event->category_name,
                        ],
                    };

                    $lastWrittenDate = $dateKey;
                }
            }
        }

        return collect($rows);
    }

    protected function itemType(WasteEventLine $line, WasteReport $report): ?string
    {
        if (filled($line->item_type)) {
            return $line->item_type;
        }

        return (int) $line->item?->brand_id === (int) $report->brand_id
            ? $line->item?->item_type
            : null;
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    protected function titleRows(WasteReport $report, string $brandCode): array
    {
        $month = strtoupper($report->event_date->locale('id')->translatedFormat('F Y'));
        $statusLabel = $report->status === WasteReportStatus::Approved
            ? null
            : strtoupper($report->status?->value ?? '');

        if ($brandCode === 'MOMOYO') {
            return [
                ['DIVISI : BAR & SERVICES'],
                ['TAHUN : '.$report->event_date->format('Y')],
                ['BULAN : '.strtoupper($report->event_date->locale('id')->translatedFormat('F'))],
                [$statusLabel === null ? null : 'STATUS : '.$statusLabel],
                ['TGL', 'NAMA PIP', 'NAMA BARANG', 'KODE ITEM', 'QTY PIP', 'QTY', 'UNIT', 'KETERANGAN'],
            ];
        }

        return [
            ['FORM ADJUSTMENT'],
            ['PT. KREASI LIMA CAHAYA'],
            [strtoupper($report->brand->name.' '.$report->outlet->name)],
            ['BULAN, TAHUN: '.$month.($statusLabel === null ? '' : ' | STATUS: '.$statusLabel)],
            [null],
            $brandCode === 'JCHICKEN'
                ? ['TANGGAL', 'NAMA PRODUK', 'KODE CSA', 'JENIS', 'JUMLAH', 'SATUAN CSA', 'ALASAN WASTE', 'USER', 'SECTION', 'KATEGORI', 'SM', 'AUDIT']
                : ['TANGGAL', 'NAMA PRODUK', 'KODE CSA', 'JENIS', 'JUMLAH', 'SATUAN CSA', 'ALASAN WASTE', 'USER', 'KATEGORI', 'AUDIT'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return strtoupper($this->reports->first()->brand->code) === 'MOMOYO'
            ? ['A' => 14, 'B' => 28, 'C' => 36, 'D' => 20, 'E' => 16, 'F' => 14, 'G' => 14, 'H' => 28]
            : (strtoupper($this->reports->first()->brand->code) === 'JCHICKEN'
                ? ['A' => 16, 'B' => 38, 'C' => 20, 'D' => 25, 'E' => 16, 'F' => 17, 'G' => 54, 'H' => 25, 'I' => 20, 'J' => 20, 'K' => 12, 'L' => 12, 'M' => 28, 'N' => 54]
                : ['A' => 16, 'B' => 38, 'C' => 20, 'D' => 25, 'E' => 16, 'F' => 17, 'G' => 54, 'H' => 25, 'I' => 20, 'J' => 20]);
    }

    public function styles(Worksheet $sheet): array
    {
        $brandCode = strtoupper($this->reports->first()->brand->code);
        $isMomoyo = $brandCode === 'MOMOYO';
        $lastColumn = match ($brandCode) {
            'MOMOYO'   => 'H',
            'JCHICKEN' => 'L',
            default    => 'J',
        };
        $printLastColumn = $brandCode === 'JCHICKEN' ? 'N' : $lastColumn;
        $headingRow = $isMomoyo ? 5 : 6;
        $firstDataRow = $headingRow + 1;

        if ($brandCode === 'JCHICKEN') {
            foreach ([
                'M7'  => 'Avoidable food waste',
                'M9'  => 'unavoidable food waste',
                'M11' => 'Preparation Waste',
                'M13' => 'Operational/Kitchen Waste',
                'M15' => 'Plate waste',
                'N6'  => 'Waste',
                'N7'  => 'Produksi berlebihan',
                'N8'  => 'Kesalahan produksi',
                'N9'  => 'sampah makanan yang tidak dapat dihindari dan tidak dapat digunakan',
            ] as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            $sheet->getStyle('M6:N15')->getAlignment()->setWrapText(true);
        }

        $lastDataRow = max($firstDataRow, $sheet->getHighestRow());

        if (! $isMomoyo) {
            foreach (range(1, 4) as $row) {
                $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            }
            $sheet->getStyle("A1:{$lastColumn}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A1:{$lastColumn}4")->getFont()->setBold(true)->setSize(13);
        } else {
            $sheet->getStyle('A1:A3')->getFont()->setBold(true);
        }

        $sheet->getStyle("A{$headingRow}:{$lastColumn}{$headingRow}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => '000000']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => 'FFF200']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($headingRow)->setRowHeight(30);
        $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'D1D5DB']]],
        ]);
        $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $quantityColumn = $isMomoyo ? 'F' : 'E';
        $this->formatQuantityColumn($sheet, $quantityColumn, $firstDataRow, $lastDataRow);

        if ($isMomoyo) {
            $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getNumberFormat()->setFormatCode('mm-dd-yy');
            $this->formatQuantityColumn($sheet, 'E', $firstDataRow, $lastDataRow);
        } elseif ($brandCode === 'LUUCA') {
            $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        }

        $sheet->setAutoFilter("A{$headingRow}:{$lastColumn}{$lastDataRow}");
        $sheet->freezePane("A{$firstDataRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A3)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setPrintArea("A1:{$printLastColumn}{$lastDataRow}")
            ->setRowsToRepeatAtTopByStartAndEnd(1, $headingRow);

        return [];
    }

    protected function formatQuantityColumn(Worksheet $sheet, string $column, int $firstDataRow, int $lastDataRow): void
    {
        $sheet->getStyle("{$column}{$firstDataRow}:{$column}{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('#,##0');

        for ($row = $firstDataRow; $row <= $lastDataRow; $row++) {
            $cell = "{$column}{$row}";
            $value = $sheet->getCell($cell)->getValue();

            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            if (floor((float) $value) !== (float) $value) {
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.####');
            }
        }
    }
}
