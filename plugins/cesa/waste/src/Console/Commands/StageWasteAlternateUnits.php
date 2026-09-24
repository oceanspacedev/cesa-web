<?php

namespace Cesa\Waste\Console\Commands;

use Cesa\Waste\Services\WasteAlternateUnitCandidateService;
use Illuminate\Console\Command;
use Throwable;

class StageWasteAlternateUnits extends Command
{
    protected $signature = 'waste:stage-alternate-units
        {file : Path file adjustment Jchicken}
        {--sheet= : Nama sheet bulanan, default SEPTEMBER 26}';

    protected $description = 'Catat kandidat satuan alternatif dari adjustment Jchicken untuk ditinjau admin.';

    public function handle(WasteAlternateUnitCandidateService $service): int
    {
        try {
            $result = $service->stageJchickenWorkbook(
                (string) $this->argument('file'),
                (string) ($this->option('sheet') ?: 'SEPTEMBER 26'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d pasangan kandidat dari %d baris; %d baru, %d diperbarui. Semua kandidat baru menunggu persetujuan admin.',
            $result['found'],
            $result['source_rows'],
            $result['created'],
            $result['refreshed'],
        ));

        return self::SUCCESS;
    }
}
