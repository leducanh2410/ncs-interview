<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class DocumentUploadCompleteEmail extends Mailable implements ShouldQueue
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

        // Callers send this while a DB transaction holds row locks: the mail must not
        // block the request on SMTP, and must not be dispatched if the transaction rolls back
        $this->afterCommit();
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: $this->getLocalizedTemplate('paper.document_upload_complete'),
            with: [
                'name' => $this->name,
            ]
        );
    }
}
