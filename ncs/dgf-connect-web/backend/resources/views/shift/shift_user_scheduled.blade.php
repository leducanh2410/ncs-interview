<x-mail::message>
# Your Schedule Is Ready

Hello {{ $name ?? 'there' }},

You have been scheduled for the following:

<x-mail::panel>
**Date:** {{ $shiftDate }}
</x-mail::panel>

Please make sure to be available at the scheduled time. You can find more details in your account.

If you have any questions or need to reschedule, please contact us as soon as possible.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
