<?php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

function loadSheet(string $path, string $sheetName): PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $reader->setLoadSheetsOnly($sheetName);

    return $reader->load($path)->getSheetByName($sheetName);
}

function val($sheet, string $col, int $r)
{
    $cell = $sheet->getCell($col.$r);

    return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
}

function num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '';
    }

    return number_format((float) str_replace(',', '.', (string) $v), 4, '.', '');
}

function flag(mixed $v): string
{
    return ($v === true || $v === 1 || $v === '1') ? '1' : '0';
}

$fixture = json_decode(file_get_contents('plugins/cesa/waste/tests/Fixtures/september_2026_source_replay.json'), true);
$exportPath = 'storage/app/private/waste/qa_exports/waste-bulanan-2026-09-qa-replay2.xlsx';
$reader = IOFactory::createReaderForFile($exportPath);
$reader->setReadDataOnly(true);
$export = $reader->load($exportPath);

// Master maps: code => [jenis, raw unit label, name]
$masters = [
    'JCHICKEN' => loadSheet('waste/Waste Form Adjustment Jchicken Ciledug .xlsx', 'Master data'),
    'LUUCA'    => loadSheet('waste/Form Adjustment Luuca Ciledug 2026.xlsx', 'MASTER DATA'),
    'MOMOYO'   => loadSheet('waste/1. Adjustment Diluar Produksi MOMOYO 2026.xlsx', 'Master data'),
];
$masterMap = [];
foreach ($masters as $brand => $sheet) {
    $start = $brand === 'MOMOYO' ? 3 : 2;
    $end = $brand === 'JCHICKEN' ? 429 : ($brand === 'LUUCA' ? 212 : 236);
    for ($r = $start; $r <= $end; $r++) {
        $code = strtoupper(trim((string) val($sheet, 'B', $r)));
        if ($code === '') {
            continue;
        }
        $jenis = $brand === 'JCHICKEN' ? trim((string) val($sheet, 'F', $r)) : ($brand === 'LUUCA' ? trim((string) val($sheet, 'E', $r)) : '');
        $unitLabel = trim((string) val($sheet, 'C', $r));
        $name = trim((string) val($sheet, 'A', $r));
        $masterMap[$brand][$code] = ['jenis' => $jenis, 'unit_label' => $unitLabel, 'name' => $name];
    }
}

// Source monthly sheets: USER column H (JC/LUUCA)
$srcSheets = [
    'JCHICKEN' => loadSheet('waste/Waste Form Adjustment Jchicken Ciledug .xlsx', 'SEPTEMBER 26'),
    'LUUCA'    => loadSheet('waste/Form Adjustment Luuca Ciledug 2026.xlsx', 'SEPTEMBER 26'),
];

$exportSheetNames = ['JCHICKEN' => 'JCHICKEN CILEDUG SEP 26', 'LUUCA' => 'LUUCA CILEDUG SEP 26', 'MOMOYO' => 'MOMOYO CILEDUG SEP 26'];

$issues = [];
$grandExpected = 0;
$grandActual = 0;

