<x-mail::message>
# Your Application Has Been Rejected

Hello {{ $name ?? 'there' }},

We regret to inform you that your submission has been reviewed and **rejected**.

@if($reason)
**Reason:** {{ $reason }}
@endif

Please log in to your account to review the feedback and update your submission accordingly.

If you have any questions or need clarification, feel free to contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
