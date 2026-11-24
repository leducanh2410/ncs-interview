<x-mail::message>
# Nhắc nhở hết hạn tài liệu

Xin chào {{ $name ?? 'bạn' }},

Đây là lời nhắc nhở thân thiện rằng một hoặc nhiều tài liệu của bạn sắp hết hạn.

Để tránh bất kỳ gián đoạn hoặc vấn đề nào, vui lòng cập nhật hoặc gia hạn tài liệu của bạn càng sớm càng tốt.
Nếu bạn đã cập nhật, vui lòng bỏ qua tin nhắn này.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
