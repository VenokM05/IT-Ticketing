<?php

use App\Models\Priority;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * DB-managed ticket priorities (PRD Phase 9, Rec #1). Beyond the label, each
 * priority carries its own SLA hour budgets (Phase 1) — first-response and
 * resolution — that used to live only in config, so they are now tunable here.
 */
new #[Layout('components.layouts.app')] #[Title('Priorities')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $fKey = '';

    public string $fLabel = '';

    public string $fResponse = '';

    public string $fResolve = '';

    public string $fSort = '0';

    public bool $fActive = true;

    public function mount(): void
    {
        $this->guard();
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function startCreate(): void
    {
        $this->guard();

        $this->reset(['editingId', 'fKey', 'fLabel', 'fResponse', 'fResolve']);
        $this->fSort = (string) (Priority::query()->max('sort') + 1);
        $this->fActive = true;
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $priority = Priority::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $priority->id;
        $this->fKey = $priority->key;
        $this->fLabel = $priority->label;
        $this->fResponse = (string) ($priority->sla_response_hours ?? '');
        $this->fResolve = (string) ($priority->sla_resolve_hours ?? '');
        $this->fSort = (string) $priority->sort;
        $this->fActive = $priority->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fKey', 'fLabel', 'fResponse', 'fResolve', 'fSort']);
        $this->fActive = true;
    }

    public function save(): void
    {
        $this->guard();

        $this->validate([
            'fKey' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('priorities', 'key')->ignore($this->editingId)],
            'fLabel' => ['required', 'string', 'max:120'],
            'fResponse' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'fResolve' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'fSort' => ['required', 'integer', 'min:0'],
        ]);

        $priority = $this->editingId ? Priority::find($this->editingId) : new Priority;
        $priority->fill([
            'key' => strtolower(trim($this->fKey)),
            'label' => trim($this->fLabel),
            'sla_response_hours' => $this->fResponse !== '' ? (int) $this->fResponse : null,
            'sla_resolve_hours' => $this->fResolve !== '' ? (int) $this->fResolve : null,
            'sort' => (int) $this->fSort,
            'is_active' => $this->fActive,
        ]);
        $priority->save();

        app(AuditLogger::class)->record(
            $this->editingId ? 'priority.updated' : 'priority.created',
            $priority,
            [
                'key' => $priority->key,
                'label' => $priority->label,
                'sla_response_hours' => $priority->sla_response_hours,
                'sla_resolve_hours' => $priority->sla_resolve_hours,
            ],
        );

        $this->cancel();
        session()->flash('success', 'Priority saved.');
    }

    public function toggle(int $id): void
    {
        $this->guard();

        $priority = Priority::findOrFail($id);
        $priority->update(['is_active' => ! $priority->is_active]);

        app(AuditLogger::class)->record('priority.toggled', $priority, ['is_active' => $priority->is_active]);

        session()->flash('success', $priority->is_active ? 'Priority enabled.' : 'Priority disabled.');
    }

    public function delete(int $id): void
    {
        $this->guard();

        $priority = Priority::findOrFail($id);
        $key = $priority->key;
        $priority->delete();

        app(AuditLogger::class)->record('priority.deleted', null, ['key' => $key]);

        session()->flash('success', 'Priority deleted. Existing tickets keep a null priority.');
    }

    public function with(): array
    {
        return [
            'priorities' => Priority::query()->ordered()->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Priorities" subtitle="Priority levels and the SLA hour budgets stamped on every new ticket.">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New priority</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit priority' : 'New priority' }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input wire:model="fKey" label="Key" placeholder="e.g. urgent" :disabled="$editingId !== null" />
                <flux:input wire:model="fLabel" label="Label" placeholder="Display name" />
                <flux:input wire:model="fSort" type="number" label="Order" />
                <flux:input wire:model="fResponse" type="number" label="SLA first response (hours)" placeholder="e.g. 2" />
                <flux:input wire:model="fResolve" type="number" label="SLA resolution (hours)" placeholder="e.g. 8" />
                <div class="flex items-end pb-1">
                    <flux:checkbox wire:model="fActive" label="Active" />
                </div>
            </div>
            @error('fKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('fLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <p class="mt-2 text-xs text-fg-subtle">Leave an SLA field blank for no deadline on that milestone. Existing tickets are not re-stamped; only new tickets and later priority changes pick up the new budgets.</p>
            <div class="mt-3 flex gap-2">
                <flux:button wire:click="save" variant="primary">Save</flux:button>
                <flux:button wire:click="cancel" variant="subtle">Cancel</flux:button>
            </div>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Order</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Label</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Key</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Response SLA</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Resolve SLA</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($priorities as $priority)
                        <tr>
                            <td class="px-4 py-2 tabular-nums text-fg-muted">{{ $priority->sort }}</td>
                            <td class="px-4 py-2 font-medium">{{ $priority->label() }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-fg-muted">{{ $priority->key }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $priority->sla_response_hours !== null ? $priority->sla_response_hours.'h' : '—' }}</td>
                            <td class="px-4 py-2 tabular-nums">{{ $priority->sla_resolve_hours !== null ? $priority->sla_resolve_hours.'h' : '—' }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$priority->is_active ? 'green' : 'zinc'" size="sm">{{ $priority->is_active ? 'Active' : 'Disabled' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $priority->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggle({{ $priority->id }})">{{ $priority->is_active ? 'Disable' : 'Enable' }}</flux:button>
                                    <flux:button size="xs" variant="ghost" class="text-red-600" wire:click="delete({{ $priority->id }})" wire:confirm="Delete this priority? Tickets keep a null priority.">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-fg-muted">No priorities yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
