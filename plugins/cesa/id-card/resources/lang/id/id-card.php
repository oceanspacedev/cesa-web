<?php

return [
    'title'      => 'Pembuatan ID Card Sales dan Kurir',
    'navigation' => 'ID Card Sales & Kurir',
    'singular'   => 'Pengajuan ID Card',
    'plural'     => 'Pengajuan ID Card',

    'fields' => [
        'full_name'        => 'Nama Lengkap',
        'shipping_address' => 'Alamat Kirim',
        'business_entity'  => 'Badan Usaha',
        'position'         => 'Jabatan',
        'photo'            => 'Foto',
        'phone'            => 'No. Handphone',
        'creator_id'       => 'Dibuat Oleh',
        'created_at'       => 'Dibuat Pada',
        'updated_at'       => 'Diperbarui Pada',
        'deleted_at'       => 'Dihapus Pada',
    ],

    'business_entities' => [
        'smi' => 'SMI',
        'msi' => 'MSI',
        'top' => 'TOP',
    ],

    'positions' => [
        'sales'   => 'Sales',
        'courier' => 'Kurir',
    ],

    'actions' => [
        'public_form' => 'Buka Form Publik',
        'submit'      => 'Kirim Pengajuan',
        'submitting'  => 'Mengirim...',
    ],

    'helpers' => [
        'shipping_address' => 'Cantumkan alamat lengkap, kecamatan, kota/kabupaten, provinsi, dan kode pos.',
        'phone'            => 'Gunakan nomor aktif, misalnya 081234567890 atau +6281234567890.',
        'photo'            => 'Unggah foto wajah yang jelas dalam format JPG, PNG, atau WebP. Maksimal 5 MB.',
    ],

    'public' => [
        'description'         => 'Lengkapi data berikut untuk mengajukan pembuatan ID card Sales atau Kurir.',
        'required'            => 'Semua kolom bertanda * wajib diisi.',
        'success_title'       => 'Pengajuan berhasil dikirim',
        'success_description' => 'Terima kasih. Pengajuan ID card Anda telah diterima dan akan diproses oleh admin.',
    ],

    'validation' => [
        'phone'        => 'Masukkan nomor handphone Indonesia yang valid, diawali 08, 628, atau +628.',
        'photo'        => 'Unggah foto baru yang valid.',
        'rate_limited' => 'Terlalu banyak percobaan pengiriman. Coba lagi dalam :seconds detik.',
    ],
];
