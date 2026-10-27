<?php

namespace App\Services;

use App\Models\SystemSetting;
use Throwable;

/**
 * Runtime WhatsApp credentials editable by administrators. Values stored here
 * take precedence over the env-driven config, so a key saved in Settings takes
 * effect immediately without touching .env or redeploying.
 */
class WhatsAppSettings
{
    /** Config keys an admin can override at runtime, mapped to their setting keys. */
    public const OVERRIDABLE = [
        'access_token' => 'whatsapp.access_token',
        'app_secret' => 'whatsapp.app_secret',
        'verify_token' => 'whatsapp.verify_token',
    ];

    public static function accessToken(): ?string
    {
        $value = self::stored('whatsapp.access_token');

        return $value ?? ((string) config('services.whatsapp.access_token') !== '' ? config('services.whatsapp.access_token') : null);
    }

    /**
     * DB-first resolution for any whatsapp config key; falls back to the
     * env-backed config value when the admin has not overridden it.
     */
    public static function credential(string $key): mixed
    {
        $settingKey = self::OVERRIDABLE[$key] ?? null;

        if ($settingKey === null) {
            return config('services.whatsapp.'.$key);
        }

        return self::stored($settingKey) ?? config('services.whatsapp.'.$key);
    }

    public static function hasStoredAccessToken(): bool
    {
        return self::stored('whatsapp.access_token') !== null;
    }

    public static function setAccessToken(string $token): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'whatsapp.access_token'],
            ['value' => trim($token)],
        );
    }

    public static function clearAccessToken(): void
    {
        SystemSetting::query()->where('key', 'whatsapp.access_token')->delete();
    }

    /**
     * Masked preview for display — shows only the last four characters so an
     * admin can confirm which key is set without exposing the secret.
     */
    public static function masked(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $tail = substr($token, -4);

        return '****'.($tail !== '' ? $tail : $token);
    }

    /**
     * Reads are guarded because the table may not exist yet (first migrate,
     * artisan running before migrations) — fall back to config silently.
     */
    private static function stored(string $key): ?string
    {
        try {
            return SystemSetting::query()->where('key', $key)->value('value');
        } catch (Throwable) {
            return null;
        }
    }
}
