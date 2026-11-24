<x-mail::message>
# Your Application Has Been Approved

Hello {{ $name ?? 'there' }},

Great news! Your submission has been reviewed and **approved**.

You can now proceed to the next step in the process. Please log in to your account to view the updated status and available actions.

If you have any questions, feel free to contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
