<?php

use Cesa\Rekrutmen\Services\CvTextExtractor;

it('safely extracts text from valid PDF streams without memory issues', function () {
    $extractor = new CvTextExtractor;

    $textStream = gzcompress('BT /F1 12 Tf (Candidate Name John Doe Experienced Sales Consultant Jakarta) Tj ET');
    $pdf = "1 0 obj\n<< /Length ".strlen($textStream)." /Filter /FlateDecode >>\nstream\n".$textStream."\nendstream\nendobj";

    $extracted = $extractor->extract($pdf);

    expect($extracted)->toContain('Candidate Name John Doe Experienced Sales Consultant Jakarta');
});

it('skips raster image streams to prevent memory exhaustion', function () {
    $extractor = new CvTextExtractor;

    // Simulated image stream with /Subtype /Image
    $dummyImageData = gzcompress(str_repeat('IMAGE_RAW_BYTE', 1000));
    $imagePdf = "2 0 obj\n<< /Type /XObject /Subtype /Image /Length ".strlen($dummyImageData)." /Filter /FlateDecode >>\nstream\n".$dummyImageData."\nendstream\nendobj";

    $textStream = gzcompress('BT (Experienced Sales Consultant with five years background in retail) Tj ET');
    $textPdf = "3 0 obj\n<< /Length ".strlen($textStream)." /Filter /FlateDecode >>\nstream\n".$textStream."\nendstream\nendobj";

    $combinedPdf = $imagePdf."\n".$textPdf;

    $extracted = $extractor->extract($combinedPdf);

    expect($extracted)->toContain('Experienced Sales Consultant with five years background in retail');
});

it('safely handles massive bfrange fonts without allocating huge memory', function () {
    $extractor = new CvTextExtractor;

    // CMap with huge range: <0000> <ffff> <0000>
    $cmapData = "beginbfrange\n<0000> <ffff> <0000>\nendbfrange";
    $cmapStream = gzcompress($cmapData);
    $cmapObj = "4 0 obj\n<< /Length ".strlen($cmapStream)." /Filter /FlateDecode >>\nstream\n".$cmapStream."\nendstream\nendobj";

    $textStream = gzcompress('BT (Valid Candidate Resume Text with comprehensive education and skills) Tj ET');
    $textObj = "5 0 obj\n<< /Length ".strlen($textStream)." /Filter /FlateDecode >>\nstream\n".$textStream."\nendstream\nendobj";

    $pdf = $cmapObj."\n".$textObj;

    $memBefore = memory_get_usage(true);
    $extracted = $extractor->extract($pdf);
    $memAfter = memory_get_usage(true);

    // Memory difference should be negligible (< 5MB)
    expect(($memAfter - $memBefore) / 1024 / 1024)->toBeLessThan(5);
    expect($extracted)->toContain('Valid Candidate Resume Text with comprehensive education and skills');
});
