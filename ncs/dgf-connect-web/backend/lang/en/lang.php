<?php

return [
    'shift_approved' => [
        'title' => 'Shift has been approved',
        'content' => 'Your shift on :date has been approved',
    ],
    'shift_rejected' => [
        'title' => 'Shift has been rejected',
        'content' => 'Your shift on :date has been rejected',
    ],
    'absent_notice_rejected' => [
        'title' => 'Absence request has been rejected',
        'content' =>
            'Your absence request for the shift on :date has been rejected. Please report for the shift as scheduled.',
    ],
    'staff_no_assigned' => [
        'title' => 'Staff number has been assigned',
        'content' => 'You have been assigned a staff number',
    ],
    'paper_approved' => [
        'title' => 'Document has been approved',
        'content' => 'Your document has been approved',
    ],
    'paper_rejected' => [
        'title' => 'Document has been rejected',
        'content' => 'Your document has been rejected. Please update your document',
    ],
    'bank_account_approved' => [
        'title' => 'Payout details approved',
        'content' => 'Your salary payout details have been approved',
    ],
    'bank_account_rejected' => [
        'title' => 'Payout details rejected',
        'content' => 'Your salary payout details were rejected. Reason: :note. Please submit them again',
    ],
    'paper_documents_required' => [
        'title' => 'Documents required',
        'content' =>
            'Your file is missing required documents: :documents. Please submit them to continue registering shifts and checking in.',
    ],
    'paper_document_names' => [
        'separator' => ', ',
        'residence_card' => 'Residence card',
        'passport' => 'Passport',
        'portrait' => 'Portrait photo',
        'student_card' => 'Student card',
        'work_permit' => 'Permission to engage in activity other than that permitted',
        'student_card_expiry_date' => 'Student card (expiry date missing)',
    ],
    'reminder' => [
        'check_out' => [
            'title' => 'Please check the check-out time',
            'content' => 'Remember to check out before the end of your shift',
        ],
        'shift' => [
            'title' => 'You have an upcoming shift',
            'content' => 'You have a shift at :time on :date',
        ],
        'check_in' => [
            'title' => 'Please check the check-in time',
            'content' => 'Remember to check in before starting your shift',
        ],
        'confirm' => [
            'title' => 'Please confirm your shift',
            'content' => 'Please confirm your shift on :date',
        ],
    ],
    'alert' => [
        'unconfirmed' => [
            'title' => 'Employees have not confirmed',
            'content' => 'Shift at :workplace at :time still has :count employee(s) unconfirmed',
        ],
        'missing_check_in' => [
            'title' => 'Employees have not checked in',
            'content' => 'Shift at :workplace at :time has :count employee(s) not checked in',
        ],
    ],
    'document_read_required' => [
        'title' => 'Document Read Required',
        'content' => 'You have required documents to read and confirm',
    ],
    'required_exam' => [
        'title' => 'New required exam available',
        'content' => 'A required exam ":title" is now available. Please complete it.',
    ],
    'date_format' => 'Y/m/d',
    'attendance_reason' => [
        'late' => [
            'title' => 'Employee arrived late',
            'content' =>
                ':name arrived :minutes minutes late for the :shift shift on :date at :workplace. Reason: :reason',
        ],
        'early_leave' => [
            'title' => 'Employee left early',
            'content' =>
                ':name left :minutes minutes early from the :shift shift on :date at :workplace. Reason: :reason',
        ],
    ],
];
