<x-mail::message>
# Action Required: Portrait Image Needed

Hello {{ $name ?? 'there' }},

We need your help to complete your profile setup. **A portrait image is required** for our face recognition system.

## What You Need to Do

Please open the mobile app and upload a high-quality portrait photo following these guidelines:

- **Clear face visibility:** Your face should be clearly visible and centered
- **Good lighting:** Use natural or bright lighting - avoid shadows on your face
- **No obstructions:** Remove hats, sunglasses, or anything covering your face
- **Plain background:** Use a simple, uncluttered background if possible
- **Direct camera angle:** Face the camera directly, not at an angle

## How to Upload

1. Open the DGF Connect mobile app
2. Go to your profile section
3. Upload your portrait image
4. Wait for approval

Your account access may be limited until a valid portrait image is uploaded and approved.

If you have any questions or need assistance, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
