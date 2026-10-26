<?php

namespace App\Services;

use App\Enums\WebhookEventType;
use App\Enums\WebhookProcessingStatus;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppWebhookEvent;

/**
 * Durable inbox writer (PRD §8.2 step 5-6, §8.3). Persists each webhook message
 * or delivery-status change under a composite, idempotent event_key, then
 * dispatches background processing. Only dedupe metadata is stored on the event
 * row — the inbound body is passed to the job transiently and never persisted
 * for senders we cannot identify (§9.2, §13.1).
 */
class WebhookEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     * @return int number of newly-dispatched events
     */
    public function record(array $payload): int
    {
        $dispatched = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    $dispatched += $this->recordInbound($message) ? 1 : 0;
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $dispatched += $this->recordStatus($status) ? 1 : 0;
                }
            }
        }

        return $dispatched;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function recordInbound(array $message): bool
    {
        $waId = $message['id'] ?? null;

        if (! $waId) {
            return false;
        }

        $key = WhatsAppWebhookEvent::buildEventKey(WebhookEventType::Inbound, $waId);

        $event = WhatsAppWebhookEvent::query()->firstOrCreate(
            ['event_key' => $key],
            [
                'event_type' => WebhookEventType::Inbound->value,
                'processing_status' => WebhookProcessingStatus::Pending->value,
                'payload' => [
                    'from' => $message['from'] ?? null,
                    'message_id' => $waId,
                    'type' => $message['type'] ?? 'text',
                    'context_id' => $message['context']['id'] ?? null,
                    'timestamp' => isset($message['timestamp']) ? (int) $message['timestamp'] : null,
                ],
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return false; // duplicate delivery — already recorded (§8.3)
        }

        ProcessWhatsAppWebhook::dispatch($event->id, $this->normalizeInbound($message), 'inbound');

        return true;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function recordStatus(array $status): bool
    {
        $waId = $status['id'] ?? null;

        if (! $waId) {
            return false;
        }

        $key = WhatsAppWebhookEvent::buildEventKey(WebhookEventType::Status, $waId);

        $event = WhatsAppWebhookEvent::query()->firstOrCreate(
            ['event_key' => $key],
            [
                'event_type' => WebhookEventType::Status->value,
                'processing_status' => WebhookProcessingStatus::Pending->value,
                'payload' => [
                    'message_id' => $waId,
                    'status' => $status['status'] ?? null,
                    'recipient' => $status['recipient_id'] ?? null,
                    'timestamp' => isset($status['timestamp']) ? (int) $status['timestamp'] : null,
                ],
            ],
        );

        if (! $event->wasRecentlyCreated) {
            return false;
        }

        ProcessWhatsAppWebhook::dispatch(
            $event->id,
            ['wa_message_id' => $waId, 'status' => $status['status'] ?? null],
            'status',
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function normalizeInbound(array $message): array
    {
        $type = $message['type'] ?? 'text';

        $body = match ($type) {
            'text' => $message['text']['body'] ?? '',
            'image', 'video', 'document', 'audio', 'sticker' => $message[$type]['caption'] ?? '',
            default => $message['text']['body'] ?? '',
        };

        $normalized = [
            'from' => $message['from'] ?? null,
            'message_id' => $message['id'] ?? null,
            'type' => $type,
            'body' => $body,
            'context_id' => $message['context']['id'] ?? null,
            'timestamp' => isset($message['timestamp']) ? (int) $message['timestamp'] : null,
        ];

        // Media id is a transient reference passed to the job (like the body) so it
        // can be downloaded; it is never persisted on the event row (§13.1).
        if (isset($message[$type]['id'])) {
            $normalized['media'] = [
                'id' => $message[$type]['id'],
                'mime_type' => $message[$type]['mime_type'] ?? null,
                'filename' => $message[$type]['filename'] ?? $message[$type]['file_name'] ?? null,
            ];
        }

        return $normalized;
    }
}
