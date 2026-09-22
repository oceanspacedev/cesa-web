<?php

use Cesa\Rekrutmen\Services\AiScreeningService;
use Cesa\Rekrutmen\Services\AiSettingsService;
use Cesa\Rekrutmen\Services\CvPdfRasterizer;
use Cesa\Rekrutmen\Services\CvTextExtractor;
use Symfony\Component\Process\Process;

it('rasterizes image-only pdf pages into jpeg bytes', function (): void {
    if (trim((string) shell_exec('command -v convert')) === '') {
        test()->skip('ImageMagick convert is not available');
    }

    $png = sys_get_temp_dir().'/cv-src-'.uniqid('', true).'.png';
    $pdf = sys_get_temp_dir().'/cv-src-'.uniqid('', true).'.pdf';
    $image = imagecreatetruecolor(240, 80);
    imagefilledrectangle($image, 0, 0, 239, 79, imagecolorallocate($image, 255, 255, 255));
    imagestring($image, 5, 16, 28, 'Candidate CV Page', imagecolorallocate($image, 0, 0, 0));
    imagepng($image, $png);
    imagedestroy($image);

    try {
        (new Process(['convert', $png, $pdf]))->mustRun();
        $images = (new CvPdfRasterizer)->images((string) file_get_contents($pdf));
    } finally {
        @unlink($png);
        @unlink($pdf);
    }

    expect($images)->not->toBeEmpty()
        ->and(str_starts_with($images[0], "\xFF\xD8"))->toBeTrue();
});

it('builds multimodal screening content when only cv page images are available', function (): void {
    $service = new AiScreeningService(
        Mockery::mock(AiSettingsService::class),
        new CvTextExtractor,
        new CvPdfRasterizer,
    );

    $content = $service->userContent('{"title":"Telemarketing"}', '', ["\xFF\xD8\xFF".'fake-jpeg']);

    expect($content)->toBeArray()
        ->and($content[0]['type'])->toBe('text')
        ->and($content[0]['text'])->toContain('Telemarketing')
        ->and($content[1]['type'])->toBe('image_url')
        ->and($content[1]['image_url']['url'])->toStartWith('data:image/jpeg;base64,');
});
