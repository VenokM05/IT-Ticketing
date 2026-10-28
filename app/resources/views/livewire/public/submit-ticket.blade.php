<?php

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\EmployeeToken;
use App\Models\KbArticle;
use App\Services\TicketService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
    // Company is chosen through the mandatory picker modal (null until picked).
    public ?int $companyId = null;
    public bool $companyPicker = false;
    public bool $manualCompany = false;
    // Filters the picker list when there are many companies to choose from.
    public string $companySearch = '';
    public string $department = '';
    public string $position = '';
    public string $contact = '';
    public string $subject = '';
    public string $description = '';
    public string $category = 'general';
    // Optional remote-assistance handle; only collected on the web forms.
    public string $anydeskId = '';

    // Honeypot: real users never fill this, bots do — such submissions are dropped.
    public string $website = '';

    public ?string $ticketNumber = null;

    // Self-service tracking link (Phase 10) + the category suggestions were keyed on.
    public ?string $trackUrl = null;

    public ?string $submittedCategory = null;

    public function mount(): void
    {
        // The company is mandatory: open the picker immediately so the first thing
        // a requester does is say which company they belong to. The modal has no
        // dismiss affordance, so it can only be left by making a choice.
        $this->companyPicker = $this->company === '';
    }

    public function openCompanyPicker(): void
    {
        $this->companySearch = '';
        $this->companyPicker = true;
    }

    public function chooseCompany(int $companyId): void
    {
        $company = Company::query()->active()->find($companyId);

        if ($company === null) {
            return;
        }

        $this->companyId = $company->id;
        $this->company = $company->name;
        $this->manualCompany = false;
        $this->companySearch = '';
        $this->companyPicker = false;
        $this->resetErrorBag('company');
    }

    public function chooseOther(): void
    {
        // Escape hatch for a company that is not enrolled yet — a name is still
        // required before the request can be filed.
        $this->companyId = null;
        $this->company = '';
        $this->manualCompany = true;
        $this->companySearch = '';
        $this->companyPicker = false;
    }

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
            $this->reset(['name', 'company', 'department', 'position', 'contact', 'subject', 'description', 'website', 'anydeskId']);

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
            'category' => ['required', Rule::in(Category::query()->active()->pluck('key')->all())],
            'anydeskId' => ['nullable', 'string', 'max:60'],
        ], [
            'name.required' => 'Please tell us your name.',
            'company.required' => 'Please tell us which company you are from.',
            'contact.required' => 'Provide a phone number or email so IT can reach you.',
            'subject.required' => 'A short subject is required.',
            'description.required' => 'Please describe the issue.',
        ]);

        // Prefer the company chosen in the picker; fall back to matching the typed
        // name against an active record by name or code (case-insensitive).
        if ($this->companyId !== null) {
            $match = Company::query()->active()->find($this->companyId);
        } else {
            $needle = mb_strtolower(trim($this->company));
            $match = Company::query()->active()->get()->first(
                fn (Company $c) => mb_strtolower($c->name) === $needle
                    || ($c->code !== null && mb_strtolower($c->code) === $needle),
            );
        }

        $ticket = app(TicketService::class)->createTicket([
            // No enrolled employee; company stamped from the match (null => Unassigned).
            'company_id' => $match?->id,
            'subject' => trim($this->subject),
            'description' => trim($this->description),
            'category' => $this->category,
            'priority' => 'medium',
            'status' => TicketStatus::New,
            'assigned_to' => null,
            'requester_name' => trim($this->name),
            'requester_contact' => trim($this->contact),
            'requester_company' => $match ? null : trim($this->company),
            'requester_department' => $this->department !== '' ? trim($this->department) : null,
            'requester_position' => $this->position !== '' ? trim($this->position) : null,
            'anydesk_id' => $this->anydeskId !== '' ? trim($this->anydeskId) : null,
        ]);

        $this->ticketNumber = $ticket->ticket_number;

        // Hand the requester a no-login tracking link and remember their chosen
        // category so the confirmation screen can show matching self-help articles.
        $this->trackUrl = route('portal.ticket', EmployeeToken::issue($ticket)->token);
        $this->submittedCategory = $this->category;

        $this->reset([
            'name', 'company', 'department', 'position', 'contact',
            'subject', 'description', 'website', 'anydeskId', 'companyId',
        ]);
        $this->manualCompany = false;
        $this->category = 'general';
    }

    public function again(): void
    {
        $this->reset(['ticketNumber', 'trackUrl', 'submittedCategory', 'companySearch']);
        $this->companyPicker = true;
    }

    public function with(): array
    {
        return [
            // Suggestions only — a free-text entry that matches nothing is still accepted.
            'companyNames' => Company::query()->active()->orderBy('name')->pluck('name'),
            // Full records for the picker modal (name + logo + code), narrowed by
            // the search box so a long list stays easy to scan.
            'companies' => Company::query()->active()
                ->when($this->companySearch, fn ($q) => $q->where(fn ($i) => $i
                    ->where('name', 'like', "%{$this->companySearch}%")
                    ->orWhere('code', 'like', "%{$this->companySearch}%")))
                ->orderBy('name')->get(),
            'selectedCompany' => $this->companyId !== null ? Company::query()->find($this->companyId) : null,
            'categories' => Category::query()->active()->ordered()->get(),
            // Knowledge-base self-help: react to the live selection, or to the
            // category the submitted request was filed under on the receipt.
            'suggestions' => KbArticle::suggestionsFor(
                $this->ticketNumber ? $this->submittedCategory : $this->category,
            ),
        ];
    }
}; ?>

