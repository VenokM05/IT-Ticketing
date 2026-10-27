<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single runtime setting. Secrets live here encrypted-at-rest, never in plain
 * config, and are applied over env values at request boot (see WhatsAppSettings).
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }
}
