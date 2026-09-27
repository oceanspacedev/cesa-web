<?php

namespace App\Support;

class WhatsAppNumber
{
    /**
     * Normalisasi nomor WhatsApp Indonesia ke format internasional 628...
     */
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\+?[0-9\s().-]+$/', $value) !== 1) {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $value);

        return match (true) {
            str_starts_with($digits, '0062') => substr($digits, 2),
            str_starts_with($digits, '620')  => '62'.substr($digits, 3),
            str_starts_with($digits, '62')   => $digits,
            str_starts_with($digits, '0')    => '62'.substr($digits, 1),
            str_starts_with($digits, '8')    => '62'.$digits,
            default                          => $digits,
        };
    }

    /**
     * Apakah nomor sudah berupa nomor WhatsApp Indonesia (628...) yang valid.
     */
    public static function isValid(string $value): bool
    {
        return preg_match('/^628\d{8,12}$/', $value) === 1;
    }

    /**
     * Konversi ke format lokal 08..., null bila nomor tidak valid.
     */
    public static function toLocal(string $value): ?string
    {
        $normalized = self::normalize($value);

        if (! self::isValid($normalized)) {
            return null;
        }

        return '0'.substr($normalized, 2);
    }

    /**
     * Samarkan nomor untuk ditampilkan di log/notifikasi: +62812****90.
     */
    public static function mask(string $value): string
    {
        $length = strlen($value);

        if ($length <= 7) {
            return '+'.$value;
        }

        return '+'.substr($value, 0, 5).str_repeat('*', max(4, $length - 7)).substr($value, -2);
    }
}
