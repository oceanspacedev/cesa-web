<?php

return [
    'label'   => 'Department|Departments',
    'actions' => [
        'export' => 'Export Departments',
    ],
    'exports' => [
        'notifications' => [
            'completed_body' => 'The department export finished with :success exported row(s) and :failed failed row(s).',
        ],
    ],
    'fields' => [
        'code'            => 'Code',
        'name'            => 'Name',
        'description'     => 'Description',
        'approvers'       => 'Approvers',
        'approvers_count' => 'Approver Count',
        'archived'        => 'Archived',
        'archived_yes'    => 'Yes',
        'archived_no'     => 'No',
        'deleted_suffix'  => 'Deleted',
    ],
];
