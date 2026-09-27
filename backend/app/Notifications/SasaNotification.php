<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification type, driven by a configurable rule. The rule decides the
 * recipients, the channels and the wording; this class only delivers it.
 */
class SasaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $eventKey,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $payload = [],
        public readonly array $channels = ['database'],
        public readonly string $severity = 'info',
    ) {}

    public function via(object $notifiable): array
    {
        $map = ['in_app' => 'database', 'email' => 'mail'];
        $via = [];

        foreach ($this->channels as $channel) {
            $mapped = $map[$channel] ?? null;

            if ($mapped === 'mail' && ! $notifiable->email) {
                continue;
            }

            if ($mapped && ! in_array($mapped, $via, true)) {
                $via[] = $mapped;
            }
        }

        return $via ?: ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('SASA · '.$this->subject)
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($this->body);

        if (! empty($this->payload['url'])) {
            $message->action('Open in SASA', rtrim(config('sasa.web_url'), '/').$this->payload['url']);
        }

        return $message->line('You are receiving this because of a notification rule on your project. You can change your preferences in SASA.');
    }

    public function toArray(object $notifiable): array
    {
        return array_merge([
            'event' => $this->eventKey,
            'subject' => $this->subject,
            'body' => $this->body,
            'severity' => $this->severity,
        ], $this->payload);
    }
}
