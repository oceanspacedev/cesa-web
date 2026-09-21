<?php

use Cesa\IdCard\Filament\Resources\IdCardRequestResource;

return [
    'resources' => [
        'manage' => [
            IdCardRequestResource::class => ['view_any', 'view', 'create', 'update', 'delete', 'delete_any', 'force_delete', 'force_delete_any', 'restore', 'restore_any'],
        ],
        'exclude' => [],
    ],
    'pages' => [
        'exclude' => [],
    ],
];
