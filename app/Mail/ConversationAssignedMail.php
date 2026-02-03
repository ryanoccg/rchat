<?php

namespace App\Mail;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConversationAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public User $user
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title . ' - RChat',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conversation-assigned',
            with: [
                'notification' => $this->notification,
                'user' => $this->user,
                'actionUrl' => config('app.url') . ($this->notification->action_url ?? '/dashboard'),
            ],
        );
    }
}
