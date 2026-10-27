<?php

use App\Models\ActivityLog;
use App\Services\AuditLogger;
use App\Services\WhatsAppSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Administrator WhatsApp API configuration (Settings section). The API key saved
 * here is encrypted at rest and takes precedence over the env-configured token,
 * so outbound sends, webhook verification and media downloads pick it up on the
 * very next request — no redeploy required.
 */
new #[Layout('components.layouts.app')] #[Title('WhatsApp Settings')] class extends Component {
    public string $apiKey = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'apiKey' => ['required', 'string', 'max:500'],
        ], [
            'apiKey.required' => 'Enter the WhatsApp Business API key.',
        ]);

        WhatsAppSettings::setAccessToken($this->apiKey);

        // Audit records only the masked tail, never the secret itself.
        app(AuditLogger::class)->record('whatsapp.token_updated', null, [
            'masked' => WhatsAppSettings::masked($this->apiKey),
        ]);

        $this->reset('apiKey');
        session()->flash('whatsapp-saved', 'WhatsApp API key saved. It takes effect immediately.');
    }

    public function remove(): void
    {
        $this->authorizeAdmin();

        WhatsAppSettings::clearAccessToken();

        app(AuditLogger::class)->record('whatsapp.token_cleared');

        session()->flash('whatsapp-saved', 'Stored WhatsApp API key removed.');
    }

    public function with(): array
    {
        return [
            'stored' => WhatsAppSettings::hasStoredAccessToken(),
            'masked' => WhatsAppSettings::masked(WhatsAppSettings::accessToken()),
            'audit' => ActivityLog::query()
                ->where('event', 'like', 'whatsapp.%')
                ->with('actor')
                ->latest('created_at')
                ->limit(5)
                ->get(),
        ];
    }
}; ?>

<div class="flex flex-col items-start">
    <div class="mb-6 w-full text-start">
        <flux:heading size="xl">Settings</flux:heading>
        <flux:subheading>Manage your profile and account settings</flux:subheading>
    </div>

    <x-settings.layout heading="WhatsApp" subheading="Connect the WhatsApp Business Cloud API used for ticket intake and notifications">
        <div class="flex flex-col gap-6">
            @if (session('whatsapp-saved'))
                <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('whatsapp-saved') }}</div>
            @endif

            {{-- Current key state: masked, never the full secret. --}}
            <div class="card p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold">Current API key</h3>
                        <p class="mt-1 text-sm text-fg-muted">
                            @if ($masked)
                                <span class="font-mono">{{ $masked }}</span>
                                <span class="text-fg-subtle">({{ $stored ? 'set from Settings' : 'from server environment' }})</span>
                            @else
                                No API key is configured yet.
                            @endif
                        </p>
                    </div>
                    @if ($masked)
                        <flux:badge :color="$stored ? 'green' : 'amber'" size="sm" class="shrink-0">{{ $stored ? 'Configured' : 'Env only' }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm" class="shrink-0">Not set</flux:badge>
                    @endif
                </div>
            </div>

            <form wire:submit="save" class="flex flex-col gap-4">
                <flux:input
                    wire:model="apiKey"
                    label="API Key"
                    type="password"
                    autocomplete="off"
                    placeholder="WhatsApp Business API token from Meta (starts with EAApp…)"
                />
                <p class="-mt-2 text-xs text-fg-subtle">
                    Used for sending messages, template delivery, and media downloads. Stored encrypted; only the last four characters are ever displayed.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="submit" variant="primary" icon="lock-closed" class="tap">
                        {{ $stored ? 'Update key' : 'Save key' }}
                    </flux:button>
                    @if ($stored)
                        <flux:button wire:click="remove" variant="subtle" class="tap">Remove stored key</flux:button>
                    @endif
                </div>
            </form>

            {{-- Compact audit trail of credential changes (Phase 8, Rec #10). --}}
            <div class="card p-4">
                <h3 class="text-sm font-semibold">Recent changes</h3>
                @if ($audit->isEmpty())
                    <p class="mt-2 text-sm text-fg-muted">No WhatsApp settings changes recorded yet.</p>
                @else
                    <ul class="mt-2 divide-y divide-border text-sm">
                        @foreach ($audit as $row)
                            <li class="flex items-center justify-between py-2">
                                <span>{{ $row->event === 'whatsapp.token_updated' ? 'API key updated' : 'API key removed' }}</span>
                                <span class="text-fg-muted">{{ $row->actor?->name ?? 'System' }} · {{ $row->created_at?->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-settings.layout>
</div>
