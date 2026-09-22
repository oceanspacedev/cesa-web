<?php

namespace Cesa\Rekrutmen\Services;

use Symfony\Component\Process\Process;

class CvPdfRasterizer
{
    /**
     * @return list<string>
     */
    public function images(string $pdf): array
    {
        if ($pdf === '' || ! str_starts_with($pdf, '%PDF-') || strlen($pdf) > 20 * 1024 * 1024) {
            return [];
        }

        $convert = $this->convertBinary();
        if ($convert === null) {
            return [];
        }

        $directory = sys_get_temp_dir().'/cv-raster-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            return [];
        }

        $pdfPath = $directory.'/cv.pdf';
        file_put_contents($pdfPath, $pdf);

        try {
            $process = new Process([
                $convert,
                '-density', '110',
                $pdfPath.'[0-2]',
                '-resize', '1200x1600>',
                '-quality', '75',
                $directory.'/page-%d.jpg',
            ]);
            $process->setTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                return [];
            }

            $images = [];
            foreach (glob($directory.'/page-*.jpg') ?: [] as $file) {
                $bytes = file_get_contents($file);
                if (is_string($bytes) && strlen($bytes) > 800 && str_starts_with($bytes, "\xFF\xD8")) {
                    $images[] = $bytes;
                }
            }

            return array_slice($images, 0, 3);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    private function convertBinary(): ?string
    {
        if (is_executable('/usr/bin/convert')) {
            return '/usr/bin/convert';
        }

        $process = new Process(['bash', '-lc', 'command -v convert']);
        $process->run();
        $path = trim($process->getOutput());

        return $path !== '' ? $path : null;
    }
}
