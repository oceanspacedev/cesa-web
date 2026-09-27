<?php

$url = rtrim(trim((string) env('WAG_URL', '')), '/');

return [
    'url'          => $url,
    'token'        => env('WAG_TOKEN'),
    'engine_url'   => env('WAG_ENGINE_URL') ?: ($url !== '' ? $url.'/api/v2' : null),
    'engine_token' => env('WAG_ENGINE_TOKEN') ?: env('WAG_TOKEN'),
    'timeout'      => (int) env('WHATSAPP_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | OTP WhatsApp (login Filament)
    |--------------------------------------------------------------------------
    |
    | expires_in dalam detik; message mendukung placeholder
    | {app_name}, {otp}, dan {expires_in}.
    |
    */

    'otp' => [
        'expires_in' => (int) env('WAG_OTP_EXPIRES_IN', 300),
        'message'    => env(
            'WAG_OTP_MESSAGE',
            'Kode OTP {app_name} Anda: {otp}. Berlaku {expires_in}. Jangan bagikan kode ini kepada siapa pun.',
        ),
    ],
];
