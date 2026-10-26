<?php

namespace App\Support;

/**
 * Fixed, case-insensitive command grammar and validity checks for inbound
 * WhatsApp text (PRD §9.4, §9.5).
 */
final class MessageGrammar
{
    /**
     * @return array{name: string, arg: string}|null
     */
    public static function parseCommand(string $body): ?array
    {
        $t = trim($body);

        if (preg_match('/^NEW\s+TICKET\b\s*(.*)$/i', $t, $m)) {
            return ['name' => 'new', 'arg' => trim($m[1])];
        }

        if (preg_match('/^STATUS\b\s*(.*)$/i', $t, $m)) {
            return ['name' => 'status', 'arg' => strtoupper(trim($m[1]))];
        }

        if (preg_match('/^CLOSE\b\s*(.*)$/i', $t, $m)) {
            return ['name' => 'close', 'arg' => strtoupper(trim($m[1]))];
        }

        if (preg_match('/^REGISTER\b\s*(.*)$/i', $t, $m)) {
            return ['name' => 'register', 'arg' => trim($m[1])];
        }

        return null;
    }

    public static function isRegister(string $body): bool
    {
        return (bool) preg_match('/^\s*REGISTER\b/i', trim($body));
    }

    /**
     * @return array<int, string> distinct IT-YYYY-NNNNNN references found in the body
     */
    public static function extractTicketNumbers(string $body): array
    {
        $matches = [];
        preg_match_all('/IT-\d{4}-\d{6}/i', $body, $matches);

        return array_values(array_unique(array_map(
            static fn (string $n) => strtoupper($n),
            $matches[0] ?? [],
        )));
    }

    /** §9.5 mechanical validity for a candidate new request. */
    public static function isValidNewRequest(string $body, bool $isMedia): bool
    {
        if ($isMedia) {
            return true;
        }

        $trimmed = trim($body);

        return mb_strlen($trimmed) >= 5 && ! self::isPureGreeting($trimmed);
    }

    public static function isPureGreeting(string $body): bool
    {
        // Strip punctuation/emoji, collapse whitespace.
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($body))));

        if ($normalized === '') {
            return true; // emoji-only or punctuation-only
        }

        $greetings = [
            'hi', 'hii', 'hello', 'hey', 'yo', 'sup', 'ok', 'okay', 'k',
            'yes', 'no', 'thanks', 'thank you', 'thankyou', 'thx', 'ty',
            'good morning', 'good afternoon', 'good evening', 'gm', 'gn',
            'test', 'ping', 'cool', 'nice', 'salamat',
        ];

        return in_array($normalized, $greetings, true);
    }
}
