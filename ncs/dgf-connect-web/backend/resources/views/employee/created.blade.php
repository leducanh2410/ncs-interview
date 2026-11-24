<x-mail::message>
# Your {{ config('app.name') }} account is ready

Hello {{ $name ?? 'there' }},

An account has been created for you on {{ config('app.name') }}.

<x-mail::panel>
Email: {{ $email }}

Password: {{ $password }}
</x-mail::panel>

Please sign in and change your password after your first login.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
