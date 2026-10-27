<?php

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Staff')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $fName = '';

    public string $fEmail = '';

    public string $fPassword = '';

    public string $fCompanyId = '';

    public bool $fOnCall = false;

    public bool $fActive = true;

    /** @var list<string> */
    public array $fSkills = [];

    public function mount(): void
    {
        $this->guard();
    }

    /**
     * Technician routing is administrator-managed. Re-checked on every action
     * because Livewire update requests skip mount().
     */
    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function startCreate(): void
    {
        $this->guard();

        $this->reset(['editingId', 'fName', 'fEmail', 'fPassword', 'fCompanyId', 'fSkills']);
        $this->fOnCall = false;
        $this->fActive = true;
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        $this->guard();

        $tech = User::query()->with('skills')->findOrFail($id);

        $this->resetErrorBag();
        $this->editingId = $tech->id;
        $this->fName = $tech->name;
        $this->fEmail = $tech->email;
        $this->fPassword = '';
        $this->fCompanyId = (string) ($tech->company_id ?? '');
        $this->fOnCall = $tech->is_on_call;
        $this->fActive = $tech->is_active;
        $this->fSkills = $tech->skills->pluck('category_key')->all();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->reset(['showForm', 'editingId', 'fName', 'fEmail', 'fPassword', 'fCompanyId', 'fSkills']);
    }

    public function save(): void
    {
        $this->guard();

        $rules = [
            'fName' => ['required', 'string', 'max:255'],
            'fEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'fCompanyId' => ['required', 'integer', 'exists:companies,id'],
            'fSkills' => ['array'],
            'fSkills.*' => [Rule::in(array_column(TicketCategory::cases(), 'value'))],
        ];

        if ($this->editingId) {
            $rules['fPassword'] = ['nullable', 'string', 'min:8'];
        } else {
            $rules['fPassword'] = ['required', 'string', 'min:8'];
        }

        $this->validate($rules);

        $tech = $this->editingId ? User::find($this->editingId) : new User;
        $tech->fill([
            'name' => trim($this->fName),
            'email' => trim($this->fEmail),
            'role' => UserRole::Technician,
            'company_id' => (int) $this->fCompanyId,
            'is_active' => $this->fActive,
            'is_on_call' => $this->fOnCall,
        ]);

        if ($this->fPassword !== '') {
            $tech->password = $this->fPassword;
        }

        $tech->save();

        // Replace the skill set wholesale so removals stick.
        $tech->skills()->delete();
        foreach (array_unique($this->fSkills) as $key) {
            $tech->skills()->create(['category_key' => $key]);
        }

        $this->cancel();
        session()->flash('success', 'Technician saved.');
    }

    public function toggleOnCall(int $id): void
    {
        $this->guard();

        $tech = User::query()->where('role', UserRole::Technician->value)->findOrFail($id);
        $tech->update(['is_on_call' => ! $tech->is_on_call]);

        session()->flash('success', $tech->is_on_call ? 'Marked on-call.' : 'Marked off-call.');
    }

    public function with(): array
    {
        $technicians = User::query()
            ->with(['company:id,name,code', 'skills'])
            ->where('role', UserRole::Technician->value)
            ->orderBy('name')
            ->get();

        // Open workload per technician for the routing table (Resolved/Closed excluded).
        $open = Ticket::query()
            ->selectRaw('assigned_to, count(*) as aggregate')
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->groupBy('assigned_to')
            ->pluck('aggregate', 'assigned_to');

        return [
            'technicians' => $technicians,
            'open' => $open,
            'companies' => Company::query()->where('is_active', true)->orderBy('name')->get(),
            'categories' => TicketCategory::cases(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Staff" subtitle="Technicians, their home company, skills and on-call status — this drives ticket auto-assignment">
        <x-slot:actions>
            <flux:button wire:click="startCreate" variant="primary" icon="plus">New technician</flux:button>
        </x-slot:actions>
    </x-page.header>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
    @endif

    @if ($showForm)
        <div class="card p-4">
            <h2 class="text-sm font-semibold">{{ $editingId ? 'Edit technician' : 'New technician' }}</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input wire:model="fName" label="Name" placeholder="Full name" />
                <flux:input wire:model="fEmail" label="Email" type="email" placeholder="tech@company.com" />
                <flux:input wire:model="fPassword" label="Password" type="password"
                    :placeholder="$editingId ? 'Leave blank to keep current' : 'Minimum 8 characters'" autocomplete="new-password" />
                <flux:select wire:model="fCompanyId" label="Home company" placeholder="Select company">
                    @foreach ($companies as $company)
                        <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="mt-4">
                <span class="text-sm font-medium">Skills (categories this technician handles)</span>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach ($categories as $case)
                        <flux:checkbox wire:model="fSkills" value="{{ $case->value }}" label="{{ $case->label() }}" />
                    @endforeach
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-4">
                <flux:checkbox wire:model="fOnCall" label="On-call (preferred for auto-assign)" />
                <flux:checkbox wire:model="fActive" label="Active" />
            </div>

            <div class="mt-4 flex gap-2">
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
                        <th class="px-4 py-2 font-medium text-fg-subtle">Technician</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Home company</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Skills</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">On-call</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Open</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($technicians as $tech)
                        <tr>
                            <td class="px-4 py-2">
                                <span class="font-medium">{{ $tech->name }}</span>
                                <div class="text-xs text-fg-muted">{{ $tech->email }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $tech->company?->name ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @forelse ($tech->skills as $skill)
                                    <flux:badge size="sm" color="zinc" class="mb-1">{{ \App\Enums\TicketCategory::from($skill->category_key)->label() }}</flux:badge>
                                @empty
                                    <span class="text-fg-subtle">Generalist</span>
                                @endforelse
                            </td>
                            <td class="px-4 py-2"><flux:badge :color="$tech->is_on_call ? 'green' : 'zinc'" size="sm">{{ $tech->is_on_call ? 'On-call' : 'Off' }}</flux:badge></td>
                            <td class="px-4 py-2 tabular-nums">{{ (int) ($open->get($tech->id, 0)) }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$tech->is_active ? 'green' : 'zinc'" size="sm">{{ $tech->is_active ? 'Active' : 'Inactive' }}</flux:badge></td>
                            <td class="px-4 py-2">
                                <div class="flex gap-2">
                                    <flux:button size="xs" variant="subtle" wire:click="startEdit({{ $tech->id }})">Edit</flux:button>
                                    <flux:button size="xs" variant="subtle" wire:click="toggleOnCall({{ $tech->id }})">{{ $tech->is_on_call ? 'Set off' : 'Set on-call' }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-fg-muted">No technicians yet. Add one so tickets can auto-route to a company.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
