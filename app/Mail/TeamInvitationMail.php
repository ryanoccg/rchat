<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public TeamInvitation $invitation;
    public string $inviterName;
    public string $companyName;
    public string $acceptUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(TeamInvitation $invitation)
    {
        $this->invitation = $invitation;
        $this->inviterName = $invitation->invitedBy->name ?? 'A team member';
        $this->companyName = $invitation->company->name ?? 'the company';
        $this->acceptUrl = url("/accept-invitation/{$invitation->token}");
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->companyName} on RChat",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.team-invitation',
            with: [
                'invitation' => $this->invitation,
                'inviterName' => $this->inviterName,
                'companyName' => $this->companyName,
                'acceptUrl' => $this->acceptUrl,
                'role' => $this->invitation->role,
                'expiresAt' => $this->invitation->expires_at->format('F j, Y'),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
