<?php

namespace Cesa\Waste\Console\Commands;

use Cesa\Waste\Services\WasteMasterImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportWasteMaster extends Command
{
    protected $signature = 'waste:import-master
        {--jchicken= : Path file master Jchicken}
        {--luuca= : Path file master Luuca}
        {--momoyo= : Path file master MOMOYO}';

    protected $description = 'Import master barang waste berdasarkan brand dan kode barang.';

    public function handle(WasteMasterImportService $service): int
    {
        $paths = [
            'JCHICKEN' => $this->option('jchicken'),
            'LUUCA'    => $this->option('luuca'),
            'MOMOYO'   => $this->option('momoyo'),
        ];
        $paths = array_filter($paths);

        if ($paths === []) {
            $this->components->error('Isi minimal satu opsi file: --jchicken, --luuca, atau --momoyo.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($paths as $brandCode => $path) {
            try {
                $result = $service->import($brandCode, (string) $path);
                $this->components->info(sprintf(
                    '%s: %d item diimpor, %d nonaktif, %d dilewati.',
                    $brandCode,
                    $result['imported'],
                    $result['inactive'],
                    $result['skipped'],
                ));
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error("{$brandCode}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
