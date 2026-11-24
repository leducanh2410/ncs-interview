<x-mail::message>
# Tải tài liệu lên thành công

Xin chào {{ $name ?? 'bạn' }},

Chúng tôi đã nhận được tài liệu của bạn thành công.

Đội ngũ của chúng tôi sẽ xem xét trong thời gian sớm nhất. Bạn sẽ nhận được thông báo sau khi quá trình xác minh hoàn tất.

<x-mail::panel>
Nếu cần thêm thông tin, chúng tôi sẽ liên hệ với bạn qua email.
</x-mail::panel>

Cảm ơn bạn đã hoàn thành bước này.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
