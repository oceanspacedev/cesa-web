<?php

return [
    'navigation' => [
        'label' => 'Approver',
    ],
    'model' => [
        'singular' => 'Approver',
        'plural'   => 'Approver',
    ],
    'form' => [
        'sections' => [
            'identity' => 'Identitas Approver',
            'scope'    => 'Cakupan Persetujuan',
        ],
        'fields' => [
            'name'           => 'Nama',
            'email'          => 'Email',
            'phone'          => 'Telepon',
            'title'          => 'Jabatan',
            'approval_order' => 'Urutan Persetujuan',
            'company_id'     => 'Badan Usaha',
            'division_id'    => 'Divisi',
            'is_active'      => 'Aktif',
        ],
        'helpers' => [
            'company_id'     => 'Kosongkan jika approver berlaku untuk semua badan usaha.',
            'division_id'    => 'Kosongkan jika approver berlaku untuk semua divisi pada cakupan badan usaha yang dipilih.',
            'approval_order' => 'Gunakan angka kecil untuk approver yang harus memproses lebih dahulu.',
        ],
    ],
    'table' => [
        'columns' => [
            'approval_order' => 'Urutan',
            'name'           => 'Nama',
            'email'          => 'Email',
            'phone'          => 'Telepon',
            'title'          => 'Jabatan',
            'company_id'     => 'Badan Usaha',
            'division_id'    => 'Divisi',
            'is_active'      => 'Aktif',
        ],
        'placeholders' => [
            'company_id'  => 'Semua Badan Usaha',
            'division_id' => 'Semua Divisi',
        ],
        'filters' => [
            'company_id'  => 'Badan Usaha',
            'division_id' => 'Divisi',
            'is_active'   => 'Status Aktif',
        ],
    ],
];
