<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AntiSpoofingRetryFailedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly array $failedVerificationIds,
        private readonly string $errorMessage,
        private readonly string $failedAt
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Lỗi: Kiểm tra chống giả mạo thất bại');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'spoofing.vi.retry_failed',
            with: [
                'failedVerificationIds' => $this->failedVerificationIds,
                'verificationCount' => count($this->failedVerificationIds),
                'errorMessage' => $this->errorMessage,
                'failedAt' => $this->failedAt,
            ]
        );
    }
}
