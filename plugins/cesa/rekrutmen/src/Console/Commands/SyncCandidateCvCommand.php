<?php

namespace Cesa\Rekrutmen\Console\Commands;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Services\RekrutmenStorage;
use Illuminate\Console\Command;

class SyncCandidateCvCommand extends Command
{
    protected $signature = 'rekrutmen:sync-cv {--dry-run : Hanya cek pencocokan tanpa mengubah database}';

    protected $description = 'Cocokkan CV kandidat dengan disk penyimpanan asal dalam modul Rekrutmen';

    public function handle(RekrutmenStorage $storage): int
    {
        $files = $storage->files(JobApplication::RESUME_DIRECTORY);
        if ($files === []) {
            $this->error('Tidak ditemukan berkas CV pada disk penyimpanan Rekrutmen.');

            return self::FAILURE;
        }

        $matched = 0;
        $updated = 0;
        foreach (JobApplication::query()->get(['id', 'resume_path', 'resume_disk']) as $application) {
            $file = $storage->findCandidateResume($application, $files);
            if ($file === null) {
                continue;
            }
            $matched++;
            if ($application->resume_path === $file['path'] && $application->resume_disk === $file['disk']) {
                continue;
            }
            $updated++;
            $this->line('#'.$application->id.' -> '.$file['disk'].':'.$file['path']);
            if (! $this->option('dry-run')) {
                $storage->rememberCandidateResume($application, $file);
            }
        }

        $this->info("Cocok: {$matched}; perlu diperbarui: {$updated}. Lokasi berkas yang ambigu dilewati.");
        if ($this->option('dry-run')) {
            $this->warn('Mode DRY-RUN: database belum diubah.');
        }

        return self::SUCCESS;
    }
}
