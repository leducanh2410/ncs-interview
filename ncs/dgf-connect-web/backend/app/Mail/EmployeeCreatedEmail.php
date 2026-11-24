<?php

namespace App\Mail;

use App\Mail\Traits\LocalizableMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class EmployeeCreatedEmail extends Mailable
{
    use LocalizableMail, Queueable, SerializesModels;

    private readonly string $name;

    private readonly string $email;

    private readonly string $password;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, #[\SensitiveParameter] string $password)
    {
        $this->name = $user->short_name;
        $this->email = $user->email;
        $this->password = $password;
        $this->forUser($user);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: $this->getLocalizedTemplate('employee.created'),
            with: [
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
            ]
        );
    }
}
