<?php

namespace Cesa\Rekrutmen\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncStorageToS3Command extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rekrutmen:sync-storage-to-s3
                            {--disk=s3 : Target disk filesystem (default: s3)}
                            {--folder=rekrutmen : Subfolder di storage/app/public yang akan disinkronkan}
                            {--force : Timpa berkas jika sudah ada di S3}
                            {--dry-run : Hanya simulasi hitung berkas tanpa mengupload}';

    /**
     * @var string
     */
    protected $description = 'Upload semua berkas rekrutmen (CV, foto, poster lowongan) dari penyimpanan lokal ke SeaweedFS S3';

    public function handle(): int
    {
        $targetDisk = (string) $this->option('disk');
        $subFolder = trim((string) $this->option('folder'), '/\\');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('=== SINKRONISASI BERKAS REKRUTMEN KE S3 ===');
        $this->line("Target Disk   : <comment>{$targetDisk}</comment>");
        $this->line("Subfolder     : <comment>storage/app/public/{$subFolder}</comment>");
        $this->line('Mode Force    : <comment>'.($force ? 'Ya (Timpa file yang ada)' : 'Tidak (Lewati file yang sudah ada)').'</comment>');
        $this->line('Mode Dry-Run  : <comment>'.($dryRun ? 'Ya (Simulasi)' : 'Tidak (Upload nyata)').'</comment>');
        $this->newLine();

        if (! config()->has("filesystems.disks.{$targetDisk}")) {
            $this->error("Disk '{$targetDisk}' tidak ditemukan dalam konfigurasi filesystems.");

            return self::FAILURE;
        }

        $sourcePath = storage_path('app/public/'.$subFolder);
        if (! File::isDirectory($sourcePath)) {
            $this->error("Direktori lokal tidak ditemukan: {$sourcePath}");

            return self::FAILURE;
        }

        $this->info('Memindai berkas lokal di '.$sourcePath.'...');
        $allFiles = File::allFiles($sourcePath);
        $totalCount = count($allFiles);

        if ($totalCount === 0) {
            $this->warn('Tidak ada berkas yang ditemukan untuk disinkronkan.');

            return self::SUCCESS;
        }

        $totalBytes = 0;
        foreach ($allFiles as $file) {
            $totalBytes += $file->getSize();
        }

        $totalSizeMB = round($totalBytes / (1024 * 1024), 2);
        $totalSizeGB = round($totalBytes / (1024 * 1024 * 1024), 2);

        $this->info("Ditemukan {$totalCount} berkas (Total ukuran: {$totalSizeMB} MB / ~{$totalSizeGB} GB).");
        $this->newLine();

        if ($dryRun) {
            $this->info('[DRY-RUN] Simulasi selesai. Tidak ada berkas yang diunggah ke S3.');

            return self::SUCCESS;
        }

        // Test S3 connection before processing
        try {
            $testKey = 'rekrutmen/.sync-check-'.time();
            Storage::disk($targetDisk)->put($testKey, 'ok');
            Storage::disk($targetDisk)->delete($testKey);
        } catch (Throwable $e) {
            $this->error('Gagal terhubung ke disk S3: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Mulai mengunggah berkas ke S3...');
        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->setFormat(" %current%/%max% [%bar%] %percent:3s%% | Berkas: %message%\n");
        $progressBar->setMessage('Persiapan...');
        $progressBar->start();

        $uploadedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        $failedFiles = [];
        $uploadedBytes = 0;
        $startTime = microtime(true);

        foreach ($allFiles as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $s3Key = $subFolder.'/'.$relativePath;

            $progressBar->setMessage(basename($s3Key));

            // Check if file already exists in S3 (unless force is requested)
            if (! $force) {
                try {
                    if (Storage::disk($targetDisk)->exists($s3Key)) {
                        $skippedCount++;
                        $progressBar->advance();

                        continue;
                    }
                } catch (Throwable) {
                    // Continue to upload attempt if existence check fails
                }
            }

            // Stream upload to preserve memory
            $stream = @fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                $failedCount++;
                $failedFiles[] = "{$s3Key} (Gagal membaca file lokal)";
                $progressBar->advance();

                continue;
            }

            try {
                $success = Storage::disk($targetDisk)->put($s3Key, $stream);
                if ($success) {
                    $uploadedCount++;
                    $uploadedBytes += $file->getSize();
                } else {
                    $failedCount++;
                    $failedFiles[] = "{$s3Key} (Put returned false)";
                }
            } catch (Throwable $e) {
                $failedCount++;
                $failedFiles[] = "{$s3Key} ({$e->getMessage()})";
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $duration = round(microtime(true) - $startTime, 1);
        $uploadedMB = round($uploadedBytes / (1024 * 1024), 2);

        $this->table(
            ['Metrik', 'Nilai'],
            [
                ['Total Berkas Ditemukan', number_format($totalCount)],
                ['Berkas Berhasil Diupload', number_format($uploadedCount)." ({$uploadedMB} MB)"],
                ['Berkas Dilewati (Sudah ada di S3)', number_format($skippedCount)],
                ['Berkas Gagal', number_format($failedCount)],
                ['Waktu Eksekusi', "{$duration} detik"],
            ]
        );

        if (! empty($failedFiles)) {
            $this->warn('Beberapa berkas gagal diunggah:');
            foreach (array_slice($failedFiles, 0, 10) as $err) {
                $this->line(" - {$err}");
            }
            if (count($failedFiles) > 10) {
                $this->line(' ... dan '.(count($failedFiles) - 10).' berkas lainnya.');
            }

            return self::FAILURE;
        }

        $this->info('✓ Sinkronisasi seluruh berkas rekrutmen ke S3 selesai dengan sukses!');

        return self::SUCCESS;
    }
}
