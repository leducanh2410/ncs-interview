<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class ShiftUserScheduledEmail extends Mailable
{
    use Queueable, SerializesModels;

    private readonly string $name;

    private readonly string $shiftDate;

    /**
     * Create a new message instance.
     */
    public function __construct(string $name, string $shiftDate)
    {
        $this->name = $name;
        $this->shiftDate = $shiftDate;
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'shift.shift_user_scheduled',
            with: [
                'name' => $this->name,
                'shiftDate' => $this->shiftDate,
            ]
        );
    }
}
