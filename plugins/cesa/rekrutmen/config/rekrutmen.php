<?php

$engineDriver = env('REKRUTMEN_WHATSAPP_ENGINE_DRIVER', 'wag_hub');
$wag = require base_path('config/wag.php');

return [
    'disk' => env('REKRUTMEN_FILESYSTEM_DISK', 's3'),

    'thumbnail_disk' => env('REKRUTMEN_THUMBNAIL_DISK', env('REKRUTMEN_FILESYSTEM_DISK', 's3')),

    'default_pipeline_id' => env('REKRUTMEN_DEFAULT_PIPELINE_ID'),

    'default_pipeline_name' => env('REKRUTMEN_DEFAULT_PIPELINE_NAME', 'Default Recruitment Pipeline'),

    'notifications' => [
        'queue'    => env('REKRUTMEN_NOTIFICATION_QUEUE', 'notifications'),
        'delivery' => [
            'lease_seconds' => 120,
        ],
        'mail'     => [
            'throttle' => [
                'enabled'              => env('NOTIFICATION_MAIL_THROTTLE_ENABLED', env('REKRUTMEN_MAIL_THROTTLE_ENABLED', true)),
                'min_interval_seconds' => (int) env('NOTIFICATION_MAIL_THROTTLE_MIN_INTERVAL', env('REKRUTMEN_MAIL_THROTTLE_MIN_INTERVAL', 2)),
                'max_interval_seconds' => (int) env('NOTIFICATION_MAIL_THROTTLE_MAX_INTERVAL', env('REKRUTMEN_MAIL_THROTTLE_MAX_INTERVAL', 5)),
                'key'                  => env('NOTIFICATION_MAIL_THROTTLE_KEY', env('REKRUTMEN_MAIL_THROTTLE_KEY', 'global')),
            ],
        ],
        'whatsapp' => [
            'enabled'       => env('REKRUTMEN_WHATSAPP_ENABLED', true),
            'engine_driver' => $engineDriver,
            'engine_url'    => env('REKRUTMEN_WHATSAPP_ENGINE_URL') ?: ($engineDriver === 'local' ? 'http://127.0.0.1:3318' : $wag['engine_url']),
            'engine_token'  => env('REKRUTMEN_WHATSAPP_ENGINE_TOKEN') ?: ($engineDriver === 'local' ? null : $wag['engine_token']),
            'auto_start'    => env('REKRUTMEN_WHATSAPP_ENGINE_AUTO_START', $engineDriver === 'local'),
            'node_binary'   => env('REKRUTMEN_WHATSAPP_NODE_BINARY', 'node'),
            'endpoint'      => env('WAG_URL', 'https://waghub.mekayastudio.com'),
            'api_key'       => env('WAG_TOKEN'),

            'country_code' => env('WHATSAPP_COUNTRY_CODE', '62'),
            'throttle'     => [
                'enabled'              => env('WHATSAPP_THROTTLE_ENABLED', true),
                'min_interval_seconds' => (int) env('WHATSAPP_THROTTLE_MIN_INTERVAL', 2),
                'max_interval_seconds' => (int) env('WHATSAPP_THROTTLE_MAX_INTERVAL', 3),
                'key'                  => env('WHATSAPP_THROTTLE_KEY', 'global'),
            ],
            'queue'        => env('WHATSAPP_QUEUE', 'whatsapp'),
            'connection'   => env('WHATSAPP_CONNECTION'),
            'timeout'      => (int) env('WHATSAPP_TIMEOUT', 20),
            'http_timeout' => (int) env('REKRUTMEN_WHATSAPP_HTTP_TIMEOUT', env('WHATSAPP_TIMEOUT', 20)),
            'job_timeout'  => (int) env('REKRUTMEN_WHATSAPP_JOB_TIMEOUT', 60),
            'tries'        => (int) env('WHATSAPP_TRIES', 3),
            'backoff'      => array_values(array_filter(
                array_map('intval', explode(',', env('WHATSAPP_BACKOFF', '10,30,60'))),
                static fn (int $interval): bool => $interval >= 0
            )),
        ],
    ],

    'application_form' => [
        'default_fields' => [
            [
                'name'     => 'full_name',
                'label'    => 'rekrutmen::config/application-form.fields.full_name',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'email',
                'label'    => 'rekrutmen::config/application-form.fields.email',
                'type'     => 'email',
                'required' => true,
            ],
            [
                'name'     => 'gender',
                'label'    => 'rekrutmen::config/application-form.fields.gender',
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    [
                        'value' => 'male',
                        'label' => 'rekrutmen::enums/job-application-gender.male',
                    ],
                    [
                        'value' => 'female',
                        'label' => 'rekrutmen::enums/job-application-gender.female',
                    ],
                ],
            ],
            [
                'name'     => 'birth_date',
                'label'    => 'rekrutmen::config/application-form.fields.birth_date',
                'type'     => 'date',
                'required' => true,
            ],
            [
                'name'     => 'marital_status',
                'label'    => 'rekrutmen::config/application-form.fields.marital_status',
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    [
                        'value' => 'single',
                        'label' => 'rekrutmen::enums/job-application-marital-status.single',
                    ],
                    [
                        'value' => 'married',
                        'label' => 'rekrutmen::enums/job-application-marital-status.married',
                    ],
                    [
                        'value' => 'divorced',
                        'label' => 'rekrutmen::enums/job-application-marital-status.divorced',
                    ],
                ],
            ],
            [
                'name'     => 'address_ktp',
                'label'    => 'rekrutmen::config/application-form.fields.address_ktp',
                'type'     => 'textarea',
                'required' => true,
            ],
            [
                'name'     => 'address_domicile',
                'label'    => 'rekrutmen::config/application-form.fields.address_domicile',
                'type'     => 'textarea',
                'required' => true,
            ],
            [
                'name'     => 'whatsapp_number',
                'label'    => 'rekrutmen::config/application-form.fields.whatsapp_number',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'active_phone',
                'label'    => 'rekrutmen::config/application-form.fields.active_phone',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'emergency_contact_name',
                'label'    => 'rekrutmen::config/application-form.fields.emergency_contact_name',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'emergency_contact_relation',
                'label'    => 'rekrutmen::config/application-form.fields.emergency_contact_relation',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'emergency_contact_phone',
                'label'    => 'rekrutmen::config/application-form.fields.emergency_contact_phone',
                'type'     => 'text',
                'required' => true,
            ],
            [
                'name'     => 'photo',
                'label'    => 'rekrutmen::config/application-form.fields.photo',
                'type'     => 'file',
                'required' => true,
            ],
            [
                'name'     => 'resume',
                'label'    => 'rekrutmen::config/application-form.fields.resume',
                'type'     => 'file',
                'required' => true,
            ],
        ],
        'by_slug' => [],
    ],

    'security' => [
        'approval_link_expiration_minutes' => (int) env('REKRUTMEN_APPROVAL_LINK_EXPIRATION_MINUTES', 10080),
        'approval_rate_limit'              => [
            'max_attempts'  => (int) env('REKRUTMEN_APPROVAL_RATE_LIMIT_MAX_ATTEMPTS', 5),
            'decay_seconds' => (int) env('REKRUTMEN_APPROVAL_RATE_LIMIT_DECAY_SECONDS', 60),
        ],
        'recaptcha' => [
            'enabled'         => env('REKRUTMEN_RECAPTCHA_ENABLED', false),
            'site_key'        => env('REKRUTMEN_RECAPTCHA_SITE_KEY'),
            'secret_key'      => env('REKRUTMEN_RECAPTCHA_SECRET_KEY'),
            'action'          => env('REKRUTMEN_RECAPTCHA_ACTION', 'request_man_power'),
            'score_threshold' => (float) env('REKRUTMEN_RECAPTCHA_SCORE_THRESHOLD', 0.5),
            'timeout'         => (int) env('REKRUTMEN_RECAPTCHA_TIMEOUT', 5),
        ],
    ],

    'mail' => [
        'job_application' => [
            'mailer' => env('REKRUTMEN_JOB_APPLICATION_MAIL_MAILER', 'rekrutmen_job_application'),
            'from'   => [
                'address' => env('REKRUTMEN_JOB_APPLICATION_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
                'name'    => env('REKRUTMEN_JOB_APPLICATION_MAIL_FROM_NAME', env('MAIL_FROM_NAME')),
            ],
            'reply_to' => [
                'address' => env('REKRUTMEN_JOB_APPLICATION_MAIL_REPLY_TO_ADDRESS'),
                'name'    => env('REKRUTMEN_JOB_APPLICATION_MAIL_REPLY_TO_NAME'),
            ],
        ],
    ],
];
