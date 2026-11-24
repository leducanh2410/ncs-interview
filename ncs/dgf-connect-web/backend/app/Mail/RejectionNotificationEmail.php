<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class RejectionNotificationEmail extends Mailable
{
    use LocalizableMail, Queueable, SerializesModels;

    private readonly string $name;

    private readonly ?string $reason;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, ?string $reason = null)
    {
        $this->name = $user->short_name;
        $this->reason = $reason;
        $this->forUser($user);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: $this->getLocalizedTemplate('paper.rejection_notification'),
            with: [
                'name' => $this->name,
                'reason' => $this->reason,
            ]
        );
    }
}
