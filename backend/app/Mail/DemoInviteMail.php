<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A short, plain-text invitation into a new demo workspace, with the "set your password" link. */
class DemoInviteMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $organisation,
        public readonly string $email,
        public readonly string $inviteUrl,
        public readonly string $endsOn,
        public readonly int $linkHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your SASA demo is ready');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.demo-invite');
    }
}
