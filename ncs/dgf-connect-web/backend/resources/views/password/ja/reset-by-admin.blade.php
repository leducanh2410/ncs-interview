<x-mail::message>
# パスワードがリセットされました

{{ $name ?? 'お客様' }} 様

{{ config('app.name') }} のパスワードが管理者によってリセットされました。

<x-mail::panel>
メールアドレス: {{ $email }}

新しいパスワード: {{ $password }}
</x-mail::panel>

ログイン後、できるだけ早くパスワードを変更してください。

よろしくお願いいたします。<br>
{{ config('app.name') }}
</x-mail::message>
