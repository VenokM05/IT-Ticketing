<?php

use App\Enums\TicketStatus;
use App\Mail\TicketReceiptMail;
use App\Models\Category;
use App\Models\Company;
use App\Models\EmployeeToken;
use App\Models\KbArticle;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\TicketService;
use App\Support\AttachmentPolicy;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Public, no-login ticket submission (process.md item 2: the "submit-ticket" web form).
 *
 * Anyone reaches this without an account. The requester is NOT enrolled, so the ticket
 * carries a self-declared snapshot (name / company / department / position / contact)
 * and no employee_id. The company is picked from a forced first step: a typed name that
 * matches an active company is stamped; an unlisted one is filed as "Unassigned" for
 * admins to triage. On success the requester keeps a ticket number, a tokenized tracking
 * link (mailed to them when an address was given) and can recover that link later.
 */
new #[Layout('components.layouts.auth')] #[Title('Submit a Ticket')] class extends Component {
    use WithFileUploads;

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
    // Optional email — the receipt (number + tracking link) is mailed to it.
    public string $email = '';
    public string $subject = '';
    public string $description = '';
    public string $category = 'general';
    // Self-declared blast radius, mapped onto a DB priority (see impactOptions()).
    public string $impact = 'individual';
    // Optional remote-assistance handle; only collected on the web forms.
    public string $anydeskId = '';
    // Optional screenshot, stored on the private disk like any other attachment.
    public $screenshot = null;

    // Honeypot: real users never fill this, bots do — such submissions are dropped.
    public string $website = '';
    // Time-on-page floor: stamped at mount, a post arriving sooner is a bot.
    public int $openedAt = 0;

    public ?string $ticketNumber = null;

    // Self-service tracking link (Phase 10) + the category suggestions were keyed on.
    public ?string $trackUrl = null;

    public ?string $submittedCategory = null;

    // The address the receipt actually went to, for the confirmation screen.
    public ?string $mailedTo = null;

    // Soft duplicate warning: the number of the open request that looks the same.
    public ?string $duplicateOf = null;

    public bool $duplicateAck = false;

    // Receipt recovery — "already submitted?" lookup by number + contact.
    public bool $lookupMode = false;

    public string $lookupNumber = '';

    public string $lookupContact = '';

    public function mount(): void
    {
        // ?company=CODE (or the exact company name) answers the first step up front, so
        // a company can share one intake link with its own staff.
        $preset = $this->matchCompany((string) request()->query('company'));

        if ($preset !== null) {
            $this->companyId = $preset->id;
            $this->company = $preset->name;
        }

        // The company is mandatory: open the picker immediately so the first thing
        // a requester does is say which company they belong to. The modal has no
        // dismiss affordance, so it can only be left by making a choice.
        $this->companyPicker = $this->company === '';

        $this->openedAt = time();
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

        // Two bot gates, both dropped silently so a scraper gets no signal to retry
        // around: the honeypot field a human never fills, and a form completed faster
        // than the configured reading-and-typing floor.
        $floor = (int) config('tickets.intake.min_fill_seconds', 0);

        if ($this->website !== '' || ($floor > 0 && $this->openedAt > 0 && time() - $this->openedAt < $floor)) {
            $this->reset(['name', 'company', 'department', 'position', 'contact', 'email', 'subject', 'description', 'website', 'anydeskId']);

            return;
        }

        $contact = trim($this->contact);
        $email = trim($this->email);

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'contact' => ['required', 'string', 'max:160'],
            // Judged as an address only once something was actually typed in it.
            'email' => $email === '' ? ['nullable', 'max:255'] : ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(Category::query()->active()->pluck('key')->all())],
            'impact' => ['required', Rule::in(array_keys($this->impactOptions()))],
            'anydeskId' => ['nullable', 'string', 'max:60'],
            'screenshot' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.(int) config('tickets.attachments.max_size_kb', 5120)],
        ], [
            'name.required' => 'Please tell us your name.',
            'company.required' => 'Please tell us which company you are from.',
            'contact.required' => 'Provide a phone number or email so IT can reach you.',
            'email.email' => 'That email address does not look valid.',
            'subject.required' => 'A short subject is required.',
            'description.required' => 'Please describe the issue.',
            'screenshot.image' => 'The attachment must be an image.',
            'impact.required' => 'Choose how much this is affecting you.',
        ]);

        // Prefer the company chosen in the picker; fall back to matching the typed
        // name against an active record by name or code (case-insensitive).
        $match = $this->companyId !== null
            ? Company::query()->active()->find($this->companyId)
            : $this->matchCompany($this->company);

        // Soft duplicate check: rather than quietly filing a second copy of a request
        // that is still open, point at the existing one and let the requester confirm
        // that this is genuinely a new problem.
        if (! $this->duplicateAck) {
            $duplicate = $this->findRecentDuplicate(trim($this->subject), $contact, $email, $match);

            if ($duplicate !== null) {
                $this->duplicateOf = $duplicate->ticket_number;

                return;
            }
        }

        $ticket = app(TicketService::class)->createTicket([
            // No enrolled employee; company stamped from the match (null => Unassigned).
            'company_id' => $match?->id,
            'subject' => trim($this->subject),
            'description' => trim($this->description),
            'category' => $this->category,
            // Stated impact replaces the old flat "medium" so real outages escalate
            // and minor annoyances queue behind them; admins can reclassify either.
            'priority' => $this->impactOptions()[$this->impact]['priority'] ?? 'medium',
            'status' => TicketStatus::New,
            'assigned_to' => null,
            'requester_name' => trim($this->name),
            'requester_contact' => $contact,
            'requester_email' => $email !== '' ? $email : null,
            'requester_company' => $match ? null : trim($this->company),
            'requester_department' => $this->department !== '' ? trim($this->department) : null,
            'requester_position' => $this->position !== '' ? trim($this->position) : null,
            'anydesk_id' => $this->anydeskId !== '' ? trim($this->anydeskId) : null,
        ]);

        $this->storeScreenshot($ticket);

        $this->ticketNumber = $ticket->ticket_number;

        // Hand the requester a no-login tracking link and remember their chosen
        // category so the confirmation screen can show matching self-help articles.
        $this->trackUrl = route('portal.ticket', EmployeeToken::issue($ticket)->token);
        $this->submittedCategory = $this->category;

        // Mail the receipt: a screen that gets closed or scrolled past should not be
        // the only place the number and link exist. A mail outage must never lose the
        // ticket, so the send is best-effort and the on-screen receipt still stands.
        $this->mailedTo = null;

        if ($email !== '') {
            try {
                Mail::to($email)->send(new TicketReceiptMail($ticket, $this->trackUrl));
                $this->mailedTo = $email;
            } catch (\Throwable) {
                // Intentionally silent — the ticket is filed and the link is on screen.
            }
        }

        $this->reset([
            'name', 'company', 'department', 'position', 'contact',
            'subject', 'description', 'website', 'anydeskId', 'companyId',
            'email', 'screenshot', 'duplicateOf',
        ]);
        $this->manualCompany = false;
        $this->duplicateAck = false;
        $this->category = 'general';
        $this->impact = 'individual';

        // Re-arm the fill-time floor for the next request from this same page view.
        $this->openedAt = time();
    }

    public function again(): void
    {
        $this->reset(['ticketNumber', 'trackUrl', 'submittedCategory', 'companySearch', 'mailedTo', 'duplicateOf']);
        $this->lookupMode = false;
        $this->companyPicker = true;
    }

    /**
     * File the same request anyway, after the duplicate warning was shown.
     */
    public function submitAnyway(): void
    {
        $this->duplicateAck = true;
        $this->duplicateOf = null;

        $this->submit();
    }

    public function dismissDuplicateNotice(): void
    {
        $this->duplicateOf = null;
    }

    public function startLookup(): void
    {
        $this->resetErrorBag();
        $this->lookupMode = true;
        $this->companyPicker = false;
    }

    public function closeLookup(): void
    {
        $this->lookupMode = false;

        // Coming back from a deep-link-free recovery attempt, the mandatory first
        // step still stands — unless a company is already chosen.
        $this->companyPicker = $this->company === '' && $this->ticketNumber === null;
    }

    /**
     * Recover the tracking link for an existing request from its number plus the
     * contact detail left on it. Both must match, and attempts are rate-limited per
     * network, so this stays a receipt helper rather than a way to read other
     * people's tickets.
     */
    public function findTicket(): void
    {
        $key = 'public-ticket-recover:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('lookupContact', 'Too many lookups from your network. Please try again in a minute.');

            return;
        }

        RateLimiter::hit($key, 60);

        $validated = $this->validate([
            'lookupNumber' => ['required', 'string', 'max:60'],
            'lookupContact' => ['required', 'string', 'max:160'],
        ], [
            'lookupNumber.required' => 'Enter the ticket number from your receipt.',
            'lookupContact.required' => 'Enter the phone number or email you left on the request.',
        ]);

        $contact = trim($validated['lookupContact']);

        $ticket = Ticket::query()
            ->whereRaw('upper(ticket_number) = ?', [Str::upper(trim($validated['lookupNumber']))])
            ->where(fn ($query) => $query
                ->where('requester_contact', $contact)
                ->orWhere('requester_email', $contact))
            ->latest('id')
            ->first();

        if ($ticket === null) {
            $this->addError('lookupNumber', 'No request matches that number and contact detail. Check both and try again.');

            return;
        }

        // Reuse a link that is still live; otherwise mint a fresh one for the same ticket.
        $token = EmployeeToken::query()
            ->where('ticket_id', $ticket->id)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first() ?? EmployeeToken::issue($ticket);

        $this->lookupMode = false;
        $this->reset(['lookupNumber', 'lookupContact']);

        $this->redirect(route('portal.ticket', $token->token));
    }

    /**
     * Active company whose name or code matches a typed/linked tag, if any.
     */
    private function matchCompany(string $needle): ?Company
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return null;
        }

        return Company::query()->active()->get()->first(
            fn (Company $company) => mb_strtolower($company->name) === $needle
                || ($company->code !== null && mb_strtolower($company->code) === $needle),
        );
    }

    /**
     * The impact a requester can state, each mapped to the priority it files under.
     * The wording is about blast radius rather than urgency jargon; "blocking" carries
     * the old flat default so nothing is silently de-prioritised, while a stated
     * outage is allowed to escalate past the queue.
     *
     * @return array<string, array{label: string, hint: string, priority: string}>
     */
    private function impactOptions(): array
    {
        return [
            'minor' => ['label' => 'Minor', 'hint' => 'I can work around it for now', 'priority' => 'low'],
            'individual' => ['label' => 'Blocking', 'hint' => 'It only affects me', 'priority' => 'medium'],
            'team' => ['label' => 'Urgent', 'hint' => 'My team or a shared resource', 'priority' => 'high'],
            'outage' => ['label' => 'Critical', 'hint' => 'Everyone is blocked, or a system is down', 'priority' => 'critical'],
        ];
    }

    /**
     * Most recent open request from the same requester with the same subject, inside
     * the configured window. Null when the window is disabled or nothing matches.
     */
    private function findRecentDuplicate(string $subject, string $contact, string $email, ?Company $company): ?Ticket
    {
        $hours = (int) config('tickets.intake.duplicate_window_hours', 24);

        if ($hours <= 0 || $subject === '') {
            return null;
        }

        return Ticket::query()
            ->open()
            ->where('created_at', '>=', now()->subHours($hours))
            ->when($company, fn ($query) => $query->where('company_id', $company->id))
            ->where(fn ($query) => $query
                ->when($contact !== '', fn ($inner) => $inner->where('requester_contact', $contact))
                ->when($email !== '', fn ($inner) => $inner->orWhere('requester_email', $email)))
            ->whereRaw('lower(trim(subject)) = ?', [mb_strtolower($subject)])
            ->latest('id')
            ->first();
    }

    /**
     * Persist the optional intake screenshot on the private disk and record it as a
     * ticket attachment, so web uploads sit beside WhatsApp media under the same
     * channel-agnostic policy (MIME allow-list, size ceiling, sanitised name).
     */
    private function storeScreenshot(Ticket $ticket): void
    {
        if ($this->screenshot === null) {
            return;
        }

        $mime = (string) $this->screenshot->getMimeType();
        $size = (int) $this->screenshot->getSize();

        // The validator already restricts this to images; the policy is the second
        // gate, so nothing unexpected can slip onto disk.
        if (! AttachmentPolicy::allowsMime($mime) || ! AttachmentPolicy::withinSize($size)) {
            return;
        }

        $name = AttachmentPolicy::sanitizeName($this->screenshot->getClientOriginalName());
        $path = $this->screenshot->storeAs("attachments/{$ticket->id}", (string) Str::uuid().'-'.$name, 'local');

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_message_id' => null,
            'uploaded_by' => null,
            'original_filename' => Str::limit($name, 240, ''),
            'mime_type' => $mime,
            'file_size' => $size,
            'storage_path' => $path,
        ]);
    }

    public function with(): array
    {
        // Suggestions only — a free-text entry that matches nothing is still accepted.
        $allCompanies = Company::query()->active()->orderBy('name')->get();
        $search = mb_strtolower(trim($this->companySearch));

        // Matching happens in memory: the company list is short, and it keeps the
        // search case-insensitive on every driver instead of relying on LIKE collation.
        $companies = $search === ''
            ? $allCompanies
            : $allCompanies->filter(fn (Company $company) => str_contains(mb_strtolower($company->name), $search)
                || ($company->code !== null && str_contains(mb_strtolower($company->code), $search)));

        return [
            'companyNames' => $allCompanies->pluck('name'),
            // Full records for the picker modal (name + logo + code).
            'companies' => $companies,
            // The search box only earns its space once the list is long to scan.
            'showCompanySearch' => $allCompanies->count() > 6 || $search !== '',
            'selectedCompany' => $this->companyId !== null ? Company::query()->find($this->companyId) : null,
            'categories' => Category::query()->active()->ordered()->get(),
            'impacts' => $this->impactOptions(),
            'maxUploadMb' => round((int) config('tickets.attachments.max_size_kb', 5120) / 1024),
            // Knowledge-base self-help: react to the live selection, or to the
            // category the submitted request was filed under on the receipt.
            'suggestions' => KbArticle::suggestionsFor(
                $this->ticketNumber ? $this->submittedCategory : $this->category,
            ),
        ];
    }
}; ?>

