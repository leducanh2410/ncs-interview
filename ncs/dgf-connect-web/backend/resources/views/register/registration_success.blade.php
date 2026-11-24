<x-mail::message>
# Welcome to {{ config('app.name') }}!

Hello {{ $name ?? 'there' }},

Thank you for registering with us. Your account has been successfully created.

We're excited to have you on board! You can now log in and start using our services.

If you have any questions or need support, feel free to contact us at any time.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
