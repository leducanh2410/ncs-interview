<?php

namespace App\Mail;

use App\Helpers\UrlHelper;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SpoofingDetectedEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<int, array<string, mixed>> */
    private readonly array $entries;

    /**
     * Create a new message instance.
     */
    public function __construct(Collection $verifications)
    {
        $entries = [];

        foreach ($verifications as $verification) {
            $verification->load(['checkIn.user', 'checkIn.shift.workplace', 'checkIn.shift.workplaceShift']);

            $user = $verification->checkIn->user;
            $shift = $verification->checkIn->shift;
            $workplace = $shift->workplace;
            $workplaceShift = $shift->workplaceShift;

            $entries[] = [
                'userName' => $user->short_name,
                'staffNo' => $user->staff_no ?? 'N/A',
                'workplaceName' => $workplace->name ?? 'N/A',
                'shiftDate' => Carbon::parse($shift->date)->format('d/m/Y'),
                'shiftTime' => ($workplaceShift->start_time ?? 'N/A') . ' - ' . ($workplaceShift->end_time ?? 'N/A'),
                'verificationType' => $verification->is_check_in ? 'Check-in' : 'Check-out',
                'spoofedConfidence' => number_format($verification->spoofed_confidence * 100, 2),
                'detectedAt' => Carbon::parse($verification->updated_at)->format('d/m/Y H:i:s'),
                'imageUrl' => $verification->image_path ? UrlHelper::getCdnUrl($verification->image_path) : null,
            ];
        }

        $this->entries = $entries;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $count = count($this->entries);

        return new Envelope(subject: "Cảnh báo: Phát hiện {$count} check-in/check-out giả mạo");
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'spoofing.vi.spoofing_detected',
            with: [
                'entries' => $this->entries,
            ]
        );
    }
}
