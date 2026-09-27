<?php

return [
    'title'      => 'Pengguna',
    'navigation' => [
        'title' => 'Pengguna',
        'group' => 'Pengaturan',
    ],
    'global-search' => [
        'email' => 'Email',
    ],
    'form' => [
        'validation' => [
            'cannot-remove-last-admin' => 'Tidak dapat menghapus peran admin dari pengguna admin terakhir.',
            'first-user-must-be-admin' => 'Pengguna pertama dalam sistem harus diberi peran admin.',
        ],
        'sections' => [
            'general-information' => [
                'title'  => 'Informasi Umum',
                'fields' => [
                    'name'                  => 'Nama',
                    'email'                 => 'Email',
                    'phone'                 => 'Nomor HP',
                    'password'              => 'Kata sandi',
                    'password-confirmation' => 'Konfirmasi Kata Sandi',
                ],
            ],
            'permissions' => [
                'title'  => 'Izin',
                'fields' => [
                    'roles'                                    => 'Peran',
                    'permissions'                              => 'Izin',
                    'resource-permission'                      => 'Izin Sumber Daya',
                    'resource-permission-self-change-disabled' => 'Anda tidak dapat mengubah izin sumber daya Anda sendiri. Minta administrator lain untuk memperbaruinya.',
                    'teams'                                    => 'Tim',
                ],
            ],
            'avatar' => [
                'title' => 'Avatar',
            ],
            'lang-and-status' => [
                'title'  => 'Bahasa & Status',
                'fields' => [
                    'language' => 'Bahasa Pilihan',
                    'status'   => 'Status',
                ],
            ],
            'multi-company' => [
                'title'             => 'Multi-Perusahaan',
                'allowed-companies' => 'Perusahaan yang Diizinkan',
                'default-company'   => 'Perusahaan Default',
            ],
        ],
    ],
    'table' => [
        'columns' => [
            'avatar'              => 'Avatar',
            'name'                => 'Nama',
            'email'               => 'Email',
            'teams'               => 'Tim',
            'role'                => 'Peran',
            'resource-permission' => 'Izin Sumber Daya',
            'default-company'     => 'Perusahaan Default',
            'allowed-company'     => 'Perusahaan yang Diizinkan',
            'created-by'          => 'Dibuat Oleh',
            'created-at'          => 'Dibuat Pada',
            'updated-at'          => 'Diperbarui Pada',
        ],
        'filters' => [
            'resource-permission' => 'Izin Sumber Daya',
            'teams'               => 'Tim',
            'roles'               => 'Peran',
            'default-company'     => 'Perusahaan Default',
            'allowed-companies'   => 'Perusahaan yang Diizinkan',
        ],
        'actions' => [
            'edit' => [
                'notification' => [
                    'title' => 'Pengguna diperbarui',
                    'body'  => 'Pengguna berhasil diperbarui.',
                ],
            ],
            'delete' => [
                'notification' => [
                    'title' => 'Pengguna dihapus',
                    'body'  => 'Pengguna telah berhasil dihapus.',
                    'error' => [
                        'title' => 'Pengguna Tidak Dapat Dihapus',
                        'body'  => 'Ini adalah pengguna default atau Anda tidak dapat menghapusnya sendiri.',
                    ],
                ],
            ],
            'restore' => [
                'notification' => [
                    'title' => 'Pengguna dipulihkan',
                    'body'  => 'Pengguna telah berhasil dipulihkan.',
                ],
            ],
        ],
        'bulk-actions' => [
            'restore' => [
                'notification' => [
                    'title' => 'Pengguna dipulihkan',
                    'body'  => 'Pengguna telah berhasil dipulihkan.',
                ],
            ],
            'delete' => [
                'notification' => [
                    'title' => 'Pengguna dihapus',
                    'body'  => 'Pengguna telah berhasil dihapus.',
                ],
            ],
            'force-delete' => [
                'notification' => [
                    'title' => 'Pengguna dihapus paksa',
                    'body'  => 'Pengguna telah berhasil dihapus secara paksa.',
                    'error' => [
                        'title' => 'Pengguna tidak dapat dihapus',
                        'body'  => 'Pengguna tidak dapat dihapus karena sedang digunakan.',
                    ],
                ],
            ],
        ],
        'empty-state-actions' => [
            'create' => [
                'notification' => [
                    'title' => 'Pengguna dibuat',
                    'body'  => 'Pengguna telah berhasil dibuat.',
                ],
            ],
        ],
    ],
    'infolist' => [
        'sections' => [
            'general-information' => [
                'title'   => 'Informasi Umum',
                'entries' => [
                    'name'                  => 'Nama',
                    'email'                 => 'Email',
                    'phone'                 => 'Nomor HP',
                    'password'              => 'Kata sandi',
                    'password-confirmation' => 'Konfirmasi Kata Sandi',
                ],
            ],
            'permissions' => [
                'title'   => 'Izin',
                'entries' => [
                    'roles'               => 'Peran',
                    'permissions'         => 'Izin',
                    'resource-permission' => 'Izin Sumber Daya',
                    'teams'               => 'Tim',
                ],
            ],
            'avatar' => [
                'title' => 'Avatar',
            ],
            'lang-and-status' => [
                'title'   => 'Bahasa & Status',
                'entries' => [
                    'language' => 'Bahasa Pilihan',
                    'status'   => 'Status',
                ],
            ],
            'multi-company' => [
                'title'             => 'Multi-Perusahaan',
                'allowed-companies' => 'Perusahaan yang Diizinkan',
                'default-company'   => 'Perusahaan Default',
            ],
        ],
    ],
];
