<?php

return [
    'layout' => [
        'title' => 'Manpower Request Form',
    ],
    'summary' => [
        'title'       => 'Request Submitted Successfully',
        'description' => 'Use the following summary to confirm that the submitted information is correct.',
        'fields'      => [
            'status_response_id' => 'Tracking ID',
            'posisi_dibutuhkan'  => 'Requested Position',
            'nama_pengaju'       => 'Requester Name',
            'status_kebutuhan'   => 'Requirement Status',
            'nama_replacement'   => 'Replacement Employee Name',
            'progress_url'       => 'Progress Link',
        ],
        'actions' => [
            'submit_another' => 'Submit Another Request',
        ],
    ],
    'header' => [
        'title'       => 'MANPOWER REQUEST FORM',
        'description' => 'Complete the following form to submit a manpower request.',
        'required'    => '* Required',
    ],
    'sections' => [
        'applicant_information'            => 'Applicant Information',
        'position_requirements'            => 'Position Requirements',
        'qualifications_and_description'   => 'Qualifications & Description',
        'requirement_status'               => 'Requirement Status',
    ],
    'fields' => [
        'nama_pengaju'               => 'Requester Name',
        'posisi_pengaju'             => 'Requester Position / Title',
        'email_address'              => 'Requester Email',
        'tanggal_pengajuan'          => 'Submission Date',
        'divisi'                     => 'Division',
        'division_id'                => 'Division',
        'company_id'                 => 'Business Entity',
        'posisi_dibutuhkan'          => 'Requested Position',
        'lokasi_penempatan'          => 'Placement Location',
        'status_kebutuhan'           => 'Requirement Status',
        'nama_karyawan_replacement'  => 'Employee to Be Replaced',
        'level_pekerjaan'            => 'Job Level',
        'jumlah_karyawan_dibutuhkan' => 'Number of Employees Needed',
        'estimasi_tanggal_join'      => 'Estimated Join Date',
        'requirements_kualifikasi'   => 'Required Qualifications',
        'job_description'            => 'Job Description',
        'keterangan'                 => 'Additional Notes',
    ],
    'placeholders' => [
        'nama_pengaju'               => 'Requester full name',
        'posisi_pengaju'             => 'Example: HR Manager',
        'email_address'              => 'email@company.com',
        'posisi_dibutuhkan'          => 'Example: Accounting Staff',
        'lokasi_penempatan'          => 'Example: Central Jakarta',
        'nama_karyawan_replacement'  => 'Example: Budi Santoso',
        'requirements_kualifikasi'   => 'Required education, experience, and skills...',
        'job_description'            => 'Job duties and responsibilities for the requested position...',
        'keterangan'                 => 'Additional relevant information (optional)',
    ],
    'helper_texts' => [
        'nama_karyawan_replacement' => 'For replacement requests, enter the name of the employee being replaced.',
    ],
    'actions' => [
        'submit' => 'Submit Request',
    ],
    'pagination' => [
        'single_page' => 'Page :current of :total',
    ],
    'notifications' => [
        'success' => [
            'title' => 'Success',
            'body'  => 'The manpower request has been submitted successfully!',
        ],
        'validation' => [
            'title' => 'Validation failed',
            'body'  => 'Please review the submitted data.',
        ],
    ],
    'errors' => [
        'nama_karyawan_replacement_required' => 'The replacement employee name is required.',
        'system'                             => 'A system error occurred. Please try again.',
        'recaptcha_required'                 => 'reCAPTCHA verification is required.',
        'recaptcha_failed'                   => 'reCAPTCHA verification failed. Please try again.',
    ],
    'common' => [
        'not_available' => '—',
    ],
];
