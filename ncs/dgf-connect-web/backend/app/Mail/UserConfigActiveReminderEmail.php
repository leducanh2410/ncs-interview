<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserConfigActiveReminderEmail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var array<int, array<string, mixed>> */
    private readonly array $entries;

    /**
     * Create a new message instance.
     *
     * @param \Illuminate\Support\Collection<int, array{user: \App\Models\User, active_configs: array<int, string>}> $usersWithConfigs
     */
    public function __construct($usersWithConfigs)
    {
        $entries = [];

        foreach ($usersWithConfigs as $item) {
            $user = $item['user'];
            $activeConfigs = $item['active_configs'];

            $configLabels = [];
            if (in_array('max_shift_per_day', $activeConfigs)) {
                $configLabels[] = 'Ca làm/ngày';
            }
            if (in_array('max_hour_per_week', $activeConfigs)) {
                $configLabels[] = 'Giờ làm/tuần';
            }

            $entries[] = [
                'name' => $user->short_name,
                'email' => $user->email,
                'staffNo' => $user->staff_no ?? 'N/A',
                'activeConfigs' => implode(', ', $configLabels),
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

        return new Envelope(subject: "Cảnh báo: {$count} nhân viên còn kích hoạt cấu hình ca làm/giờ làm tùy chỉnh");
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user_config_active_reminder',
            with: [
                'entries' => $this->entries,
            ]
        );
    }
}
