<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Services\TicketService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Public, no-login ticket submission (process.md item 2: the "submit-ticket" web form).
 *
 * Anyone reaches this without an account. The requester is NOT enrolled, so the ticket
 * carries a self-declared snapshot (name / company / department / position / contact)
 * and no employee_id. The company stays a tag: a typed name that matches an active
 * company is stamped; anything else is filed as "Unassigned" for admins to triage.
 */
new #[Layout('components.layouts.auth')] #[Title('Submit a Ticket')] class extends Component {
    public string $name = '';
    public string $company = '';
    public string $department = '';
    public string $position = '';
    public string $contact = '';
    public string $subject = '';
    public string $description = '';
    public string $category = TicketCategory::General->value;

    // Honeypot: real users never fill this, bots do — such submissions are dropped.
    public string $website = '';

    public ?string $ticketNumber = null;

    public function submit(): void
    {
        // Cheap spam control on an open endpoint: a handful of requests per minute per IP.
        $key = 'public-ticket:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('description', 'Too many requests from your network. Please try again in a minute.');

            return;
        }

        RateLimiter::hit($key, 60);

        // Silently accept honeypot hits without creating anything.
        if ($this->website !== '') {
            $this->reset(['name', 'company', 'department', 'position', 'contact', 'subject', 'description', 'website']);

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_column(TicketCategory::cases(), 'value'))],
        ], [
            'name.required' => 'Please tell us your name.',
            'company.required' => 'Please tell us which company you are from.',
            'contact.required' => 'Provide a phone number or email so IT can reach you.',
            'subject.required' => 'A short subject is required.',
            'description.required' => 'Please describe the issue.',
        ]);

        // Match the typed company to an active record by name or code (case-insensitive).
        $needle = mb_strtolower(trim($this->company));
        $match = Company::query()->active()->get()->first(
            fn (Company $c) => mb_strtolower($c->name) === $needle
                || ($c->code !== null && mb_strtolower($c->code) === $needle),
        );

        $ticket = app(TicketService::class)->createTicket([
            // No enrolled employee; company stamped from the match (null => Unassigned).
            'company_id' => $match?->id,
            'subject' => trim($this->subject),
            'description' => trim($this->description),
            'category' => TicketCategory::from($this->category),
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::New,
            'assigned_to' => null,
            'requester_name' => trim($this->name),
            'requester_contact' => trim($this->contact),
            'requester_company' => $match ? null : trim($this->company),
            'requester_department' => $this->department !== '' ? trim($this->department) : null,
            'requester_position' => $this->position !== '' ? trim($this->position) : null,
        ]);

        $this->ticketNumber = $ticket->ticket_number;

        $this->reset([
            'name', 'company', 'department', 'position', 'contact',
            'subject', 'description', 'website',
        ]);
        $this->category = TicketCategory::General->value;
    }

    public function again(): void
    {
        $this->reset('ticketNumber');
    }

    public function with(): array
    {
        return [
            // Suggestions only — a free-text entry that matches nothing is still accepted.
            'companyNames' => Company::query()->active()->orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div class="w-full">
    @if ($ticketNumber)
        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-900 dark:bg-green-950/40">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            </div>
            <h1 class="text-lg font-semibold text-green-800 dark:text-green-200">Request received</h1>
            <p class="mt-1 text-sm text-green-700 dark:text-green-300">Your ticket number is</p>
            <p class="mt-1 text-2xl font-bold tracking-wide text-green-800 dark:text-green-100">{{ $ticketNumber }}</p>
            <p class="mt-3 text-sm text-green-700/80 dark:text-green-300/80">Please save this number — it is how IT will reference your request. Quote it if you follow up.</p>
            <flux:button wire:click="again" variant="subtle" class="mt-4">Submit another request</flux:button>
        </div>
    @else
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-semibold tracking-tight">Submit an IT request</h1>
            <p class="mt-1 text-sm text-fg-muted">No account needed. Fill this in and IT will get back to you.</p>
        </div>

        <form wire:submit="submit" class="flex flex-col gap-4">
            <flux:input wire:model="name" label="Your name" placeholder="Full name" />

            <div>
                <flux:input wire:model="company" label="Company" placeholder="Company name" list="known-companies" />
                <datalist id="known-companies">
                    @foreach ($companyNames as $known)
                        <option value="{{ $known }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="department" label="Department (optional)" placeholder="e.g. Finance" />
                <flux:input wire:model="position" label="Position (optional)" placeholder="e.g. Accountant" />
            </div>

            <flux:input wire:model="contact" label="Phone or email" placeholder="How IT can reach you" />

            <flux:input wire:model="subject" label="Subject" placeholder="Short summary of the issue" />

            <flux:textarea wire:model="description" label="Description" rows="5" placeholder="What is happening? When did it start? What is affected?" />

            <flux:select wire:model="category" label="Category">
                @foreach (TicketCategory::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Honeypot: visually hidden, only bots fill it. --}}
            <div class="hidden" aria-hidden="true">
                <flux:input wire:model="website" label="Website" tabindex="-1" autocomplete="off" />
            </div>

            <div class="flex items-center justify-end pt-1">
                <flux:button type="submit" variant="primary" class="w-full justify-center" wire:loading.attr="disabled">Submit request</flux:button>
            </div>
        </form>
    @endif
</div>
