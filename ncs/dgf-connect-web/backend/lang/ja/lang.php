<?php

return [
    'shift_approved' => [
        'title' => 'シフトが承認されました',
        'content' => ':date のシフトが承認されました',
    ],
    'shift_rejected' => [
        'title' => 'シフトが拒否されました',
        'content' => ':date のシフトが拒否されました',
    ],
    'absent_notice_rejected' => [
        'title' => '欠勤申請が却下されました',
        'content' => ':date のシフトの欠勤申請が却下されました。予定どおり出勤してください。',
    ],
    'staff_no_assigned' => [
        'title' => 'スタッフコードが発行されました',
        'content' => 'あなたにスタッフコードが発行されました',
    ],
    'paper_approved' => [
        'title' => '書類が承認されました',
        'content' => 'あなたの書類が承認されました',
    ],
    'paper_rejected' => [
        'title' => '書類が拒否されました',
        'content' => 'あなたの書類が拒否されました。書類を更新してください',
    ],
    'bank_account_approved' => [
        'title' => '給与受取情報が承認されました',
        'content' => 'あなたの給与受取情報が承認されました',
    ],
    'bank_account_rejected' => [
        'title' => '給与受取情報が差し戻されました',
        'content' => 'あなたの給与受取情報が差し戻されました。理由: :note。再度提出してください',
    ],
    'paper_documents_required' => [
        'title' => '書類の追加提出が必要です',
        'content' =>
            '必要な書類が不足しています：:documents。シフト登録・打刻を続けるには、書類を追加提出してください。',
    ],
    'paper_document_names' => [
        'separator' => '、',
        'residence_card' => '在留カード',
        'passport' => 'パスポート',
        'portrait' => '顔写真',
        'student_card' => '学生証',
        'work_permit' => '資格外活動許可',
        'student_card_expiry_date' => '学生証（有効期限未登録）',
    ],
    'reminder' => [
        'check_out' => [
            'title' => 'チェックアウト時間を確認してください',
            'content' => 'シフト終了前にチェックアウトすることを忘れないでください',
        ],
        'shift' => [
            'title' => '次のシフトがあります',
            'content' => ':date の :time にシフトがあります',
        ],
        'check_in' => [
            'title' => 'チェックイン時間を確認してください',
            'content' => 'シフト開始前にチェックインすることを忘れないでください',
        ],
        'confirm' => [
            'title' => 'シフトを確認してください',
            'content' => ':date のシフトを確認してください',
        ],
    ],
    'alert' => [
        'unconfirmed' => [
            'title' => '未確認の従業員がいます',
            'content' => ':workplace の :time のシフトでまだ :count 名の従業員が未確認です',
        ],
        'missing_check_in' => [
            'title' => 'チェックインしていない従業員がいます',
            'content' => ':workplace の :time のシフトで :count 名の従業員がチェックインしていません',
        ],
    ],
    'document_read_required' => [
        'title' => '書類の読み確認が必要です',
        'content' => '読んで確認する必要がある書類があります',
    ],
    'required_exam' => [
        'title' => '必須試験が公開されました',
        'content' => '必須試験「:title」が利用可能になりました。受験してください。',
    ],
    'date_format' => 'Y/m/d',
    'attendance_reason' => [
        'late' => [
            'title' => '従業員の遅刻',
            'content' => ':nameさんが:date :workplaceの:shiftシフトに:minutes分遅刻しました。理由: :reason',
        ],
        'early_leave' => [
            'title' => '従業員の早退',
            'content' => ':nameさんが:date :workplaceの:shiftシフトを:minutes分早退しました。理由: :reason',
        ],
    ],
];
