<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class ApprovalNotificationEmail extends Mailable
{
    use LocalizableMail, Queueable, SerializesModels;

    private readonly string $name;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->name = $user->short_name;
        $this->forUser($user);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: $this->getLocalizedTemplate('paper.approval_notification'),
            with: [
                'name' => $this->name,
            ]
        );
    }
}
