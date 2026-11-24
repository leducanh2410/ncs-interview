<x-mail::message>
# {{ config('app.name') }} のアカウントが作成されました

{{ $name ?? 'お客様' }} 様

{{ config('app.name') }} のアカウントが作成されました。

<x-mail::panel>
メールアドレス: {{ $email }}

パスワード: {{ $password }}
</x-mail::panel>

初回ログイン後に、パスワードを変更してください。

よろしくお願いいたします。<br>
{{ config('app.name') }}
</x-mail::message>
