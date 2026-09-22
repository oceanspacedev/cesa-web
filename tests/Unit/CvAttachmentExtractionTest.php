<?php

use Cesa\Rekrutmen\Http\Requests\UploadCandidateCvRequest;
use Cesa\Rekrutmen\Services\CvPdfRasterizer;
use Cesa\Rekrutmen\Services\CvTextExtractor;

it('extracts readable text from a docx cv', function (): void {
    $docx = cvAttachmentDocx('Experienced PHP and Laravel developer with five years of application testing.');

    expect((new CvTextExtractor)->extract($docx))
        ->toContain('Experienced PHP and Laravel developer');
});

it('extracts readable text from a word 97 doc cv', function (): void {
    $text = 'Experienced PHP and Laravel developer with five years of application testing.';
    $doc = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00\x01", 16)
        .mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');

    expect((new CvTextExtractor)->extract($doc))
        ->toContain('Experienced PHP and Laravel developer');
});

it('reads embedded images from a photo-only docx cv', function (): void {
    $docx = cvAttachmentDocx('short', ['image1.jpeg' => cvAttachmentJpeg()]);

    expect((new CvTextExtractor)->extract($docx))->toBe('')
        ->and((new CvPdfRasterizer)->images($docx))->not->toBeEmpty();
});

it('accepts only pdf and word files as uploaded cvs', function (): void {
    $rules = implode('|', (new UploadCandidateCvRequest)->rules()['cv']);

    expect($rules)->toContain('mimes:pdf,doc,docx')
        ->and($rules)->not->toContain('jpg')
        ->and($rules)->not->toContain('png')
        ->and($rules)->not->toContain('webp');
});

/**
 * @param  array<string, string>  $media
 */
function cvAttachmentDocx(string $plainText, array $media = []): string
{
    $path = sys_get_temp_dir().'/cv-docx-'.uniqid('', true).'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $escaped = htmlspecialchars($plainText, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body><w:p><w:r><w:t>'.$escaped.'</w:t></w:r></w:p></w:body></w:document>'
    );

    foreach ($media as $name => $bytes) {
        $zip->addFromString('word/media/'.$name, $bytes);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function cvAttachmentJpeg(): string
{
    $image = imagecreatetruecolor(48, 48);
    imagefilledrectangle($image, 0, 0, 47, 47, imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagejpeg($image, null, 80);
    imagedestroy($image);

    return (string) ob_get_clean();
}
