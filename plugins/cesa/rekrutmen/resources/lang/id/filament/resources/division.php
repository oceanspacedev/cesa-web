<?php

return [
    'navigation' => [
        'label' => 'Divisi',
    ],
    'model' => [
        'singular' => 'Divisi',
        'plural'   => 'Divisi',
    ],
    'form' => [
        'sections' => [
            'identity' => 'Identitas Divisi',
        ],
        'fields' => [
            'company_id' => 'Badan Usaha',
            'name'       => 'Nama Divisi',
            'is_active'  => 'Aktif',
        ],
    ],
    'table' => [
        'columns' => [
            'company_id' => 'Badan Usaha',
            'name'       => 'Nama Divisi',
            'is_active'  => 'Aktif',
        ],
        'filters' => [
            'company_id' => 'Badan Usaha',
            'is_active'  => 'Status Aktif',
        ],
    ],
];
