<x-mail::message>
# Mật khẩu của bạn đã được đặt lại

Xin chào {{ $name ?? 'bạn' }},

Mật khẩu {{ config('app.name') }} của bạn đã được quản trị viên đặt lại.

<x-mail::panel>
Email: {{ $email }}

Mật khẩu mới: {{ $password }}
</x-mail::panel>

Vui lòng đăng nhập và đổi mật khẩu sớm nhất có thể.

Trân trọng,<br>
{{ config('app.name') }}
</x-mail::message>
