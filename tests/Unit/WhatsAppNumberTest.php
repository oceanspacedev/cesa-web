<?php

use App\Support\WhatsAppNumber;

it('normalizes indonesian numbers to the international 628 format', function (string $input, string $expected) {
    expect(WhatsAppNumber::normalize($input))->toBe($expected);
})->with([
    ['081234567890', '6281234567890'],
    ['6281234567890', '6281234567890'],
    ['+62 812-3456-789', '628123456789'],
    ['0062 812 345 678', '62812345678'],
    ['6208123456789', '628123456789'],
    ['812345678', '62812345678'],
    ['+62 (812) 345.678', '62812345678'],
]);

it('rejects numbers that cannot be a whatsapp number', function () {
    expect(WhatsAppNumber::normalize('abc'))->toBe('')
        ->and(WhatsAppNumber::normalize('0812#345'))->toBe('');
});

it('converts valid numbers to the local 08 format', function () {
    expect(WhatsAppNumber::toLocal('6281234567890'))->toBe('081234567890')
        ->and(WhatsAppNumber::toLocal('+62 812-3456-789'))->toBe('08123456789');
});

it('refuses to convert invalid numbers to the local format', function () {
    expect(WhatsAppNumber::toLocal('12345'))->toBeNull()
        ->and(WhatsAppNumber::toLocal('62 812'))->toBeNull();
});

it('masks numbers for safe display', function () {
    expect(WhatsAppNumber::mask('628123456789'))->toBe('+62812*****89')
        ->and(WhatsAppNumber::mask('6281'))->toBe('+6281');
});
