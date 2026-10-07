<?php

return [
    'label'   => 'Divisi|Divisi',
    'actions' => [
        'export' => 'Ekspor Divisi',
    ],
    'exports' => [
        'notifications' => [
            'completed_body' => 'Ekspor divisi selesai dengan :success baris berhasil diekspor dan :failed baris gagal diekspor.',
        ],
    ],
    'fields' => [
        'code'             => 'Kode',
        'name'             => 'Nama',
        'description'      => 'Deskripsi',
        'approvers'        => 'Pemberi Persetujuan',
        'approvers_count'  => 'Jumlah Pemberi Persetujuan',
        'archived'         => 'Diarsipkan',
        'archived_yes'     => 'Ya',
        'archived_no'      => 'Tidak',
        'deleted_suffix'   => 'Dihapus',
    ],
];
