<?php

return [
    'shift_approved' => [
        'title' => 'මුරය අනුමත විය',
        'content' => ':date දින මුරය අනුමත විය',
    ],
    'shift_rejected' => [
        'title' => 'මුරය ප්‍රතික්ෂේප විය',
        'content' => ':date දින මුරය ප්‍රතික්ෂේප විය',
    ],
    'absent_notice_rejected' => [
        'title' => 'නිවාඩු ඉල්ලීම ප්‍රතික්ෂේප විය',
        'content' =>
            ':date දින මුරය සඳහා ඔබගේ නිවාඩු ඉල්ලීම ප්‍රතික්ෂේප විය. කරුණාකර උපලේඛනයට අනුව රාජකාරියට වාර්තා කරන්න.',
    ],
    'staff_no_assigned' => [
        'title' => 'කාර්ය මණ්ඩල අංකය ලබා දී ඇත',
        'content' => 'ඔබට කාර්ය මණ්ඩල අංකයක් ලබා දී ඇත',
    ],
    'paper_approved' => [
        'title' => 'ලේඛනය අනුමත විය',
        'content' => 'ඔබේ ලේඛනය අනුමත විය',
    ],
    'paper_rejected' => [
        'title' => 'ලේඛනය ප්‍රතික්ෂේප විය',
        'content' => 'ලේඛනය ප්‍රතික්ෂේප විය. කරුණාකර යාවත්කාලීන කරන්න',
    ],
    'bank_account_approved' => [
        'title' => 'වැටුප් ගෙවීම් තොරතුරු අනුමත විය',
        'content' => 'ඔබගේ වැටුප් ගෙවීම් තොරතුරු අනුමත විය',
    ],
    'bank_account_rejected' => [
        'title' => 'වැටුප් ගෙවීම් තොරතුරු ප්‍රතික්ෂේප විය',
        'content' => 'ඔබගේ වැටුප් ගෙවීම් තොරතුරු ප්‍රතික්ෂේප විය. හේතුව: :note. කරුණාකර නැවත ඉදිරිපත් කරන්න',
    ],
    'paper_documents_required' => [
        'title' => 'ලේඛන එක් කිරීම අවශ්‍යයි',
        'content' =>
            'ඔබගේ ගොනුවේ අවශ්‍ය ලේඛන නොමැත: :documents. මාරු ලියාපදිංචිය සහ පැමිණීම සටහන් කිරීම දිගටම කරගෙන යාමට කරුණාකර ඒවා ඉදිරිපත් කරන්න.',
    ],
    'paper_document_names' => [
        'separator' => ', ',
        'residence_card' => 'පදිංචි කාඩ්පත',
        'passport' => 'ගමන් බලපත්‍රය',
        'portrait' => 'ඡායාරූපය',
        'student_card' => 'ශිෂ්‍ය හැඳුනුම්පත',
        'work_permit' => 'අවසර ලත් ක්‍රියාකාරකම්වලින් පිටත ක්‍රියාකාරකම් සඳහා අවසරය',
        'student_card_expiry_date' => 'ශිෂ්‍ය හැඳුනුම්පත (කල් ඉකුත් වන දිනය නොමැත)',
    ],
    'reminder' => [
        'check_out' => [
            'title' => 'පිටවීමේ වේලාව බලන්න',
            'content' => 'මුරය අවසන් වීමට පෙර පිටවීම සටහන් කරන්න',
        ],
        'shift' => [
            'title' => 'ඉදිරි මුරයක් ඇත',
            'content' => ':date දින :time ට මුරයක් ඇත',
        ],
        'check_in' => [
            'title' => 'පැමිණීමේ වේලාව බලන්න',
            'content' => 'මුරය ආරම්භයට පෙර පැමිණීම සටහන් කරන්න',
        ],
        'confirm' => [
            'title' => 'මුරය තහවුරු කරන්න',
            'content' => ':date දින මුරය තහවුරු කරන්න',
        ],
    ],
    'alert' => [
        'unconfirmed' => [
            'title' => 'සේවකයන් තහවුරු කර නැත',
            'content' => ':workplace හි :time මුරයේ තවමත් සේවකයන් :count ක් තහවුරු කර නැත',
        ],
        'missing_check_in' => [
            'title' => 'සේවකයන් පැමිණීම සටහන් කර නැත',
            'content' => ':workplace හි :time මුරයේ සේවකයන් :count ක් පැමිණීම සටහන් කර නැත',
        ],
    ],
    'document_read_required' => [
        'title' => 'ලේඛන කියවීම අවශ්‍යයි',
        'content' => 'කියවා තහවුරු කළ යුතු ලේඛන තිබේ',
    ],
    'required_exam' => [
        'title' => 'නව අනිවාර්ය විභාගයක් ඇත',
        'content' => 'අනිවාර්ය විභාගය ":title" දැන් ලබා ගත හැක. කරුණාකර සම්පූර්ණ කරන්න.',
    ],
    'date_format' => 'Y/m/d',
    'attendance_reason' => [
        'late' => [
            'title' => 'සේවකයා ප්‍රමාද වී පැමිණියේය',
            'content' => ':name :date දින :workplace හි :shift මුරයට මිනිත්තු :minutes ක් ප්‍රමාද විය. හේතුව: :reason',
        ],
        'early_leave' => [
            'title' => 'සේවකයා කලින් පිටව ගියේය',
            'content' =>
                ':name :date දින :workplace හි :shift මුරයෙන් මිනිත්තු :minutes ක් කලින් පිටව ගියේය. හේතුව: :reason',
        ],
    ],
];
