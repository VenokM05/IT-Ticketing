<?php

use App\Enums\NotificationType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Mail\IncidentBroadcastMail;
use App\Models\DashboardNotification;
use App\Models\Employee;
use App\Models\User;
use App\Services\WhatsAppOutboundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Incident Broadcast')] class extends Component {
    public string $subject = '';

    public string $message = '';

    public bool $sendEmail = true;

    public bool $sendWhatsApp = true;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Requesters of live tickets — enrolled employees carrying at least one
     * non-closed ticket. Deduplicated because a person can hold several tickets.
     */
    private function recipients()
    {
        return Employee::query()
            ->where('is_active', true)
            ->whereHas('tickets', fn (Builder $q) => $q->where('status', '!=', TicketStatus::Closed->value))
            ->get();
    }

    public function send(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $recipients = $this->recipients();
        $combined = $this->subject."\n\n".$this->message;
        $emailed = 0;
        $messaged = 0;
        $whatsappOn = (bool) config('services.whatsapp.enabled');

        // Honour an explicit per-channel opt-out when a requester also holds a staff
        // account on the same address (users can opt out in profile settings).
        $optedOutEmail = User::query()->where('notify_email', false)->pluck('email')->filter()->map(fn ($email) => strtolower((string) $email))->all();
        $optedOutWhatsapp = User::query()->where('notify_whatsapp', false)->pluck('email')->filter()->map(fn ($email) => strtolower((string) $email))->all();

        foreach ($recipients as $employee) {
            $key = strtolower((string) $employee->email);

            if ($this->sendEmail && $employee->email && ! in_array($key, $optedOutEmail, true)) {
                Mail::to($employee->email)->send(new IncidentBroadcastMail($this->subject, $this->message));
                $emailed++;
            }

            if ($this->sendWhatsApp && $whatsappOn && $employee->whatsapp_number && ! in_array($key, $optedOutWhatsapp, true)) {
                if (app(WhatsAppOutboundService::class)->sendPlainText($employee->whatsapp_number, $combined)) {
                    $messaged++;
                }
            }
        }

        // Audit trail: a dashboard row for every active admin recording the broadcast.
        $refId = (int) now()->format('YmdHis');
        User::query()->where('role', UserRole::Admin->value)->where('is_active', true)->get()
            ->each(fn (User $admin) => DashboardNotification::query()->create([
                'user_id' => $admin->id,
                'type' => NotificationType::IncidentBroadcast->value,
                'ticket_id' => null,
                'ref_id' => $refId,
                'title' => 'Broadcast: '.$this->subject,
                'message' => "Delivered to {$emailed} email and {$messaged} WhatsApp recipient(s).",
            ]));

        session()->flash('success', "Broadcast delivered to {$emailed} email and {$messaged} WhatsApp recipient(s).");
        $this->reset(['subject', 'message']);
    }

    public function with(): array
    {
        return [
            'recipientCount' => $this->recipients()->count(),
            'whatsappEnabled' => (bool) config('services.whatsapp.enabled'),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Incident Broadcast" subtitle="Notify every requester with a live ticket at once"/>

    <div class="card p-4 max-w-2xl">
        <p class="text-sm text-fg-muted">
            This reaches <span class="font-medium text-fg">{{ $recipientCount }}</span> requester(s) with an open ticket.
            Use it for planned maintenance or wide-impact outages.
        </p>

        <form wire:submit="send" class="mt-4 space-y-4">
            <flux:input wire:model="subject" label="Subject" placeholder="Scheduled network maintenance Saturday 8pm" />
            <flux:textarea wire:model="message" label="Message" rows="5" placeholder="What is happening, who is affected and what to do…" />

            <div class="flex flex-wrap gap-4">
                <flux:checkbox wire:model="sendEmail" label="Send email" />
                <flux:checkbox wire:model="sendWhatsApp" label="Send WhatsApp" :disabled="! $whatsappEnabled" />
                @unless ($whatsappEnabled)
                    <span class="text-xs text-fg-subtle self-center">WhatsApp is disabled.</span>
                @endunless
            </div>

            <flux:button type="submit" variant="primary">Send broadcast</flux:button>
        </form>
    </div>
</div>
