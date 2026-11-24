<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShiftAbsentNoticeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $employeeName,
        private readonly string $shiftDate,
        private readonly string $workplaceName,
        private readonly string $shiftTime,
        private readonly string $position
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Shift absent notice submitted');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'shift.absent_notice',
            with: [
                'employeeName' => $this->employeeName,
                'shiftDate' => $this->shiftDate,
                'workplaceName' => $this->workplaceName,
                'shiftTime' => $this->shiftTime,
                'position' => $this->position,
            ]
        );
    }
}
