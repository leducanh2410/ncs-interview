<x-mail::message>
# Your password has been reset

Hello {{ $name ?? 'there' }},

Your {{ config('app.name') }} password was reset by an administrator.

<x-mail::panel>
Email: {{ $email }}

New password: {{ $password }}
</x-mail::panel>

Please sign in and change your password as soon as possible.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
