<?php

namespace App\Jobs;

use App\Enums\WebhookProcessingStatus;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsAppInboundService;
use App\Services\WhatsAppOutboundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Background processing of a persisted webhook event (PRD §8.6). Recoverable:
 * the event row records success/failure so a crashed worker or API error can be
 * retried or replayed without duplicating side effects (idempotent event_key).
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $message
     */
    public function __construct(
        public int $eventId,
        public array $message,
        public string $kind,
    ) {
    }

    public function handle(WhatsAppInboundService $inbound, WhatsAppOutboundService $outbound): void
    {
        $event = WhatsAppWebhookEvent::find($this->eventId);

        if (! $event) {
            return;
        }

        try {
            if ($this->kind === 'status') {
                $outbound->reconcileDelivery(
                    (string) ($this->message['wa_message_id'] ?? ''),
                    (string) ($this->message['status'] ?? ''),
                );
            } else {
                $inbound->handle($this->message);
            }

            $event->update([
                'processing_status' => WebhookProcessingStatus::Processed,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $event->update([
                'processing_status' => WebhookProcessingStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
