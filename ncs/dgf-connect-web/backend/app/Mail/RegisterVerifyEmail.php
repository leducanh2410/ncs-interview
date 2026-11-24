<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class RegisterVerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    private readonly string $otp;

    private readonly int $expiresIn;

    /**
     * Create a new message instance.
     */
    public function __construct(string $otp, int $expiresIn)
    {
        $this->otp = $otp;
        $this->expiresIn = $expiresIn;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'register.send_otp',
            with: [
                'otp' => $this->otp,
                'expiryIn' => $this->expiresIn,
            ]
        );
    }
}
