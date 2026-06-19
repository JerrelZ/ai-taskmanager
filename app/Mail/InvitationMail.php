<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Je bent uitgenodigd voor :workspace', [
                'workspace' => $this->invitation->workspace->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'workspaceName' => $this->invitation->workspace->name,
                'inviterName' => $this->invitation->inviter?->name,
                'acceptUrl' => route('invitations.accept', $this->invitation->token),
            ],
        );
    }
}
