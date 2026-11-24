<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class PortraitImageRequiredEmail extends Mailable implements ShouldQueue
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
            markdown: $this->getLocalizedTemplate('paper.portrait_image_required'),
            with: [
                'name' => $this->name,
            ]
        );
    }
}
