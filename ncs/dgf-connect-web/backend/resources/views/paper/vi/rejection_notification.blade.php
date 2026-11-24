<x-mail::message>
# Đơn của bạn đã bị từ chối

Xin chào {{ $name ?? 'bạn' }},

Chúng tôi rất tiếc phải thông báo rằng hồ sơ của bạn đã được xem xét và **bị từ chối**.

@if($reason)
**Lý do:** {{ $reason }}
@endif

Vui lòng đăng nhập vào tài khoản của bạn để xem phản hồi và cập nhật hồ sơ theo yêu cầu.

Nếu bạn có bất kỳ câu hỏi hoặc cần làm rõ, vui lòng liên hệ với đội ngũ hỗ trợ của chúng tôi.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
