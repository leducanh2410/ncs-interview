<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class PasswordResetByAdminEmail extends Mailable
{
    use LocalizableMail, Queueable, SerializesModels;

    private readonly string $name;

    private readonly string $email;

    private readonly string $password;

    public function __construct(User $user, #[\SensitiveParameter] string $password)
    {
        $this->name = $user->short_name;
        $this->email = $user->email;
        $this->password = $password;
        $this->forUser($user);
        $this->subject = $this->getLocalizedText('password.reset_by_admin_subject');
    }

    public function content(): Content
    {
        return new Content(
            markdown: $this->getLocalizedTemplate('password.reset-by-admin'),
            with: [
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
            ]
        );
    }
}
