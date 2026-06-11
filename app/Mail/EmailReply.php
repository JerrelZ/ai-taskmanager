<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A reply on an email thread, with the threading headers (Message-ID,
 * In-Reply-To, References) set so receiving clients nest it correctly.
 */
class EmailReply extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $references  bare reference ids (no angle brackets)
     */
    public function __construct(
        public string $fromAddress,
        public string $toAddress,
        public string $subjectLine,
        public string $bodyText,
        public string $messageId,
        public ?string $inReplyTo = null,
        public array $references = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress),
            to: [new Address($this->toAddress)],
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $this->inReplyTo !== null ? ['In-Reply-To' => "<{$this->inReplyTo}>"] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reply',
            with: ['body' => $this->bodyText],
        );
    }
}
