<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Inbound media handling (PRD §8.4, §7.10). Resolves a WhatsApp media object to
 * its authenticated download URL, fetches the bytes, stores them privately on the
 * default disk and records a ticket_attachments row linked to the inbound message.
 *
 * The outbound Meta call is gated behind WHATSAPP_ENABLED: when disabled (no live
 * credentials) the intake still records the message but no binary is fetched, so
 * the pipeline stays exercisable offline. Media references are transient — never
 * persisted on the webhook-event row (§13.1).
 */
class WhatsAppMediaService
{
    private const MAX_BYTES = 100_000_000; // 100 MB Cloud API media ceiling.

    public function __construct(private readonly WhatsAppCloudApi $api)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.whatsapp.enabled');
    }

    /**
     * @param  array{id?: string, mime_type?: string|null, filename?: string|null}  $media
     * @return TicketAttachment|null null when disabled, no media id, or the fetch fails
     */
    public function attach(Ticket $ticket, TicketMessage $message, array $media): ?TicketAttachment
    {
        if (! $this->enabled()) {
            return null;
        }

        $mediaId = $media['id'] ?? null;

        if (! $mediaId) {
            return null;
        }

        try {
            $meta = $this->api->mediaUrl($mediaId);
            $bytes = $this->api->downloadMedia($meta['url']);
        } catch (\Throwable) {
            // A media-download failure must never break ticket intake; the message
            // row is already persisted and the event can be replayed (§8.6).
            return null;
        }

        $size = strlen($bytes);

        if ($size === 0 || $size > self::MAX_BYTES) {
            return null;
        }

        $mime = $meta['mime_type'] ?? $media['mime_type'] ?? 'application/octet-stream';
        $original = $meta['filename'] ?? $media['filename'] ?? $this->fallbackName($mediaId, $mime);
        $path = sprintf('wa-attachments/%d/%s-%s', $ticket->id, (string) Str::uuid(), $this->safeName($original));

        Storage::disk('local')->put($path, $bytes);

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => $message->id,
            'original_filename' => Str::limit($original, 240, ''),
            'mime_type' => $mime,
            'file_size' => $size,
            'storage_path' => $path,
        ]);
    }

    private function fallbackName(string $mediaId, string $mime): string
    {
        $extension = match (true) {
            str_starts_with($mime, 'image/jpeg') => 'jpg',
            str_starts_with($mime, 'image/png') => 'png',
            str_starts_with($mime, 'image/') => substr($mime, strrpos($mime, '/') + 1),
            str_starts_with($mime, 'audio/mpeg') => 'mp3',
            str_starts_with($mime, 'video/mp4') => 'mp4',
            str_starts_with($mime, 'application/pdf') => 'pdf',
            default => 'bin',
        };

        return 'whatsapp-media-'.$mediaId.'.'.$extension;
    }

    /** Strip path separators and control characters from a supplied file name. */
    private function safeName(string $name): string
    {
        $base = Str::of($name)->afterLast('/')->afterLast('\\')->replaceMatches('/[^A-Za-z0-9._-]/', '_')->trim('._');

        return (string) ($base->length() > 0 ? $base : 'media');
    }
}
