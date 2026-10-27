<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single ticket-tracking link (PRD Phase 10, Rec #13). Minted at public intake
 * and handed back as a magic URL; possession of the opaque {@link $token} is the
 * only credential, so there is no password store and no login for requesters.
 */
class EmployeeToken extends Model
{
    protected $fillable = ['token', 'ticket_id', 'expires_at', 'last_used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Issue a fresh tracking link for a ticket. The token is a random 32-byte
     * hex string (never derived from the id); the link is valid for the given
     * number of days unless a null window is requested (never expires).
     */
    public static function issue(Ticket $ticket, ?int $days = 30): self
    {
        return static::create([
            'token' => bin2hex(random_bytes(32)),
            'ticket_id' => $ticket->id,
            'expires_at' => $days === null ? null : now()->addDays($days),
        ]);
    }

    /**
     * Resolve a presented token to its ticket, or null when it is unknown or past
     * expiry — callers treat both identically (a 404) so nothing is disclosed.
     */
    public static function resolve(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        // A direct lookup is safe here: the token is itself an unguessable random
        // secret handed only to the requester, not a password, so it is stored
        // verbatim. Unknown or past-expiry tokens both resolve to null (a 404).
        $record = static::query()->where('token', $token)->first();

        if (! $record || ($record->expires_at !== null && $record->expires_at->isPast())) {
            return null;
        }

        return $record;
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
