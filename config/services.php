<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai_compatible' => [
        'automatic'           => env('REKRUTMEN_AI_AUTOMATIC', true),
        'requests_per_minute' => env('REKRUTMEN_AI_REQUESTS_PER_MINUTE', 20),
        'base_url'            => env('OPENAI_COMPATIBLE_BASE_URL', 'https://router.rizqis.com/v1'),
        'api_key'             => env('OPENAI_COMPATIBLE_API_KEY'),
        'model'               => env('OPENAI_COMPATIBLE_MODEL', 'ag/gemini-3.7-flash-low'),
    ],

];
