<?php

return [
    'transfer_request' => [
        'actions' => [
            'download_pdf'                    => 'Unduh PDF',
            'download_pdf_filename_prefix'    => 'pengajuan-transfer',
            'resend_pending_approver'         => 'Kirim ulang ke approver pending',
            'resend_notification_heading'     => 'Kirim ulang notifikasi',
            'resend_notification_description' => 'Notifikasi akan dikirim ulang ke approver pending di tahap saat ini.',
        ],
        'notifications' => [
            'approval_completed_title'   => 'Approval sudah selesai',
            'approval_completed_body'    => 'Status approval sudah bukan pending.',
            'reminder_not_needed_title'  => 'Tidak perlu reminder approval',
            'reminder_not_needed_body'   => 'Reminder hanya untuk approval pending dengan realisasi masih pending. Realisasi partial/done/cancelled tidak dikirim ulang.',
            'no_pending_approver_title'  => 'Tidak ada approver pending',
            'no_pending_approver_body'   => 'Tidak ditemukan approver yang sedang pending.',
            'empty_approver_email_title' => 'Email approver kosong',
            'empty_approver_email_body'  => 'Notifikasi tidak dapat dikirim karena email approver belum diisi.',
            'notification_resent_title'  => 'Notifikasi dikirim ulang',
            'notification_resent_body'   => 'Dikirim ke :approver.',
        ],
        'defaults' => [
            'approver_name' => 'Approver',
        ],
    ],
];
