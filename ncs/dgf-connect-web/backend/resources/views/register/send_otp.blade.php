<x-mail::message>
# Verify Your Email Address

Hello!

Thank you for signing up. Please use the following OTP (One-Time Password) to verify your email address:

<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire in {{ $expiryIn }} minutes.

If you did not request this email, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
