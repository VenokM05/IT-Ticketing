<?php

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Manual ticket submission on behalf of an employee (PRD §1.1 admin dashboard
 * management). WhatsApp remains the primary intake channel; this covers walk-ins,
 * phone calls and messages that arrive outside the bot. Administrator-only, enforced
 * by TicketPolicy::create.
 */
new #[Layout('components.layouts.app')] #[Title('Submit Ticket')] class extends Component {
    public string $employeeId = '';
    public string $subject = '';
    public string $description = '';
    public string $category = 'general';
    public string $priority = 'medium';
    public string $technicianId = '';
    public string $anydeskId = '';

    public function mount(): void
    {
        $this->authorize('create', Ticket::class);
    }

    public function submit(): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $this->validate(
            [
                'employeeId' => ['required', 'integer', 'exists:employees,id'],
                'subject' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:5000'],
                'category' => ['required', 'string', 'in:'.implode(',', Category::query()->active()->pluck('key')->all())],
                'priority' => ['required', 'string', 'in:'.implode(',', Priority::query()->active()->pluck('key')->all())],
                'technicianId' => ['nullable', 'integer', 'exists:users,id'],
                'anydeskId' => ['nullable', 'string', 'max:60'],
            ],
            [
                'employeeId.required' => 'Choose the employee this ticket is for.',
                'subject.required' => 'A short subject is required.',
                'description.required' => 'Describe the issue.',
            ],
        );

        $ticket = app(TicketService::class)->createTicket([
            'employee_id' => (int) $this->employeeId,
            'subject' => $this->subject,
            'description' => $this->description,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->technicianId !== '' ? TicketStatus::Assigned : TicketStatus::New,
            'assigned_to' => $this->technicianId !== '' ? (int) $this->technicianId : null,
            'anydesk_id' => $this->anydeskId !== '' ? trim($this->anydeskId) : null,
        ], auth()->user());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_number} created.");
    }

    public function with(): array
    {
        $employees = Employee::query()
            ->where('is_active', true)
            ->with(['company:id,name', 'department:id,name'])
            ->orderBy('name')
            ->get();

        return [
            'employees' => $employees,
            // Lets the form preview the company that will be stamped on the ticket.
            'requester' => $this->employeeId === '' ? null : $employees->firstWhere('id', (int) $this->employeeId),
            'categories' => Category::query()->active()->ordered()->get(),
            'priorities' => Priority::query()->active()->ordered()->get(),
            'technicians' => User::query()->where('role', UserRole::Technician->value)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div class="mx-auto w-full max-w-2xl flex flex-col gap-5">
    <x-page.header title="Submit a Ticket" subtitle="Log an IT issue on behalf of an employee (walk-in, phone or email)." />

    <form wire:submit="submit" class="card flex flex-col gap-4 p-5">
        <flux:select wire:model.live="employeeId" label="Employee" placeholder="Select employee…">
            <flux:select.option value="">Select employee…</flux:select.option>
            @foreach ($employees as $employee)
                <flux:select.option value="{{ $employee->id }}">
                    {{ $employee->name }}{{ $employee->company ? ' — '.$employee->company->name : '' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        @if ($requester)
            {{-- The company is inherited from the employee's enrollment, not chosen here. --}}
            <div class="-mt-2 rounded-lg border border-border bg-surface-muted p-3 text-xs text-fg-muted">
                <span class="font-semibold">Company:</span>
                {{ $requester->company?->name ?? 'Not set — assign a company in the Employee Directory' }}
                <span class="mx-1">·</span>
                <span class="font-semibold">Department:</span> {{ $requester->department?->name ?? '—' }}
                @if ($requester->position)
                    <span class="mx-1">·</span>
                    <span class="font-semibold">Position:</span> {{ $requester->position }}
                @endif
            </div>
        @endif

        <flux:input wire:model="subject" label="Subject" placeholder="Short summary of the issue" />

        <flux:input wire:model="anydeskId" label="AnyDesk ID (optional)" placeholder="Remote-assistance handle, if the user provided one" />

        <flux:textarea wire:model="description" label="Description" rows="5" placeholder="What is happening? Steps to reproduce, impact, etc." />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <flux:select wire:model="category" label="Category">
                @foreach ($categories as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="priority" label="Priority">
                @foreach ($priorities as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="technicianId" label="Assign to (optional)" placeholder="Unassigned">
                <flux:select.option value="">Unassigned</flux:select.option>
                @foreach ($technicians as $tech)
                    <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex items-center justify-end gap-2 pt-2">
            <flux:button :href="route('tickets')" variant="subtle" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Create Ticket</flux:button>
        </div>
    </form>
</div>