foreach (['JCHICKEN', 'LUUCA', 'MOMOYO'] as $brand) {
    $expected = [];
    $lastDate = null;

    foreach ($fixture['brands'][$brand]['rows'] as $row) {
        $dateKey = $row['date'];
        $showDate = $lastDate !== $dateKey;
        $lastDate = $dateKey;
        $code = strtoupper($row['item_code']);
        $master = $masterMap[$brand][$code] ?? null;

        if ($brand === 'JCHICKEN') {
            $user = trim((string) val($srcSheets['JCHICKEN'], 'H', (int) $row['source_row']));
            $tuple = [
                'day'    => $showDate ? (string) (int) val($srcSheets['JCHICKEN'], 'A', (int) $row['source_row']) : '',
                'jenis'  => $master['jenis'] ?? '?',
                'code'   => $code,
                'qty'    => num($row['quantity']),
                'unit'   => $row['unit'],
                'reason' => trim((string) $row['reason']),
                'user'   => $user,
                'extra1' => $row['section'],
                'extra2' => $row['category'],
                'flag1'  => flag($row['sm'] ?? false),
                'flag2'  => flag($row['audit'] ?? false),
            ];
        } elseif ($brand === 'LUUCA') {
            $user = trim((string) val($srcSheets['LUUCA'], 'H', (int) $row['source_row']));
            $serial = SpreadsheetDate::dateTimeToExcel(new DateTime($row['date']));
            $tuple = [
                'day'    => $showDate ? num($serial) : '',
                'jenis'  => $master['jenis'] ?? '?',
                'code'   => $code,
                'qty'    => num($row['quantity']),
                'unit'   => $row['unit'],
                'reason' => trim((string) $row['reason']),
                'user'   => $user,
                'extra1' => $row['category'],
                'extra2' => flag($row['audit'] ?? false),
            ];
        } else {
            $serial = SpreadsheetDate::dateTimeToExcel(new DateTime($row['date']));
            $pipCode = $row['pip_code'] ?? null;
            $tuple = [
                'day'     => num($serial),
                'pip'     => $pipCode ? ($masterMap[$brand][$pipCode]['name'] ?? $pipCode) : 'NON PIP',
                'name'    => $master['name'] ?? '?',
                'code'    => $code,
                'pip_qty' => isset($row['pip_quantity']) ? num($row['pip_quantity']) : '',
                'qty'     => num($row['quantity']),
                'unit'    => $master['unit_label'] ?? '?',
                'extra1'  => $row['category'],
            ];
        }

        $key = json_encode(array_values($tuple), JSON_UNESCAPED_UNICODE);
        $expected[$key] = ($expected[$key] ?? 0) + 1;
        $grandExpected++;
    }

    // Actual: read export sheet
    $sheet = $export->getSheetByName($exportSheetNames[$brand]);
    $actual = [];
    $lastDay = '';
    $carry = '';
    $headerRow = $brand === 'MOMOYO' ? 5 : 6;
    $legendRows = 0;

    for ($r = $headerRow + 1; $r <= $sheet->getHighestRow(); $r++) {
        $codeCol = $brand === 'MOMOYO' ? 'D' : 'C';
        $code = strtoupper(trim((string) val($sheet, $codeCol, $r)));
        $nameB = trim((string) val($sheet, 'B', $r));

        if ($code === '') {
            // Legend rows carry text in M/N (JCHICKEN); anything else without a code is suspicious
            $leftover = [];
            foreach (range('A', 'N') as $col) {
                $v = val($sheet, $col, $r);
                if ($v !== null && $v !== '') {
                    $leftover[] = $col;
                }
            }
            if ($leftover !== []) {
                $legendRows++;
            }
            continue;
        }

        if ($brand === 'JCHICKEN') {
            $day = trim((string) val($sheet, 'A', $r));
            if ($day !== '') {
                $carry = $day;
            }
            $tuple = [
                'day'    => $day !== '' ? (string) (int) $day : '',
                'jenis'  => trim((string) val($sheet, 'D', $r)),
                'code'   => $code,
                'qty'    => num(val($sheet, 'E', $r)),
                'unit'   => trim((string) val($sheet, 'F', $r)),
                'reason' => trim((string) val($sheet, 'G', $r)),
                'user'   => trim((string) val($sheet, 'H', $r)),
                'extra1' => trim((string) val($sheet, 'I', $r)),
                'extra2' => trim((string) val($sheet, 'J', $r)),
                'flag1'  => flag(val($sheet, 'K', $r)),
                'flag2'  => flag(val($sheet, 'L', $r)),
            ];
        } elseif ($brand === 'LUUCA') {
            $day = trim((string) val($sheet, 'A', $r));
            if ($day !== '') {
                $carry = $day;
            }
            $tuple = [
                'day'    => $day !== '' ? num($day) : '',
                'jenis'  => trim((string) val($sheet, 'D', $r)),
                'code'   => $code,
                'qty'    => num(val($sheet, 'E', $r)),
                'unit'   => trim((string) val($sheet, 'F', $r)),
                'reason' => trim((string) val($sheet, 'G', $r)),
                'user'   => trim((string) val($sheet, 'H', $r)),
                'extra1' => trim((string) val($sheet, 'I', $r)),
                'extra2' => flag(val($sheet, 'J', $r)),
            ];
        } else {
            $tuple = [
                'day'     => num(val($sheet, 'A', $r)),
                'pip'     => trim((string) val($sheet, 'B', $r)),
                'name'    => trim((string) val($sheet, 'C', $r)),
                'code'    => $code,
                'pip_qty' => num(val($sheet, 'E', $r)),
                'qty'     => num(val($sheet, 'F', $r)),
                'unit'    => trim((string) val($sheet, 'G', $r)),
                'extra1'  => trim((string) val($sheet, 'H', $r)),
            ];
        }

        $key = json_encode(array_values($tuple), JSON_UNESCAPED_UNICODE);
        $actual[$key] = ($actual[$key] ?? 0) + 1;
        $grandActual++;
    }

    // Diff
    $allKeys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
    $missing = 0;
    $extra = 0;
    $samples = [];
    foreach ($allKeys as $key) {
        $e = $expected[$key] ?? 0;
        $a = $actual[$key] ?? 0;
        if ($e === $a) {
            continue;
        }
        $diff = $a - $e;
        if ($diff < 0) {
            $missing += -$diff;
        } else {
            $extra += $diff;
        }
        if (count($samples) < 5) {
            $samples[] = ['expected_x'.$e => json_decode($key, true), 'actual_x'.$a => json_decode($key, true)];
        }
    }

    echo "=== $brand ===" . PHP_EOL;
    echo 'expected rows: '.array_sum($expected).", actual rows: ".array_sum($actual).", legend/extra rows: $legendRows" . PHP_EOL;
    echo 'missing: '.$missing.', extra: '.$extra . PHP_EOL;
    foreach ($samples as $s) {
        echo '  MISMATCH exp='.json_encode($s, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

echo PHP_EOL."TOTAL expected=$grandExpected actual=$grandActual" . PHP_EOL;
