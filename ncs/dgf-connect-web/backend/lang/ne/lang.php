<?php

return [
    'shift_approved' => [
        'title' => 'शिफ्ट स्वीकृत भयो',
        'content' => ':date को शिफ्ट स्वीकृत भयो',
    ],
    'shift_rejected' => [
        'title' => 'शिफ्ट अस्वीकृत भयो',
        'content' => ':date को शिफ्ट अस्वीकृत भयो',
    ],
    'absent_notice_rejected' => [
        'title' => 'बिदा अनुरोध अस्वीकृत भयो',
        'content' => ':date को शिफ्टको लागि तपाईंको बिदा अनुरोध अस्वीकृत भयो। कृपया तालिका अनुसार काममा आउनुहोस्।',
    ],
    'staff_no_assigned' => [
        'title' => 'स्टाफ नम्बर तोकियो',
        'content' => 'तपाईंलाई स्टाफ नम्बर तोकिएको छ',
    ],
    'paper_approved' => [
        'title' => 'कागजात स्वीकृत भयो',
        'content' => 'तपाईंको कागजात स्वीकृत भयो',
    ],
    'paper_rejected' => [
        'title' => 'कागजात अस्वीकृत भयो',
        'content' => 'कागजात अस्वीकृत भयो। कृपया अद्यावधिक गर्नुहोस्',
    ],
    'bank_account_approved' => [
        'title' => 'तलब भुक्तानी विवरण स्वीकृत भयो',
        'content' => 'तपाईंको तलब भुक्तानी विवरण स्वीकृत भयो',
    ],
    'bank_account_rejected' => [
        'title' => 'तलब भुक्तानी विवरण अस्वीकृत भयो',
        'content' => 'तपाईंको तलब भुक्तानी विवरण अस्वीकृत भयो। कारण: :note। कृपया फेरि पेश गर्नुहोस्',
    ],
    'paper_documents_required' => [
        'title' => 'कागजात थप्न आवश्यक छ',
        'content' =>
            'तपाईंको फाइलमा आवश्यक कागजात छैन: :documents। सिफ्ट दर्ता र चेक-इन जारी राख्न कृपया कागजात पेश गर्नुहोस्।',
    ],
    'paper_document_names' => [
        'separator' => ', ',
        'residence_card' => 'निवास कार्ड',
        'passport' => 'राहदानी',
        'portrait' => 'फोटो',
        'student_card' => 'विद्यार्थी परिचयपत्र',
        'work_permit' => 'अनुमति प्राप्त बाहेकका गतिविधि गर्ने अनुमति',
        'student_card_expiry_date' => 'विद्यार्थी परिचयपत्र (म्याद समाप्ति मिति छैन)',
    ],
    'reminder' => [
        'check_out' => [
            'title' => 'चेक-आउट समय हेर्नुहोस्',
            'content' => 'शिफ्ट सकिनुअघि चेक-आउट गर्न नबिर्सनुहोस्',
        ],
        'shift' => [
            'title' => 'आगामी शिफ्ट छ',
            'content' => ':date मा :time बजे शिफ्ट छ',
        ],
        'check_in' => [
            'title' => 'चेक-इन समय हेर्नुहोस्',
            'content' => 'शिफ्ट सुरु हुनुअघि चेक-इन गर्न नबिर्सनुहोस्',
        ],
        'confirm' => [
            'title' => 'शिफ्ट पुष्टि गर्नुहोस्',
            'content' => ':date को शिफ्ट पुष्टि गर्नुहोस्',
        ],
    ],
    'alert' => [
        'unconfirmed' => [
            'title' => 'कर्मचारीहरूले पुष्टि गरेका छैनन्',
            'content' => ':workplace मा :time को शिफ्टमा अझै :count कर्मचारी पुष्टि नभएका छन्',
        ],
        'missing_check_in' => [
            'title' => 'कर्मचारीहरू चेक-इन गरेका छैनन्',
            'content' => ':workplace मा :time को शिफ्टमा :count कर्मचारी चेक-इन नगरेका छन्',
        ],
    ],
    'document_read_required' => [
        'title' => 'कागजात पढ्नु आवश्यक छ',
        'content' => 'पढेर पुष्टि गर्नुपर्ने कागजात छ',
    ],
    'required_exam' => [
        'title' => 'नयाँ अनिवार्य परीक्षा उपलब्ध छ',
        'content' => 'अनिवार्य परीक्षा ":title" अब उपलब्ध छ। कृपया पूरा गर्नुहोस्।',
    ],
    'date_format' => 'Y/m/d',
    'attendance_reason' => [
        'late' => [
            'title' => 'कर्मचारी ढिलो आए',
            'content' => ':name :date मा :workplace को :shift सिफ्टमा :minutes मिनेट ढिलो आए। कारण: :reason',
        ],
        'early_leave' => [
            'title' => 'कर्मचारी चाँडै गए',
            'content' => ':name :date मा :workplace को :shift सिफ्टबाट :minutes मिनेट चाँडै गए। कारण: :reason',
        ],
    ],
];
