<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Central attachment rules (PRD Phase 8, Rec #9). Any channel that stores a
 * ticket file — currently WhatsApp inbound media — routes through here so the
 * MIME allow-list, size ceiling and filename sanitisation are enforced in one
 * place. Files are always persisted to a private disk, never the webroot.
 */
class AttachmentPolicy
{
    /** True when the MIME type is on the configured allow-list. */
    public static function allowsMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));

        return in_array($mime, self::allowedMimeTypes(), true);
    }

    /** @return array<int, string> */
    public static function allowedMimeTypes(): array
    {
        return array_map('strtolower', (array) config('tickets.attachments.allowed_mime_types', []));
    }

    /** True when the byte size fits within the configured KB ceiling. */
    public static function withinSize(int $bytes): bool
    {
        $maxKb = (int) config('tickets.attachments.max_size_kb', 20_480);

        return $bytes > 0 && $bytes <= $maxKb * 1024;
    }

    /**
     * Strip path separators, traversal and control characters from a supplied
     * file name, collapsing everything unsafe to underscores.
     */
    public static function sanitizeName(string $name): string
    {
        $base = Str::of($name)
            ->afterLast('/')
            ->afterLast('\\')
            ->replaceMatches('/[^A-Za-z0-9._-]/', '_')
            ->trim('._');

        return (string) ($base->length() > 0 ? $base : 'file');
    }
}
