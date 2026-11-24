<?php

return [
    'shift_approved' => [
        'title' => 'Ca làm việc đã được phê duyệt',
        'content' => 'Ca làm việc của bạn vào ngày :date đã được phê duyệt',
    ],
    'shift_rejected' => [
        'title' => 'Ca làm việc đã bị từ chối',
        'content' => 'Ca làm việc của bạn vào ngày :date đã bị từ chối',
    ],
    'absent_notice_rejected' => [
        'title' => 'Đơn xin nghỉ đã bị từ chối',
        'content' => 'Đơn xin nghỉ ca làm việc ngày :date của bạn đã bị từ chối. Vui lòng đi làm theo lịch.',
    ],
    'staff_no_assigned' => [
        'title' => 'Đã cấp mã nhân viên',
        'content' => 'Bạn đã được cấp mã nhân viên',
    ],
    'paper_approved' => [
        'title' => 'Hồ sơ đã được phê duyệt',
        'content' => 'Hồ sơ của bạn đã được phê duyệt',
    ],
    'paper_rejected' => [
        'title' => 'Hồ sơ đã bị từ chối',
        'content' => 'Hồ sơ của bạn đã bị từ chối. Vui lòng cập nhật lại hồ sơ',
    ],
    'bank_account_approved' => [
        'title' => 'Thông tin nhận lương đã được duyệt',
        'content' => 'Thông tin nhận lương của bạn đã được duyệt',
    ],
    'bank_account_rejected' => [
        'title' => 'Thông tin nhận lương bị từ chối',
        'content' => 'Thông tin nhận lương của bạn bị từ chối. Lý do: :note. Vui lòng gửi lại',
    ],
    'paper_documents_required' => [
        'title' => 'Cần bổ sung hồ sơ',
        'content' =>
            'Hồ sơ của bạn đang thiếu giấy tờ bắt buộc: :documents. Vui lòng bổ sung để tiếp tục đăng ký ca và chấm công.',
    ],
    'paper_document_names' => [
        'separator' => ', ',
        'residence_card' => 'Thẻ cư trú',
        'passport' => 'Hộ chiếu',
        'portrait' => 'Ảnh chân dung',
        'student_card' => 'Thẻ sinh viên',
        'work_permit' => 'Giấy phép hoạt động ngoài tư cách lưu trú',
        'student_card_expiry_date' => 'Thẻ sinh viên (thiếu ngày hết hạn)',
    ],
    'reminder' => [
        'check_out' => [
            'title' => 'Hãy kiểm tra giờ check out',
            'content' => 'Hãy nhớ check out trước khi kết thúc ca làm việc',
        ],
        'shift' => [
            'title' => 'Bạn có ca làm việc sắp tới',
            'content' => 'Bạn có ca làm việc vào lúc :time ngày :date',
        ],
        'check_in' => [
            'title' => 'Hãy kiểm tra giờ check in',
            'content' => 'Hãy nhớ check in trước khi bắt đầu ca làm việc',
        ],
        'confirm' => [
            'title' => 'Vui lòng xác nhận ca làm việc',
            'content' => 'Hãy xác nhận ca làm việc vào ngày :date',
        ],
    ],
    'alert' => [
        'unconfirmed' => [
            'title' => 'Nhân viên chưa xác nhận ca làm việc',
            'content' => 'Ca làm việc tại :workplace lúc :time vẫn còn :count nhân viên chưa xác nhận',
        ],
        'missing_check_in' => [
            'title' => 'Nhân viên chưa check in',
            'content' => 'Ca làm việc tại :workplace lúc :time có :count nhân viên chưa check in',
        ],
    ],
    'document_read_required' => [
        'title' => 'Yêu cầu đọc tài liệu',
        'content' => 'Bạn có tài liệu bắt buộc cần đọc và xác nhận',
    ],
    'required_exam' => [
        'title' => 'Có bài kiểm tra bắt buộc mới',
        'content' => 'Bài kiểm tra bắt buộc ":title" đã sẵn sàng. Vui lòng hoàn thành.',
    ],
    'date_format' => 'd/m/Y',
    'attendance_reason' => [
        'late' => [
            'title' => 'Nhân viên đi muộn',
            'content' => ':name đi muộn :minutes phút ca :shift ngày :date tại :workplace. Lý do: :reason',
        ],
        'early_leave' => [
            'title' => 'Nhân viên về sớm',
            'content' => ':name về sớm :minutes phút ca :shift ngày :date tại :workplace. Lý do: :reason',
        ],
    ],
];
