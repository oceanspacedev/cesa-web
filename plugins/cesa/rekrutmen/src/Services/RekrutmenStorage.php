<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Models\JobApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RekrutmenStorage
{
    public function disk(): string
    {
        return $this->configuredDisk(config('rekrutmen.disk'), 's3');
    }

    public function thumbnailDisk(): string
    {
        return $this->configuredDisk(config('rekrutmen.thumbnail_disk'), $this->disk());
    }

    public function visibility(string $disk): string
    {
        return config('filesystems.disks.'.$disk.'.visibility') === 'public' ? 'public' : 'private';
    }

    public function storeUploadedFile(UploadedFile $file, string $directory, ?string $disk = null): string
    {
        $disk ??= $this->disk();
        $this->assertConfigured($disk);
        $path = $file->store($directory, ['disk' => $disk, 'visibility' => $this->visibility($disk)]);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Berkas rekrutmen gagal disimpan pada disk yang dipilih.');
        }

        return $path;
    }

    public function resolveDisk(string $path, ?string $storedDisk = null, ?string $preferredDisk = null): ?string
    {
        $path = $this->normalizePath($path);
        if ($path === null) {
            return null;
        }

        $disks = filled($storedDisk) ? [$storedDisk] : $this->candidateDisks($preferredDisk);
        $matches = [];
        foreach ($disks as $disk) {
            if (! config()->has('filesystems.disks.'.$disk)) {
                continue;
            }

            try {
                if (Storage::disk($disk)->exists($path)) {
                    $matches[] = $disk;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function url(string $path, ?string $storedDisk = null, ?string $preferredDisk = null): ?string
    {
        if (filter_var($path, FILTER_VALIDATE_URL) && in_array(parse_url($path, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $path;
        }

        $disk = $this->resolveDisk($path, $storedDisk, $preferredDisk);
        if ($disk === null) {
            return null;
        }

        try {
            $filesystem = Storage::disk($disk);
            if ($this->visibility($disk) === 'public') {
                return $filesystem->url($this->normalizePath($path));
            }

            return $filesystem->providesTemporaryUrls()
                ? $filesystem->temporaryUrl($this->normalizePath($path), now()->addHour())
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public function candidateDisks(?string $preferredDisk = null): array
    {
        return array_values(array_unique(array_filter([
            $preferredDisk,
            config('rekrutmen.disk'),
            config('rekrutmen.thumbnail_disk'),
            's3',
            'public',
            'local',
        ], fn (mixed $disk): bool => is_string($disk) && $disk !== '' && config()->has('filesystems.disks.'.$disk))));
    }

    /** @return list<array{disk: string, path: string}> */
    public function files(string $directory): array
    {
        $files = [];
        foreach ($this->candidateDisks() as $disk) {
            try {
                foreach (Storage::disk($disk)->files($directory) as $path) {
                    if ($this->normalizePath($path) !== null) {
                        $files[] = ['disk' => $disk, 'path' => $path];
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $files;
    }

    /**
     * @param  list<array{disk: string, path: string}>|null  $files
     * @return array{disk: string, path: string}|null
     */
    public function findCandidateResume(JobApplication $application, ?array $files = null): ?array
    {
        $path = $application->resume_path;
        if (is_string($path) && $path !== '') {
            $disk = $this->resolveDisk($path, $application->resume_disk, $this->disk());
            if ($disk !== null) {
                return ['disk' => $disk, 'path' => $this->normalizePath($path)];
            }
            if (filled($application->resume_disk) || $this->normalizePath($path) === null) {
                return null;
            }
        }

        $files ??= $this->files(JobApplication::RESUME_DIRECTORY);
        $samePath = array_values(array_filter($files, fn (array $file): bool => $file['path'] === $path));
        if (count($samePath) > 1) {
            return null;
        }

        $prefix = 'CV-'.$application->id.'-';
        $matches = array_values(array_filter($files, fn (array $file): bool => str_starts_with(basename($file['path']), $prefix)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array{disk: string, path: string} $file */
    public function rememberCandidateResume(JobApplication $application, array $file): void
    {
        if ($application->resume_path === $file['path'] && $application->resume_disk === $file['disk']) {
            return;
        }

        $updated = JobApplication::query()->whereKey($application->id)
            ->where('resume_path', $application->getRawOriginal('resume_path'))
            ->where('resume_disk', $application->getRawOriginal('resume_disk'))
            ->update(['resume_path' => $file['path'], 'resume_disk' => $file['disk']]);

        if ($updated) {
            $application->setAttribute('resume_path', $file['path']);
            $application->setAttribute('resume_disk', $file['disk']);
            $application->syncOriginalAttributes(['resume_path', 'resume_disk']);
        } else {
            $application->refresh();
        }
    }

    public function normalizePath(string $path): ?string
    {
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '\\') || str_contains($path, '://')
            || preg_match('/[\x00-\x1f\x7f]/', $path) || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    protected function configuredDisk(mixed $disk, string $fallback): string
    {
        $disk = is_string($disk) && trim($disk) !== '' ? trim($disk) : $fallback;
        $this->assertConfigured($disk);

        return $disk;
    }

    protected function assertConfigured(string $disk): void
    {
        if (! config()->has('filesystems.disks.'.$disk)) {
            throw new InvalidArgumentException('Disk penyimpanan rekrutmen ['.$disk.'] belum dikonfigurasi.');
        }
    }
}
