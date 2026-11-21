<?php

namespace App\Mail;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A short, plain-text note to the team that someone filled in a website form. */
class NewEnquiryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Enquiry $enquiry) {}

    public function envelope(): Envelope
    {
        $what = match ($this->enquiry->type) {
            'demo' => 'New demo request',
            'quote' => 'New quote request',
            default => 'New interest registration',
        };

        return new Envelope(
            subject: "{$what} from {$this->enquiry->organization}",
            replyTo: [new Address($this->enquiry->email, $this->enquiry->full_name)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.new-enquiry');
    }
}