<div class="w-full">
    {{-- Mandatory company picker: a full-screen modal with no dismiss affordance, so
         the requester can only move on by choosing their company (or opting to type
         an unlisted one). Each option shows the company's uploaded logo when it has
         one; names wrap instead of truncating so they stay fully visible. --}}
    @if ($companyPicker)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-canvas">
            <div class="flex min-h-full flex-col">
                <div class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
                    <header class="text-center">
                        <span class="inline-flex items-center rounded-full border border-accent/25 bg-accent/10 px-3 py-1 text-xs font-medium text-accent">Required first step</span>
                        <h2 class="mt-4 text-2xl font-semibold tracking-tight text-fg sm:text-3xl">Which company are you from?</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">Choose your company so IT can route and prioritise your request.</p>
                    </header>

                    <div class="mt-6">
                        <flux:input wire:model.live.debounce.300ms="companySearch" icon="magnifying-glass" placeholder="Search companies" size="sm" />
                    </div>

                    @if ($companies->isEmpty())
                        <p class="mt-10 text-center text-sm text-fg-muted">{{ $companySearch !== '' ? 'No company matches “'.$companySearch.'”.' : 'No companies have been set up yet.' }}</p>
                    @else
                        <div class="mt-6 grid gap-3 sm:grid-cols-2">
                            @foreach ($companies as $companyOption)
                                <button type="button" wire:click="chooseCompany({{ $companyOption->id }})" class="flex w-full items-center gap-3 rounded-xl border border-border bg-surface p-4 text-left shadow-xs transition hover:border-accent hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                                    <span class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-surface-muted text-sm font-semibold text-fg-muted">
                                        @if ($companyOption->hasLogo())
                                            <img src="{{ route('companies.logo', $companyOption) }}" alt="" class="size-full object-contain" />
                                        @else
                                            {{ Str::upper(Str::substr($companyOption->name, 0, 1)) }}
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold leading-snug text-fg [overflow-wrap:anywhere]">{{ $companyOption->name }}</span>
                                        @if ($companyOption->code)
                                            <span class="mt-0.5 block text-xs text-fg-subtle">{{ $companyOption->code }}</span>
                                        @endif
                                    </span>
                                    <svg class="size-4 shrink-0 text-fg-subtle" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 3.77a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 9.5 7.22 4.83a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-8 text-center">
                        <button type="button" wire:click="chooseOther" class="text-sm text-fg-muted underline underline-offset-4 transition hover:text-fg">Can't find your company? Enter it manually</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($ticketNumber)
        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-900 dark:bg-green-950/40">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            </div>
            <h1 class="text-lg font-semibold text-green-800 dark:text-green-200">Request received</h1>
            <p class="mt-1 text-sm text-green-700 dark:text-green-300">Your ticket number is</p>
            <p class="mt-1 text-2xl font-bold tracking-wide text-green-800 dark:text-green-100">{{ $ticketNumber }}</p>
            <p class="mt-3 text-sm text-green-700/80 dark:text-green-300/80">Please save this number — it is how IT will reference your request. Quote it if you follow up.</p>
            @if ($trackUrl)
                <a href="{{ $trackUrl }}" class="mt-3 inline-block text-sm font-semibold text-green-800 underline dark:text-green-100">Track this request and add updates anytime →</a>
                <p class="mt-1 text-xs text-green-700/70 dark:text-green-300/70">Keep this link — it is the only way to reopen this page without an account.</p>
            @endif
            @if ($suggestions->isNotEmpty())
                <div class="mt-4 text-left">
                    <p class="text-xs font-semibold uppercase tracking-wide text-green-700/80 dark:text-green-300/80">Meanwhile, try this</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($suggestions as $article)
                            <li class="text-sm text-green-800 dark:text-green-200">• {{ $article->title }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
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
                <span class="mb-1 block text-sm font-medium">Company</span>

                @if ($selectedCompany)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-surface px-3 py-2">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-muted text-xs font-semibold text-fg-muted">
                                @if ($selectedCompany->hasLogo())
                                    <img src="{{ route('companies.logo', $selectedCompany) }}" alt="" class="size-full object-contain" />
                                @else
                                    {{ Str::upper(Str::substr($selectedCompany->name, 0, 1)) }}
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium leading-snug text-fg [overflow-wrap:anywhere]">{{ $selectedCompany->name }}</span>
                                @if ($selectedCompany->code)
                                    <span class="block text-xs text-fg-subtle">{{ $selectedCompany->code }}</span>
                                @endif
                            </span>
                        </span>
                        <flux:button type="button" wire:click="openCompanyPicker" variant="subtle" size="sm" class="shrink-0">Change</flux:button>
                    </div>
                @elseif ($manualCompany)
                    <flux:input wire:model="company" placeholder="Type your company name" list="known-companies" />
                    <datalist id="known-companies">
                        @foreach ($companyNames as $known)
                            <option value="{{ $known }}"></option>
                        @endforeach
                    </datalist>
                    <button type="button" wire:click="openCompanyPicker" class="mt-1.5 inline-flex items-center gap-1 text-sm text-fg-muted underline underline-offset-4 transition hover:text-fg">
                        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14ZM1.5 10a8.5 8.5 0 1 1 17 0 8.5 8.5 0 0 1-17 0Z" clip-rule="evenodd" /><path d="M10.75 6.5a.75.75 0 0 0-1.5 0v3.69l-1.72-1.72a.75.75 0 1 0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l3-3a.75.75 0 0 0-1.06-1.06l-1.78 1.78V6.5Z" /></svg>
                        Choose from the list instead
                    </button>
                @else
                    <button type="button" wire:click="openCompanyPicker" class="flex w-full items-center justify-between gap-3 rounded-lg border border-border bg-surface px-3 py-2 text-left text-sm text-fg-muted transition hover:border-accent hover:text-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-accent">
                        <span>Choose your company</span>
                        <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>
                @endif

                @error('company') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="department" label="Department (optional)" placeholder="e.g. Finance" />
                <flux:input wire:model="position" label="Position (optional)" placeholder="e.g. Accountant" />
            </div>

            <flux:input wire:model="contact" label="Phone or email" placeholder="How IT can reach you" />

            <flux:input wire:model="subject" label="Subject" placeholder="Short summary of the issue" />

            {{-- Classification first helps IT route the request; AnyDesk is optional remote help. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="category" label="Category of problem">
                    @foreach ($categories as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="anydeskId" label="AnyDesk ID (optional)" placeholder="e.g. 123 456 789 — needed only for remote assistance" />
            </div>

            {{-- Knowledge-base self-help that matches the chosen category (Phase 10). --}}
            @if ($suggestions->isNotEmpty())
                <div class="rounded-lg border border-border bg-surface-muted/40 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-fg-subtle">Fixes for “{{ $categories->firstWhere('key', $category)?->label() ?? 'this' }}”</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($suggestions as $article)
                            <li class="text-sm">
                                <span class="font-medium">{{ $article->title }}</span>
                                <span class="block text-xs text-fg-muted">{{ \Illuminate\Support\Str::limit($article->body, 140) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <flux:textarea wire:model="description" label="Description" rows="5" placeholder="What is happening? When did it start? What is affected?" />

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
