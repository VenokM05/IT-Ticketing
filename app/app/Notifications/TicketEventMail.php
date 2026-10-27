<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A plain-text email alert for a ticket event (PRD Phase 7). Sent alongside the
 * in-app dashboard row; the mail channel is self-skipped when the recipient has
 * opted out of email (notify_email = false), so callers never need to branch.
 */
class TicketEventMail extends Notification
{
    use Queueable;

    public function __construct(
        public string $subject,
        public string $body,
        public ?string $url = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->notify_email ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->body);

        if ($this->url !== null) {
            $mail->action('View ticket', $this->url);
        }

        return $mail->line('— IT Ticketing');
    }
}