<div class="w-full">
    {{-- Mandatory company picker: a full-screen dialog with no dismiss affordance, so
         the requester can only move on by choosing their company (or opting to type an
         unlisted one / recover an old link). Trapped focus and dialog semantics keep
         screen-reader and keyboard users inside it until they choose. --}}
    @if ($companyPicker)
        <div
            wire:key="company-picker"
            x-data
            x-trap.noscroll="true"
            x-init="($el.querySelector('input:not([type=file])') ?? $el.querySelector('button'))?.focus()"
            role="dialog"
            aria-modal="true"
            aria-labelledby="company-picker-heading"
            class="fixed inset-0 z-50 overflow-y-auto bg-canvas"
        >
            <div class="flex min-h-full flex-col">
                <div class="mx-auto w-full max-w-2xl px-4 py-8 sm:px-6 sm:py-12">
                    <header class="text-center">
                        <span class="inline-flex items-center rounded-full border border-accent/25 bg-accent/10 px-3 py-1 text-xs font-medium text-accent">Required first step</span>
                        <h2 id="company-picker-heading" class="mt-4 text-2xl font-semibold tracking-tight text-fg sm:text-3xl">Which company are you from?</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-fg-muted">Choose your company so IT can route and prioritise your request.</p>
                    </header>

                    @if ($showCompanySearch)
                        <div class="mt-6">
                            <flux:input wire:model.live.debounce.300ms="companySearch" icon="magnifying-glass" placeholder="Search companies" size="sm" autocomplete="off" />
                        </div>
                    @endif

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
                                            <span class="mt-0.5 block text-xs text-fg-muted">{{ $companyOption->code }}</span>
                                        @endif
                                    </span>
                                    <svg class="size-4 shrink-0 text-fg-subtle" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.22 3.77a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 1 1-1.06-1.06L11.94 9.5 7.22 4.83a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-8 flex flex-col items-center gap-3 text-center sm:flex-row sm:justify-center sm:gap-6">
                        <button type="button" wire:click="chooseOther" class="text-sm text-fg-muted underline underline-offset-4 transition hover:text-fg">Can't find your company? Enter it manually</button>
                        <button type="button" wire:click="startLookup" class="text-sm text-fg-muted underline underline-offset-4 transition hover:text-fg">Already submitted? Find your tracking link</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($lookupMode)
        {{-- Receipt recovery: the tracking link is the only credential, so losing it
             used to mean losing the request. Number + the contact detail left on it
             mints a fresh link. --}}
        <div class="card mx-auto w-full max-w-lg p-6">
            <h1 class="text-lg font-semibold tracking-tight">Find your tracking link</h1>
            <p class="mt-1 text-sm text-fg-muted">Enter the ticket number exactly as it was given to you, plus the phone number or email you left on the request.</p>

            <form wire:submit="findTicket" class="mt-5 flex flex-col gap-4">
                <flux:input wire:model="lookupNumber" label="Ticket number" placeholder="e.g. IT-2026-000123" autocomplete="off" autofocus />
                <flux:input wire:model="lookupContact" label="Phone number or email" placeholder="As left on the request" autocomplete="tel" />

                @error('lookupNumber') <p class="-mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:button wire:click="closeLookup" variant="subtle">Cancel</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="findTicket">Find my request</flux:button>
                </div>
            </form>
        </div>
    @elseif ($ticketNumber)
        <div class="rounded-xl border border-green-200 bg-green-50 p-6 text-center dark:border-green-900 dark:bg-green-950/40">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            </div>
            <h1 class="text-lg font-semibold text-green-800 dark:text-green-200">Request received</h1>
            <p class="mt-1 text-sm text-green-700 dark:text-green-300">Your ticket number is</p>
            <p class="mt-1 text-2xl font-bold tracking-wide text-green-800 dark:text-green-100">{{ $ticketNumber }}</p>
            <p class="mt-3 text-sm text-green-800 dark:text-green-200">Please save this number — it is how IT will reference your request. Quote it if you follow up.</p>
            @if ($trackUrl)
                {{-- The link is shown in full and copyable, not hidden behind one anchor
                     that can be lost when the tab closes. --}}
                <div x-data="{ copied: false }" class="mt-4 text-left">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-green-800 dark:text-green-200" for="tracking-link">Your tracking link</label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <input id="tracking-link" type="text" readonly value="{{ $trackUrl }}" onclick="this.select()" class="min-w-0 flex-1 rounded-lg border border-green-300 bg-white/80 px-3 py-2 font-mono text-xs text-green-900 [overflow-wrap:anywhere] focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 dark:border-green-800 dark:bg-green-900/40 dark:text-green-100" />
                        <button type="button" @click="navigator.clipboard?.writeText(@js($trackUrl))?.then(() => { copied = true; setTimeout(() => copied = false, 2000) })" x-text="copied ? 'Copied' : 'Copy'" class="shrink-0 rounded-lg border border-green-300 px-3 py-2 text-xs font-semibold text-green-800 transition hover:bg-green-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500 dark:border-green-800 dark:text-green-100 dark:hover:bg-green-900/60">Copy</button>
                    </div>
                    <a href="{{ $trackUrl }}" class="mt-2 inline-block text-sm font-semibold text-green-800 underline dark:text-green-100">Open it now to track progress and add updates →</a>
                    <p class="mt-1 text-xs text-green-800 dark:text-green-200">Keep this link — it is the only way to reopen this request without an account. Lost it? Use “Find your tracking link”.</p>
                </div>
            @endif
            @if ($mailedTo)
                <p class="mt-3 text-sm text-green-800 dark:text-green-200">We also mailed the number and link to <span class="font-semibold">{{ $mailedTo }}</span>.</p>
            @endif
            @if ($suggestions->isNotEmpty())
                <div class="mt-4 text-left">
                    <p class="text-xs font-semibold uppercase tracking-wide text-green-800 dark:text-green-200">Meanwhile, try this</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($suggestions as $article)
                            <li class="text-sm text-green-800 dark:text-green-200">• {{ $article->title }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
                <flux:button wire:click="again" variant="subtle">Submit another request</flux:button>
                <flux:button wire:click="startLookup" variant="ghost">Find another tracking link</flux:button>
            </div>
        </div>
    @else
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-semibold tracking-tight">Submit an IT request</h1>
            <p class="mt-1 text-sm text-fg-muted">No account needed. Fill this in and IT will get back to you.</p>
        </div>

        <form wire:submit="submit" class="flex flex-col gap-4">
            {{-- One consolidated banner so a failed submit explains itself at the top,
                 instead of only marking fields further down the page. --}}
            @if ($errors->any())
                <div role="alert" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                    <p class="font-semibold">Please fix the following before sending:</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <flux:input wire:model="name" label="Your name" placeholder="Full name" autocomplete="name" />

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
                                    <span class="block text-xs text-fg-muted">{{ $selectedCompany->code }}</span>
                                @endif
                            </span>
                        </span>
                        <flux:button type="button" wire:click="openCompanyPicker" variant="subtle" size="sm" class="shrink-0">Change</flux:button>
                    </div>
                @elseif ($manualCompany)
                    <flux:input wire:model="company" placeholder="Type your company name" list="known-companies" autocomplete="organization" />
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
                <flux:input wire:model="department" label="Department (optional)" placeholder="e.g. Finance" autocomplete="off" />
                <flux:input wire:model="position" label="Position (optional)" placeholder="e.g. Accountant" autocomplete="organization-title" />
            </div>

            <flux:input wire:model="contact" label="Phone or email" placeholder="How IT can reach you" autocomplete="tel" />

            {{-- Email is optional but separate: it is where the receipt goes, and a
                 lost tracking link can be recovered against it. --}}
            <flux:input wire:model="email" type="email" label="Email for your receipt (optional)" placeholder="you@company.com" autocomplete="email" inputmode="email" />

            <flux:input wire:model="subject" label="Subject" placeholder="Short summary of the issue" autocomplete="off" />

            {{-- Classification first helps IT route the request; AnyDesk is optional remote help. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="category" label="Category of problem">
                    @foreach ($categories as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="anydeskId" label="AnyDesk ID (optional)" placeholder="e.g. 123 456 789 — needed only for remote assistance" autocomplete="off" inputmode="numeric" />
            </div>

            {{-- Stated impact sets the priority (and therefore the SLA clock) the request
                 is filed under, so an outage is not queued behind a printer. --}}
            <fieldset class="rounded-lg border border-border bg-surface p-3">
                <legend class="px-1 text-sm font-medium">How far does it reach?</legend>
                <p class="px-1 text-xs text-fg-muted">This decides how quickly IT is expected to respond.</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($impacts as $impactKey => $impactOption)
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition hover:bg-surface-muted {{ $impact === $impactKey ? 'border-accent bg-accent/5' : 'border-border' }}">
                            <input type="radio" name="impact" value="{{ $impactKey }}" wire:model="impact" class="mt-0.5 size-4 shrink-0 accent-accent" />
                            <span class="min-w-0">
                                <span class="block text-sm font-medium leading-snug text-fg">{{ $impactOption['label'] }}</span>
                                <span class="block text-xs leading-5 text-fg-muted">{{ $impactOption['hint'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('impact') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Knowledge-base self-help that matches the chosen category (Phase 10). --}}
            @if ($suggestions->isNotEmpty())
                <div class="rounded-lg border border-border bg-surface-muted/40 p-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Fixes for “{{ $categories->firstWhere('key', $category)?->label() ?? 'this' }}”</p>
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

            <div x-data="{ len: {{ mb_strlen($description) }} }" @input="len = ($event.target.value || '').length">
                <flux:textarea wire:model="description" label="Description" rows="5" placeholder="What is happening? When did it start? What is affected?" autocomplete="off" />
                <p class="mt-1 text-right text-xs text-fg-muted" :class="len > 4700 ? 'text-amber-600' : ''" x-text="len + ' / 5000 characters'"></p>
            </div>

            {{-- Optional evidence upload, stored privately beside WhatsApp media. --}}
            <div>
                <flux:input wire:model="screenshot" type="file" accept="image/png,image/jpeg,image/webp" label="Screenshot or photo (optional)" />
                @if ($screenshot?->isPreviewable())
                    <div wire:loading.remove class="mt-2">
                        <img src="{{ $screenshot->temporaryUrl() }}" alt="Screenshot preview" class="max-h-40 rounded-lg border border-border object-contain" />
                    </div>
                @endif
                @error('screenshot') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-fg-muted">PNG, JPG or WebP, up to {{ $maxUploadMb }} MB. Screenshots are stored privately and only visible to IT.</p>
            </div>

            {{-- Honeypot: visually hidden, only bots fill it. --}}
            <div class="hidden" aria-hidden="true">
                <flux:input wire:model="website" label="Website" tabindex="-1" autocomplete="off" />
            </div>

            {{-- Soft duplicate warning: offer to confirm instead of filing a second copy. --}}
            @if ($duplicateOf !== null)
                <div role="alert" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <p class="font-semibold">This looks like a request you already filed</p>
                    <p class="mt-1">Ticket <span class="font-mono font-semibold">{{ $duplicateOf }}</span> is still open with the same subject and contact details. If it is the same problem, IT is already on it.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button type="button" wire:click="submitAnyway" variant="subtle" size="sm">It is a different problem — submit anyway</flux:button>
                        <flux:button type="button" wire:click="dismissDuplicateNotice" variant="ghost" size="sm">Let me edit my request</flux:button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
                <button type="button" wire:click="startLookup" class="text-sm text-fg-muted underline underline-offset-4 transition hover:text-fg">Already submitted? Find your tracking link</button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit" class="w-full justify-center sm:w-auto">
                    <span wire:loading.remove wire:target="submit">Submit request</span>
                    <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" /></svg>
                        Submitting…
                    </span>
                </flux:button>
            </div>
        </form>
    @endif
</div>
