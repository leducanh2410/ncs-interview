<x-mail::message>
# Đơn của bạn đã được phê duyệt

Xin chào {{ $name ?? 'bạn' }},

Tin tốt lành! Hồ sơ của bạn đã được xem xét và **phê duyệt**.

Bạn có thể tiến hành bước tiếp theo trong quy trình. Vui lòng đăng nhập vào tài khoản của bạn để xem trạng thái cập nhật và các hành động có sẵn.

Nếu bạn có bất kỳ câu hỏi nào, vui lòng liên hệ với đội ngũ hỗ trợ của chúng tôi.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
