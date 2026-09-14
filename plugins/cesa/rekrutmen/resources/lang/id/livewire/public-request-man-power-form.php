<?php

return [
    'layout' => [
        'title' => 'Form Request Man Power',
    ],
    'summary' => [
        'title'       => 'Permintaan Berhasil Dikirim',
        'description' => 'Ringkasan berikut dapat digunakan untuk memastikan data yang Anda kirim sudah sesuai.',
        'fields'      => [
            'status_response_id' => 'ID Tracking',
            'posisi_dibutuhkan'  => 'Posisi Dibutuhkan',
            'nama_pengaju'       => 'Nama Pengaju',
            'status_kebutuhan'   => 'Status Kebutuhan',
            'nama_replacement'   => 'Nama Karyawan Pengganti',
            'progress_url'       => 'Link Progress',
        ],
        'actions' => [
            'submit_another' => 'Kirim Pengajuan Lain',
        ],
    ],
    'header' => [
        'title'       => 'FORM REQUEST MAN POWER',
        'description' => 'Isi formulir berikut untuk mengajukan kebutuhan tenaga kerja.',
        'required'    => '* Wajib diisi',
    ],
    'sections' => [
        'applicant_information'            => 'Informasi Pengaju',
        'position_requirements'            => 'Kebutuhan Posisi',
        'qualifications_and_description'   => 'Kualifikasi & Deskripsi',
        'requirement_status'               => 'Status Kebutuhan',
    ],
    'fields' => [
        'nama_pengaju'               => 'Nama Pengaju',
        'posisi_pengaju'             => 'Posisi / Jabatan Pengaju',
        'email_address'              => 'Email Pengaju',
        'tanggal_pengajuan'          => 'Tanggal Pengajuan',
        'divisi'                     => 'Divisi',
        'division_id'                => 'Divisi',
        'company_id'                 => 'Badan Usaha',
        'posisi_dibutuhkan'          => 'Posisi yang Dibutuhkan',
        'lokasi_penempatan'          => 'Lokasi Penempatan',
        'status_kebutuhan'           => 'Status Kebutuhan',
        'nama_karyawan_replacement'  => 'Nama Karyawan yang Akan Digantikan',
        'level_pekerjaan'            => 'Level Pekerjaan',
        'jumlah_karyawan_dibutuhkan' => 'Jumlah Karyawan Dibutuhkan',
        'estimasi_tanggal_join'      => 'Estimasi Tanggal Join',
        'requirements_kualifikasi'   => 'Kualifikasi yang Dibutuhkan',
        'job_description'            => 'Deskripsi Pekerjaan',
        'keterangan'                 => 'Keterangan Tambahan',
    ],
    'placeholders' => [
        'nama_pengaju'               => 'Nama lengkap pengaju',
        'posisi_pengaju'             => 'Contoh: Manager HRD',
        'email_address'              => 'email@perusahaan.com',
        'posisi_dibutuhkan'          => 'Contoh: Staff Akuntansi',
        'lokasi_penempatan'          => 'Contoh: Jakarta Pusat',
        'nama_karyawan_replacement'  => 'Contoh: Budi Santoso',
        'requirements_kualifikasi'   => 'Syarat pendidikan, pengalaman, dan keterampilan yang dibutuhkan...',
        'job_description'            => 'Uraian tugas dan tanggung jawab posisi yang dibutuhkan...',
        'keterangan'                 => 'Informasi tambahan yang relevan (opsional)',
    ],
    'helper_texts' => [
        'nama_karyawan_replacement' => 'Untuk kebutuhan replacement, isi nama karyawan yang akan digantikan.',
    ],
    'actions' => [
        'submit' => 'Kirim Pengajuan',
    ],
    'pagination' => [
        'single_page' => 'Halaman :current dari :total',
    ],
    'notifications' => [
        'success' => [
            'title' => 'Berhasil',
            'body'  => 'Pengajuan Request Man Power berhasil dikirim!',
        ],
        'validation' => [
            'title' => 'Validasi gagal',
            'body'  => 'Silakan periksa kembali data yang diisi.',
        ],
    ],
    'errors' => [
        'nama_karyawan_replacement_required' => 'Nama karyawan pengganti wajib diisi.',
        'system'                             => 'Terjadi kesalahan sistem, silakan coba lagi.',
        'recaptcha_required'                 => 'Verifikasi reCAPTCHA wajib diisi.',
        'recaptcha_failed'                   => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi.',
    ],
    'common' => [
        'not_available' => '—',
    ],
];
