<?php

use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * DB-managed request categories (PRD Phase 9, Rec #1). Administrators add, rename,
 * reorder and enable/disable the classification options offered at intake and used
 * for technician skill routing — no code change or deploy required.
 */
new #[Layout('components.layouts.app')] #[Title('Categories')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $fKey = '';

    public string $fLabel = '';

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

        $this->reset(['editingId', 'fKey', 'fLabel']);
        $this->fSort = (string) (Category::query()->max('sort') + 1);
        $this->fActive = true;
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $category = Category::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $category->id;
        $this->fKey = $category->key;
        $this->fLabel = $category->label;
        $this->fSort = (string) $category->sort;
        $this->fActive = $category->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fKey', 'fLabel', 'fSort']);
        $this->fActive = true;
    }

    public function save(): void
    {
        $this->guard();

        $this->validate([
            'fKey' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('categories', 'key')->ignore($this->editingId)],
            'fLabel' => ['required', 'string', 'max:120'],
            'fSort' => ['required', 'integer', 'min:0'],
        ]);

        $category = $this->editingId ? Category::find($this->editingId) : new Category;
        $category->fill([
            'key' => strtolower(trim($this->fKey)),
            'label' => trim($this->fLabel),
            'sort' => (int) $this->fSort,
            'is_active' => $this->fActive,
        ]);
        $category->save();

        app(AuditLogger::class)->record(
            $this->editingId ? 'category.updated' : 'category.created',
            $category,
            ['key' => $category->key, 'label' => $category->label],
        );

        $this->cancel();
        session()->flash('success', 'Category saved.');
    }

    public function toggle(int $id): void
    {
        $this->guard();

        $category = Category::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);

        app(AuditLogger::class)->record('category.toggled', $category, ['is_active' => $category->is_active]);

        session()->flash('success', $category->is_active ? 'Category enabled.' : 'Category disabled.');
    }

    public function delete(int $id): void
    {
        $this->guard();

        $category = Category::findOrFail($id);
        $key = $category->key;
        $category->delete();

        app(AuditLogger::class)->record('category.deleted', null, ['key' => $key]);

        session()->flash('success', 'Category deleted. Existing tickets fall back to uncategorised.');
    }

    public function with(): array
    {
        return [
            'categories' => Category::query()->ordered()->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Categories" subtitle="The problem categories offered at intake and used for skill routing.">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New category</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit category' : 'New category' }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model="fKey" label="Key" placeholder="e.g. telephony" :disabled="$editingId !== null" />
                <flux:input wire:model="fLabel" label="Label" placeholder="Display name" />
                <flux:input wire:model="fSort" type="number" label="Order" />
                <div class="flex items-end pb-1">
                    <flux:checkbox wire:model="fActive" label="Active" />
                </div>
            </div>
            @error('fKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('fLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
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
                        <th class="px-4 py-2 font-medium text-fg-subtle">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="px-4 py-2 tabular-nums text-fg-muted">{{ $category->sort }}</td>
                            <td class="px-4 py-2 font-medium">{{ $category->label() }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-fg-muted">{{ $category->key }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$category->is_active ? 'green' : 'zinc'" size="sm">{{ $category->is_active ? 'Active' : 'Disabled' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $category->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggle({{ $category->id }})">{{ $category->is_active ? 'Disable' : 'Enable' }}</flux:button>
                                    <flux:button size="xs" variant="ghost" class="text-red-600" wire:click="delete({{ $category->id }})" wire:confirm="Delete this category? Tickets keep a null category.">Delete</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-fg-muted">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
