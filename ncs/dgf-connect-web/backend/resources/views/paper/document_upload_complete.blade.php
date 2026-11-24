<x-mail::message>
# Documents Uploaded Successfully

Hello {{ $name ?? 'there' }},

We have successfully received your documents.

Our team will review them shortly. You will receive a notification once the verification process is complete.

<x-mail::panel>
If any additional information is required, we’ll contact you via email.
</x-mail::panel>

Thank you for completing this step.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
