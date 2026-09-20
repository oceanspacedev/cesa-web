<?php

$url = rtrim(trim((string) env('WAG_URL', '')), '/');

return [
    'url'          => $url,
    'token'        => env('WAG_TOKEN'),
    'engine_url'   => env('WAG_ENGINE_URL') ?: ($url !== '' ? $url.'/api/v2' : null),
    'engine_token' => env('WAG_ENGINE_TOKEN') ?: env('WAG_TOKEN'),
    'timeout'      => (int) env('WHATSAPP_TIMEOUT', 20),
];
