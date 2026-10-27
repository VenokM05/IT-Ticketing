<?php

use App\Enums\TicketCategory;
use App\Models\CannedResponse;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Canned Responses')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $fTitle = '';

    public string $fBody = '';

    public string $fCategory = '';

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

        $this->reset(['editingId', 'fTitle', 'fBody', 'fCategory']);
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $macro = CannedResponse::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $macro->id;
        $this->fTitle = $macro->title;
        $this->fBody = $macro->body;
        $this->fCategory = (string) ($macro->category_key ?? '');
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fTitle', 'fBody', 'fCategory']);
    }

    public function save(): void
    {
        $this->guard();

        $this->validate([
            'fTitle' => ['required', 'string', 'max:255'],
            'fBody' => ['required', 'string', 'max:5000'],
            'fCategory' => ['nullable', 'string', 'in:'.implode(',', array_column(TicketCategory::cases(), 'value'))],
        ]);

        $macro = $this->editingId ? CannedResponse::find($this->editingId) : new CannedResponse;
        $macro->fill([
            'title' => trim($this->fTitle),
            'body' => $this->fBody,
            'category_key' => $this->fCategory !== '' ? $this->fCategory : null,
        ]);

        if (! $this->editingId) {
            $macro->created_by = auth()->id();
        }

        $macro->save();

        $this->cancel();
        session()->flash('success', 'Canned response saved.');
    }

    public function delete(int $id): void
    {
        $this->guard();

        CannedResponse::findOrFail($id)->delete();

        session()->flash('success', 'Canned response deleted.');
    }

    public function with(): array
    {
        return [
            'macros' => CannedResponse::query()->with('author')->orderBy('title')->get(),
            'categories' => TicketCategory::cases(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Canned Responses" subtitle="Reusable snippets technicians insert when logging progress or replying.">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New response</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit response' : 'New response' }}</h2>
            <div class="mt-3 space-y-3">
                <flux:input wire:model="fTitle" label="Title" placeholder="e.g. Password reset steps" />
                <flux:select wire:model="fCategory" label="Category (optional)" placeholder="General">
                    <flux:select.option value="">No category</flux:select.option>
                    @foreach ($categories as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:textarea wire:model="fBody" label="Body" rows="4" placeholder="Write the snippet. Placeholders are substituted when a technician inserts it." />
                @verbatim
                    <p class="text-xs text-fg-subtle">Supported placeholders: {{ticket_number}}, {{requester_name}}, {{subject}}, {{technician}}</p>
                @endverbatim
            </div>
            @error('fBody') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
                        <th class="px-4 py-2 font-medium text-fg-subtle">Title</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Category</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Body</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($macros as $macro)
                        <tr>
                            <td class="px-4 py-2 align-top font-medium">{{ $macro->title }}</td>
                            <td class="px-4 py-2 align-top">
                                @if ($macro->category_key)
                                    <flux:badge size="sm" color="zinc">{{ \App\Enums\TicketCategory::from($macro->category_key)->label() }}</flux:badge>
                                @else
                                    <span class="text-fg-subtle">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 align-top max-w-md">
                                <span class="line-clamp-2 text-fg-muted">{{ $macro->body }}</span>
                            </td>
                            <td class="px-4 py-2 align-top">
                                <div class="flex gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $macro->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="subtle" wire:click="delete({{ $macro->id }})" wire:confirm="Delete this canned response?">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-fg-muted">No canned responses yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
