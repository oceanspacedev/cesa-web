<?php

return [
    'navigation' => [
        'label' => 'Recruitment Approvers',
    ],
    'model' => [
        'singular' => 'Recruitment Approver',
        'plural'   => 'Recruitment Approvers',
    ],
    'form' => [
        'sections' => [
            'identity' => 'Approver Identity',
            'scope'    => 'Approval Scope',
        ],
        'fields' => [
            'name'           => 'Name',
            'email'          => 'Email',
            'phone'          => 'Phone',
            'title'          => 'Title',
            'approval_order' => 'Approval Order',
            'company_id'     => 'Business Entity',
            'division_id'    => 'Division',
            'is_active'      => 'Active',
        ],
        'helpers' => [
            'company_id'     => 'Leave blank if the approver applies to every business entity.',
            'division_id'    => 'Leave blank if the approver applies to every division within the selected business entity scope.',
            'approval_order' => 'Use lower numbers for approvers who must review earlier.',
        ],
    ],
    'table' => [
        'columns' => [
            'approval_order' => 'Order',
            'name'           => 'Name',
            'email'          => 'Email',
            'phone'          => 'Phone',
            'title'          => 'Title',
            'company_id'     => 'Business Entity',
            'division_id'    => 'Division',
            'is_active'      => 'Active',
        ],
        'placeholders' => [
            'company_id'  => 'All Business Entities',
            'division_id' => 'All Divisions',
        ],
        'filters' => [
            'company_id'  => 'Business Entity',
            'division_id' => 'Division',
            'is_active'   => 'Active Status',
        ],
    ],
];
