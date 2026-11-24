<x-mail::message>
# Tài khoản {{ config('app.name') }} của bạn đã sẵn sàng

Xin chào {{ $name ?? 'bạn' }},

Tài khoản của bạn trên {{ config('app.name') }} đã được tạo.

<x-mail::panel>
Email: {{ $email }}

Mật khẩu: {{ $password }}
</x-mail::panel>

Vui lòng đăng nhập và đổi mật khẩu sau lần đăng nhập đầu tiên.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
