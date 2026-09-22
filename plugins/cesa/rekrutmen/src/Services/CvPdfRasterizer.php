<?php

namespace Cesa\Rekrutmen\Services;

use Symfony\Component\Process\Process;

class CvPdfRasterizer
{
    /**
     * @return list<string>
     */
    public function images(string $content): array
    {
        if ($content === '' || strlen($content) > 20 * 1024 * 1024) {
            return [];
        }

        if ($this->isRasterImage($content)) {
            $jpeg = $this->toJpeg($content);

            return $jpeg === null ? [] : [$jpeg];
        }

        if ($this->isDocx($content)) {
            return $this->imagesFromDocx($content);
        }

        if (! str_starts_with($content, '%PDF-')) {
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
        file_put_contents($pdfPath, $content);

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

    private function isDocx(string $content): bool
    {
        return str_starts_with($content, 'PK') && str_contains($content, 'word/document.xml');
    }

    private function isRasterImage(string $content): bool
    {
        return str_starts_with($content, "\xFF\xD8\xFF")
            || str_starts_with($content, "\x89PNG\r\n\x1a\n")
            || str_starts_with($content, 'GIF87a')
            || str_starts_with($content, 'GIF89a')
            || (str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP');
    }

    private function toJpeg(string $content): ?string
    {
        if (str_starts_with($content, "\xFF\xD8\xFF")) {
            return $content;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);
        $jpeg = ob_get_clean();

        return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    /**
     * @return list<string>
     */
    private function imagesFromDocx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'cvmedia');
        if ($path === false || file_put_contents($path, $content) === false) {
            return [];
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            @unlink($path);

            return [];
        }

        try {
            $images = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (! str_starts_with($name, 'word/media/')) {
                    continue;
                }

                $bytes = $zip->getFromIndex($index);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }

                $jpeg = $this->toJpeg($bytes);
                if ($jpeg === null || strlen($jpeg) < 400) {
                    continue;
                }

                $images[] = $jpeg;
                if (count($images) >= 4) {
                    break;
                }
            }

            return $images;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}
