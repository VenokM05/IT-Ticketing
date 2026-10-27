<?php

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Companies')] class extends Component {
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;
    public string $fName = '';
    public string $fCode = '';
    public string $fEmail = '';
    public string $fLocation = '';
    public bool $fActive = true;

    public string $search = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function updated($property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    /**
     * Companies are administrator-managed. Enforced on every entry point because
     * Livewire update requests do not re-run mount().
     */
    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function startCreate(): void
    {
        $this->guard();

        $this->reset(['editingId', 'fName', 'fCode', 'fEmail', 'fLocation']);
        $this->fActive = true;
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $company = Company::findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $company->id;
        $this->fName = $company->name;
        $this->fCode = (string) $company->code;
        $this->fEmail = (string) $company->email;
        $this->fLocation = (string) $company->location;
        $this->fActive = $company->is_active;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fName', 'fCode', 'fEmail', 'fLocation']);
    }

    public function save(): void
    {
        // mount() is skipped on Livewire update requests, so re-check the boundary.
        $this->guard();

        $this->validate([
            'fName' => ['required', 'string', 'max:255'],
            'fCode' => ['nullable', 'string', 'max:16', Rule::unique('companies', 'code')->ignore($this->editingId)],
        ]);

        $company = $this->editingId ? Company::find($this->editingId) : new Company;
        $company->fill([
            'name' => trim($this->fName),
            'code' => $this->fCode !== '' ? strtoupper(trim($this->fCode)) : null,
            'email' => $this->fEmail ?: null,
            'location' => $this->fLocation ?: null,
            'is_active' => $this->fActive,
        ]);
        $company->save();

        $this->cancel();
        session()->flash('success', 'Company saved.');
    }

    public function toggle(int $id): void
    {
        $this->guard();

        $company = Company::findOrFail($id);

        // Inactive companies stay referenced by historical tickets; only new
        // enrollments/submissions stop offering them.
        $company->update(['is_active' => ! $company->is_active]);

        session()->flash('success', $company->is_active ? 'Company activated.' : 'Company deactivated.');
    }

    public function with(): array
    {
        return [
            'companies' => Company::query()
                ->withCount(['employees', 'tickets'])
                ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $i) => $i
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")))
                ->orderBy('name')
                ->paginate(15),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Companies" subtitle="Which organization a ticket request came from (a tag, not an access boundary)">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New company</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    {{-- Create / edit form --}}
    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit company' : 'New company' }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model="fName" label="Name" placeholder="Legal or trading name" />
                <flux:input wire:model="fCode" label="Code" placeholder="e.g. ACME" />
                <flux:input wire:model="fEmail" label="Email" type="email" placeholder="it@company.com" />
                <flux:input wire:model="fLocation" label="Location" placeholder="e.g. Cebu City" />
            </div>
            <div class="mt-3">
                <flux:checkbox wire:model="fActive" label="Active" />
            </div>
            <div class="mt-3 flex gap-2">
                <flux:button wire:click="save" variant="primary">Save</flux:button>
                <flux:button wire:click="cancel" variant="subtle">Cancel</flux:button>
            </div>
        </div>
    @endif

    {{-- Search --}}
    <div class="card p-4">
        <flux:input wire:model.live.debounce.400ms="search" placeholder="Search name / code" icon="magnifying-glass" />
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium text-fg-muted">Company</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Employees</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Tickets</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($companies as $company)
                        <tr>
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ $company->name }}</span>
                                @if ($company->code)
                                    <flux:badge size="sm" color="zinc" class="ml-1">{{ $company->code }}</flux:badge>
                                @endif
                                <div class="text-xs text-fg-muted">{{ $company->email }}{{ $company->location ? ' · '.$company->location : '' }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $company->employees_count }}</td>
                            <td class="px-4 py-2">{{ $company->tickets_count }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$company->is_active ? 'green' : 'zinc'" size="sm">{{ $company->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $company->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="subtle" wire:click="toggle({{ $company->id }})">{{ $company->is_active ? 'Deactivate' : 'Activate' }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-fg-muted">No companies yet. Add one so ticket requests can be attributed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($companies->hasPages())
            <div class="border-t border-border px-4 py-3">{{ $companies->links() }}</div>
        @endif
    </div>
</div>
