<?php

return [
    'title'      => 'Sales and Courier ID Card Request',
    'navigation' => 'Sales & Courier ID Cards',
    'singular'   => 'ID Card Request',
    'plural'     => 'ID Card Requests',

    'fields' => [
        'full_name'        => 'Full Name',
        'shipping_address' => 'Shipping Address',
        'business_entity'  => 'Business Entity',
        'position'         => 'Position',
        'photo'            => 'Photo',
        'phone'            => 'Mobile Number',
        'creator_id'       => 'Created By',
        'created_at'       => 'Created At',
        'updated_at'       => 'Updated At',
        'deleted_at'       => 'Deleted At',
    ],

    'business_entities' => [
        'smi' => 'SMI',
        'msi' => 'MSI',
        'top' => 'TOP',
    ],

    'positions' => [
        'sales'   => 'Sales',
        'courier' => 'Courier',
    ],

    'actions' => [
        'public_form' => 'Open Public Form',
        'submit'      => 'Submit Request',
        'submitting'  => 'Submitting...',
    ],

    'helpers' => [
        'shipping_address' => 'Include the full address, district, city, province, and postal code.',
        'phone'            => 'Enter an active number, for example 081234567890 or +6281234567890.',
        'photo'            => 'Upload a clear portrait in JPG, PNG, or WebP format. Maximum 5 MB.',
    ],

    'public' => [
        'description'         => 'Complete the form to request an ID card for a Sales or Courier position.',
        'required'            => 'All fields marked with * are required.',
        'success_title'       => 'Request submitted successfully',
        'success_description' => 'Thank you. Your ID card request has been received and will be processed by an administrator.',
    ],

    'validation' => [
        'phone'        => 'Enter a valid Indonesian mobile number beginning with 08, 628, or +628.',
        'photo'        => 'Upload a valid new photo.',
        'rate_limited' => 'Too many submission attempts. Please try again in :seconds seconds.',
    ],
];
