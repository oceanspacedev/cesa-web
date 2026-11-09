<?php

return [
    'affiliates' => [
        'heading'             => 'DAFTAR FORM TRANSFER - AFILIASI',
        'description'         => 'Pilih afiliasi untuk melanjutkan pengisian formulir permintaan transfer.',
        'default_description' => 'Buka formulir permintaan transfer afiliasi.',
        'empty_state'         => 'Belum ada formulir transfer afiliasi yang tersedia saat ini.',
    ],

    'index' => [
        'heading'             => 'DAFTAR FORM TRANSFER',
        'description'         => 'Ajukan dan pantau permintaan transfer melalui form yang tersedia.',
        'default_description' => 'Buka formulir permintaan transfer yang dipilih.',
        'empty_state'         => 'Belum ada form transfer yang tersedia saat ini.',
    ],

    'catalog' => [
        'heading'             => 'DAFTAR FORM TRANSFER - :category',
        'description'         => 'Pilih form transfer kategori :category untuk melanjutkan pengisian permintaan transfer.',
        'default_description' => 'Buka form transfer kategori :category.',
        'empty_state'         => 'Belum ada form transfer kategori :category yang tersedia saat ini.',
    ],

    'categories' => [
        'heading'             => 'KATEGORI FORM TRANSFER',
        'description'         => 'Pilih kategori untuk melihat form transfer yang tersedia.',
        'default_description' => 'Buka form transfer pada kategori ini.',
        'empty_state'         => 'Belum ada kategori yang tersedia saat ini.',
    ],

    'form' => [
        'heading'       => 'FORM TRANSFER - :form',
        'description'   => 'Isi informasi berikut untuk mengajukan permintaan transfer :form.',
        'submit'        => 'Kirim Pengajuan',
        'placeholders'  => [
            'email'              => 'Masukkan email',
            'requester_name'     => 'Masukkan nama pemohon',
            'requester_whatsapp' => 'Contoh: 081234567890',
            'account_number'     => 'Masukkan nomor rekening',
            'account_name'       => 'Masukkan nama pemilik rekening',
            'transfer_amount'    => 'Contoh: 1000000',
            'purpose'            => 'Tuliskan keperluan transfer',
            'reference_note'     => 'Tuliskan catatan referensi',
        ],
        'helpers' => [
            'requester_whatsapp' => 'Kabar status pengajuan juga dikirim ke nomor WhatsApp ini.',
        ],
        'account_validation' => [
            'action'       => 'Cek Rekening',
            'hint'         => 'Klik Cek Rekening untuk memvalidasi nomor rekening.',
            'hint_manual'  => 'Klik Cek Rekening untuk memvalidasi nomor rekening. Nama penerima tetap diisi manual.',
            'success'      => 'Rekening terverifikasi.',
            'not_found'    => 'Rekening tidak ditemukan. Periksa bank dan nomor rekening.',
            'failed'       => 'Validasi rekening gagal. Silakan coba lagi.',
            'rate_limited' => 'Terlalu banyak percobaan validasi. Coba lagi nanti.',
        ],
        'notifications' => [
            'success' => [
                'title' => 'Permintaan Berhasil Dikirim',
                'body'  => 'Permintaan Anda berhasil dikirim. Referensi: :uid.',
            ],
            'error' => [
                'title' => 'Pengajuan Gagal',
                'body'  => 'Permintaan tidak dapat dikirim. Silakan coba kembali.',
            ],
            'validation' => [
                'title' => 'Validasi Gagal',
                'body'  => 'Periksa kembali kolom yang ditandai dan coba lagi.',
            ],
            'rate_limit' => [
                'title' => 'Terlalu Banyak Permintaan',
                'body'  => 'Kami mendeteksi terlalu banyak percobaan. Tunggu :seconds detik sebelum mencoba lagi.',
            ],
        ],
        'actions' => [
            'heading'                => 'PERSETUJUAN PENGAJUAN TRANSFER - :form',
            'subheading'             => 'Pemohon: :requester',
            'comments'               => 'Komentar',
            'comments_placeholder'   => 'Masukkan komentar untuk keputusan ini (opsional).',
            'approved'               => 'Persetujuan berhasil disimpan.',
            'rejected'               => 'Penolakan berhasil disimpan.',
            'invalid_state'          => 'Tugas persetujuan ini sudah tidak dapat diproses.',
            'already_processed_body' => 'Permintaan ini sudah diproses sebelumnya dan tidak dapat diubah lagi.',
            'rate_limit'             => [
                'title' => 'Terlalu Banyak Percobaan',
                'body'  => 'Tunggu :seconds detik sebelum mencoba lagi.',
            ],
        ],
    ],

    'progress' => [
        'heading'            => 'PANTAU PROGRES PERMINTAAN',
        'description'        => 'Lacak status permintaan transfer menggunakan referensi yang diberikan saat pengajuan.',
        'attn'               => 'a.n.',
        'current_status'     => 'Status saat ini',
        'submission_summary' => 'RINGKASAN PENGAJUAN',
        'approval_flow'      => 'ALUR PERSETUJUAN',
        'lookup'             => [
            'heading'                    => 'Cek progres pengajuan',
            'description'                => 'Masukkan email untuk melihat daftar pengajuan, atau tambahkan ID referensi untuk langsung membuka satu pengajuan.',
            'reference_label'            => 'ID Referensi / ID Status Response (opsional)',
            'reference_placeholder'      => 'Contoh: MAJU-00001',
            'email_label'                => 'Email',
            'email_placeholder'          => 'Email yang dipakai saat pengajuan',
            'submit'                     => 'Cek Progres',
            'results_heading'            => 'Daftar Pengajuan',
            'empty_state'                => 'Belum ada pengajuan untuk email tersebut.',
            'view_progress'              => 'Lihat progres',
            'submitted_at'               => 'Diajukan',
            'amount'                     => 'Nominal',
            'not_found'                  => 'Pengajuan tidak ditemukan. Periksa kembali ID referensi dan email.',
            'rate_limit'                 => [
                'title' => 'Terlalu Banyak Percobaan',
                'body'  => 'Tunggu :seconds detik sebelum mencoba lagi.',
            ],
        ],
    ],

    'submission' => [
        'success_title'         => 'Pengajuan Berhasil Terkirim',
        'success_description'   => 'Simpan :reference_label berikut untuk memantau proses: :uid.',
        'form_label'            => 'Form Transfer',
        'reference_id_label'    => 'ID Referensi',
        'status_response_label' => 'ID Status Response',
        'submit_another'        => 'Kirim pengajuan lain',
        'required_hint'         => '* Wajib diisi',
        'page_of'               => 'Halaman :current dari :total',
    ],

    'approval' => [
        'submission_status_label' => 'Status pengajuan',
        'your_approval_status'    => 'Status persetujuan Anda',
        'submission_summary'      => 'RINGKASAN PENGAJUAN',
        'approval_flow'           => 'ALUR PERSETUJUAN',
        'actions'                 => 'TINDAKAN',
        'reject'                  => 'Tolak',
        'approve'                 => 'Setujui',
        'information'             => 'INFORMASI',
        'completed_info'          => 'Tahap ini sudah diproses. Anda dapat menutup halaman ini.',
        'confirm'                 => [
            'approve_heading' => 'Setujui pengajuan?',
            'approve'         => 'Yakin menyetujui pengajuan ini? Keputusan akan langsung disimpan.',
            'reject_heading'  => 'Tolak pengajuan?',
            'reject'          => 'Yakin menolak pengajuan ini? Keputusan akan langsung disimpan.',
        ],
    ],
];
