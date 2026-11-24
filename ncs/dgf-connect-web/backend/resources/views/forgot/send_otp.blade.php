<x-mail::message>
# Verify Your Email Address

Hello!

You are receiving this email because we received a password reset request for your account.
Please use the following OTP to reset your password:

<x-mail::panel>
**{{ $otp }}**
</x-mail::panel>

This OTP will expire in {{ $expiryIn }} minutes.

If you did not request this email, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
