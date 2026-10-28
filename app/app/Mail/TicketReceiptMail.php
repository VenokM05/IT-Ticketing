<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Intake receipt for a public, no-login request. Carries the ticket number and the
 * tokenized tracking link so the link is not stranded on a screen the requester
 * might close. Rendered from a pre-built HTML string (like IncidentBroadcastMail),
 * keeping it assertable under Mail::fake without a dedicated view.
 */
class TicketReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $trackUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your IT request '.$this->ticket->ticket_number);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->receiptHtml());
    }

    private function receiptHtml(): string
    {
        $name = e($this->ticket->requesterName() ?: 'there');
        $number = e($this->ticket->ticket_number);
        $subject = e($this->ticket->subject);
        $url = e($this->trackUrl);

        return <<<html
            <p>Hi {$name},</p>
            <p>Your IT request has been received and filed as <strong>{$number}</strong> — "{$subject}".</p>
            <p><a href="{$url}">Track this request and add updates anytime</a> — no account or password needed.</p>
            <p>Please keep that link: it is the only way to reopen this request without an account. Quote {$number} if you contact IT directly.</p>
            <p>— IT Ticketing</p>
            html;
    }
}
