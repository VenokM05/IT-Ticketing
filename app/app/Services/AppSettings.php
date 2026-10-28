<?php

namespace App\Services;

use App\Models\SystemSetting;
use Throwable;

/**
 * Runtime branding editable by administrators (Settings › Branding). The project
 * title and logo are stored in the same encrypted key/value table as the WhatsApp
 * credentials, so a change made here is picked up on the very next request without
 * touching .env. The title is applied over config('app.name') at boot, so the
 * browser title, sidebar and logo component all reflect it from one source.
 */
class AppSettings
{
    public const TITLE_KEY = 'branding.title';

    public const LOGO_KEY = 'branding.logo';

    /** The stored project title, or null when the admin has not set one. */
    public static function title(): ?string
    {
        $value = self::stored(self::TITLE_KEY);

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * The relative path (on the private "local" disk) of the uploaded logo, or
     * null when none has been set.
     */
    public static function logo(): ?string
    {
        $value = self::stored(self::LOGO_KEY);

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * Persist (or clear) the project title. An empty string removes the override
     * so the app falls back to the env-configured name.
     */
    public static function setTitle(?string $title): void
    {
        $title = $title === null ? '' : trim($title);

        if ($title === '') {
            SystemSetting::query()->where('key', self::TITLE_KEY)->delete();

            return;
        }

        SystemSetting::query()->updateOrCreate(['key' => self::TITLE_KEY], ['value' => $title]);
    }

    public static function setLogo(?string $path): void
    {
        if ($path === null || $path === '') {
            SystemSetting::query()->where('key', self::LOGO_KEY)->delete();

            return;
        }

        SystemSetting::query()->updateOrCreate(['key' => self::LOGO_KEY], ['value' => $path]);
    }

    /**
     * Reads are guarded because the table may not exist yet (first migrate, or
     * artisan running before migrations) — fall back silently so boot never fails.
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
