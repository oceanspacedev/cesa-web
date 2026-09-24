<?php

return [
    'submissions' => [
        'max_attempts'  => (int) env('WASTE_SUBMISSIONS_RATE_LIMIT', 5),
        'decay_seconds' => (int) env('WASTE_SUBMISSIONS_RATE_DECAY', 60),
    ],
    'attachments' => [
        'disk'      => env('WASTE_ATTACHMENT_DISK', 'local'),
        'directory' => env('WASTE_ATTACHMENT_DIRECTORY', 'waste/evidence'),
        'max_size'  => (int) env('WASTE_ATTACHMENT_MAX_SIZE', 5120),
        'max_files' => (int) env('WASTE_ATTACHMENT_MAX_FILES', 5),
    ],
    'notifications' => [
        'queue'         => env('WASTE_NOTIFICATION_QUEUE', 'whatsapp'),
        'email_enabled' => (bool) env('WASTE_EMAIL_NOTIFICATIONS_ENABLED', true),
        'route_key'     => env('WASTE_NOTIFICATION_ROUTE_KEY', 'default'),
    ],
    'camera' => [
        'max_width' => (int) env('WASTE_CAMERA_MAX_WIDTH', 1600),
        'quality'   => (float) env('WASTE_CAMERA_QUALITY', 0.82),
    ],
    'master_sources' => [
        'JCHICKEN' => env('WASTE_MASTER_JCHICKEN'),
        'LUUCA'    => env('WASTE_MASTER_LUUCA'),
        'MOMOYO'   => env('WASTE_MASTER_MOMOYO'),
    ],
];
