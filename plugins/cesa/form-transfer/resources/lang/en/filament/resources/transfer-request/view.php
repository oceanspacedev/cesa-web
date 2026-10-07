<?php

return [
    'transfer_request' => [
        'actions' => [
            'download_pdf'                    => 'Download PDF',
            'download_pdf_filename_prefix'    => 'transfer-request',
            'resend_pending_approver'         => 'Resend to Pending Approver',
            'resend_notification_heading'     => 'Resend notification',
            'resend_notification_description' => 'The notification will be resent to the current pending approver.',
        ],
        'notifications' => [
            'approval_completed_title'   => 'Approval already completed',
            'approval_completed_body'    => 'The approval status is no longer pending.',
            'reminder_not_needed_title'  => 'Approval reminder not needed',
            'reminder_not_needed_body'   => 'Reminders are only sent when approval is pending and realization is still pending. Partial, done, or cancelled realizations are not resent.',
            'no_pending_approver_title'  => 'No pending approver',
            'no_pending_approver_body'   => 'No approver is currently pending.',
            'empty_approver_email_title' => 'Approver email is empty',
            'empty_approver_email_body'  => 'The notification cannot be sent because the approver email has not been filled in.',
            'notification_resent_title'  => 'Notification resent',
            'notification_resent_body'   => 'Sent to :approver.',
        ],
        'defaults' => [
            'approver_name' => 'Approver',
        ],
    ],
];
