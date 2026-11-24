<x-mail::message>
# Document Expiry Reminder

Hello {{ $name ?? 'there' }},

This is a friendly reminder that one or more of your documents are about to expire.

To avoid any disruptions or issues, please update or renew your document as soon as possible.
If you’ve already updated it, please disregard this message.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
