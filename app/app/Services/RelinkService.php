<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RelinkRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Number-relink workflow (PRD §9.3). A request is raised from an unrecognized
 * number; the requested number is matched to an existing employee record only
 * on Administrator approval, and never auto-merges records.
 */
class RelinkService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Raise (idempotently) a pending relink request for a number we could not match.
     */
    public function request(string $normalizedNumber, ?string $sourceMessageId = null): RelinkRequest
    {
        $request = RelinkRequest::query()->firstOrCreate(
            [
                'requested_number' => $normalizedNumber,
                'status' => RelinkRequest::STATUS_PENDING,
            ],
            ['source_message_id' => $sourceMessageId],
        );

        if ($request->wasRecentlyCreated) {
            $this->notifications->relinkRequested($request);
        }

        return $request;
    }

    /**
     * Approve: point the chosen employee's WhatsApp number at the requested number.
     * Enforces active-number uniqueness (§7.2) and records the decision.
     */
    public function approve(RelinkRequest $request, Employee $employee, User $admin): RelinkRequest
    {
        return DB::transaction(function () use ($request, $employee, $admin) {
            $locked = RelinkRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['relink' => 'This request has already been decided.']);
            }

            $clash = Employee::query()
                ->where('whatsapp_number', $locked->requested_number)
                ->where('is_active', true)
                ->whereKeyNot($employee->id)
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'relink' => "An active employee already uses {$locked->requested_number}.",
                ]);
            }

            $employee->update(['whatsapp_number' => $locked->requested_number]);

            $locked->update([
                'employee_id' => $employee->id,
                'status' => RelinkRequest::STATUS_APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
            ]);

            return $locked;
        });
    }

    public function reject(RelinkRequest $request, User $admin): RelinkRequest
    {
        $request->update([
            'status' => RelinkRequest::STATUS_REJECTED,
            'decided_by' => $admin->id,
            'decided_at' => now(),
        ]);

        return $request;
    }
}
