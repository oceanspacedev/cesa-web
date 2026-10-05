<?php

use Cesa\Waste\Services\WasteReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

uses(TestCase::class);

it('reads Livewire photo metadata before the temporary file is moved on the same disk', function (): void {
    Storage::fake('tmp-for-tests');
    config([
        'filesystems.default'    => 'tmp-for-tests',
        'waste.attachments.disk' => 'tmp-for-tests',
    ]);

    $filename = 'd4Lq20iLxbmjm8yzP6ssuFNYJlHUFECE744GZhYY.jpg';
    $source = UploadedFile::fake()->image('bukti.jpg');
    Storage::disk('tmp-for-tests')->put('livewire-tmp/'.$filename, file_get_contents($source->getRealPath()));
    $photo = TemporaryUploadedFile::createFromLivewire($filename);

    $service = app(WasteReportService::class);
    $method = new ReflectionMethod($service, 'evidenceMetadata');
    $metadata = $method->invoke($service, $photo, 0);

    $storedPath = $photo->store('waste/evidence', 'tmp-for-tests');

    expect($metadata['size'])->toBeGreaterThan(0)
        ->and($metadata['sha256'])->toHaveLength(64)
        ->and(Storage::disk('tmp-for-tests')->exists($storedPath))->toBeTrue();

    expect(fn () => $photo->getSize())->toThrow(UnableToRetrieveMetadata::class);
});

it('asks the reporter to recapture a missing Livewire photo instead of crashing', function (): void {
    Storage::fake('tmp-for-tests');

    $photo = TemporaryUploadedFile::createFromLivewire('missing-camera.jpg');
    $service = app(WasteReportService::class);
    $method = new ReflectionMethod($service, 'evidenceMetadata');

    try {
        $method->invoke($service, $photo, 2);
        $this->fail('Expected a validation error for a missing camera photo.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('photos.2')
            ->and($exception->errors()['photos.2'])->toContain('Foto tidak ditemukan. Ambil ulang foto kamera lalu kirim lagi.');
    }
});
