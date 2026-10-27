<?php

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Category;
use App\Models\Company;
use App\Models\Priority;
use App\Models\SavedFilter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Tickets')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $priority = '';
    public string $category = '';
    public string $technician = '';
    public string $companyId = '';
    public string $from = '';
    public string $to = '';

    /** @var list<int> ticket ids checked for a bulk action */
    public array $selected = [];

    /** @var list<int> ids on the current page, for select-all */
    public array $pageIds = [];

    public string $bulkAssignee = '';

    public string $saveViewName = '';

    /** The list filter props a saved view can capture/restore. */
    private const VIEW_KEYS = ['search', 'status', 'priority', 'category', 'technician', 'companyId', 'from', 'to'];

    public function updated($property): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset(['search', 'status', 'priority', 'category', 'technician', 'companyId', 'from', 'to']);
        $this->resetPage();
    }

    /**
     * Listing is visible to every active user; rows are scoped by role (FR-13).
     */
    public function with(): array
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        $tickets = Ticket::query()
            ->with(['assignee', 'employee', 'company', 'category', 'priority'])
            ->when(! $isAdmin, fn (Builder $q) => $q->where('assigned_to', $user->id))
            ->when($this->search, function (Builder $q) use ($isAdmin) {
                $q->where(function (Builder $inner) use ($isAdmin) {
                    $inner->where('ticket_number', 'like', "%{$this->search}%")
                        ->orWhere('subject', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%");

                    // Requester search is admin-only (§10.2 "where authorized"). Covers both
                    // enrolled employees and the self-declared public-requester snapshot.
                    if ($isAdmin) {
                        $inner->orWhereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$this->search}%"))
                            ->orWhere('requester_name', 'like', "%{$this->search}%")
                            ->orWhere('requester_company', 'like', "%{$this->search}%")
                            ->orWhere('requester_department', 'like', "%{$this->search}%")
                            ->orWhere('requester_contact', 'like', "%{$this->search}%");
                    }
                });
            })
            ->when($this->status, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->priority, fn (Builder $q) => $q->whereHas('priority', fn (Builder $p) => $p->where('key', $this->priority)))
            ->when($this->category, fn (Builder $q) => $q->whereHas('category', fn (Builder $c) => $c->where('key', $this->category)))
            ->when($this->technician, fn (Builder $q) => $q->where('assigned_to', $this->technician))
            ->when($this->companyId, fn (Builder $q) => $q->where('company_id', $this->companyId))
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest()
            ->paginate(15);

        $this->pageIds = $tickets->pluck('id')->all();
        // Drop selections that scrolled off the current page so bulk actions stay honest.
        $this->selected = array_values(array_intersect($this->selected, $this->pageIds));

        return [
            'tickets' => $tickets,
            'companies' => Company::query()->orderBy('name')->get(),
            'categories' => Category::query()->active()->ordered()->get(),
            'priorities' => Priority::query()->active()->ordered()->get(),
            'savedViews' => SavedFilter::query()->where('user_id', $user->id)->orderBy('name')->get(),
            'technicians' => $isAdmin
                ? User::query()->where('role', UserRole::Technician->value)->orderBy('name')->get()
                : collect(),
        ];
    }

    /**
     * Persist the current filters under a name (per user). Re-saving the same
     * name overwrites the earlier snapshot so a view stays a live bookmark.
     */
    public function saveView(): void
    {
        $this->validate(['saveViewName' => ['required', 'string', 'max:120']]);

        $payload = array_filter(
            array_intersect_key(get_object_vars($this), array_flip(self::VIEW_KEYS)),
            fn ($value) => $value !== '',
        );

        SavedFilter::query()->updateOrCreate(
            ['user_id' => auth()->id(), 'name' => trim($this->saveViewName)],
            ['payload' => $payload],
        );

        $this->reset('saveViewName');
        session()->flash('success', 'View saved.');
    }

    public function applyView(int $id): void
    {
        $view = SavedFilter::query()->where('user_id', auth()->id())->find($id);

        if (! $view) {
            return;
        }

        $this->reset(self::VIEW_KEYS);
        $this->fill(array_intersect_key($view->payload ?? [], array_flip(self::VIEW_KEYS)));
        $this->resetPage();
    }

    public function deleteView(int $id): void
    {
        SavedFilter::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function toggleAll(): void
    {
        $this->selected = $this->selected === [] ? $this->pageIds : [];
    }

    public function bulkAssign(): void
    {
        // Assignment is an administrator action (TicketPolicy::assign).
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->validate(['bulkAssignee' => ['required', 'integer', 'exists:users,id']]);

        $technician = User::find((int) $this->bulkAssignee);
        $done = 0;
        $skipped = 0;

        foreach ($this->selected as $id) {
            $ticket = Ticket::find($id);

            if (! $ticket || ! auth()->user()->can('assign', $ticket)) {
                $skipped++;

                continue;
            }

            app(TicketService::class)->assignTechnician($ticket, $technician, auth()->user());
            $done++;
        }

        $this->reset(['selected', 'bulkAssignee']);
        session()->flash('success', "Assigned {$done} ticket(s)".($skipped ? ", skipped {$skipped}" : '').'.');
    }

    public function bulkClose(): void
    {
        $done = 0;
        $skipped = 0;

        foreach ($this->selected as $id) {
            $ticket = Ticket::find($id);

            // Technicians only touch their own, non-closed tickets; already-closed
            // rows have no transition to Closed and are skipped, not forced.
            if (! $ticket || ! auth()->user()->can('update', $ticket) || ! $ticket->status->canTransitionTo(TicketStatus::Closed)) {
                $skipped++;

                continue;
            }

            try {
                app(TicketService::class)->changeStatus($ticket, TicketStatus::Closed, auth()->user(), 'Closed via bulk action');
                $done++;
            } catch (InvalidStatusTransition) {
                $skipped++;
            }
        }

        $this->reset('selected');
        session()->flash('success', "Closed {$done} ticket(s)".($skipped ? ", skipped {$skipped}" : '').'.');
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Tickets" :subtitle="auth()->user()->isAdmin() ? 'All tickets' : 'Your assigned tickets'">
        @if (auth()->user()->can('create', Ticket::class))
            <x-slot:actions>
                <flux:button :href="route('tickets.create')" variant="primary" icon="plus" wire:navigate>New Ticket</flux:button>
            </x-slot:actions>
        @endif
    </x-page.header>

    {{-- Search + filters (§10.2) --}}
    <div class="grid grid-cols-2 gap-3 card p-4 sm:grid-cols-3 lg:grid-cols-7">
        <div class="col-span-2 sm:col-span-1 lg:col-span-2">
            <flux:input wire:model.live.debounce.400ms="search" placeholder="Search number / subject / description…" icon="magnifying-glass" />
        </div>
        <flux:select wire:model.live="status" placeholder="All statuses">
            <flux:select.option value="">All statuses</flux:select.option>
            @foreach (TicketStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="priority" placeholder="All priorities">
            <flux:select.option value="">All priorities</flux:select.option>
            @foreach ($priorities as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="category" placeholder="All categories">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach ($categories as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if (auth()->user()->isAdmin())
            <flux:select wire:model.live="companyId" placeholder="All companies">
                <flux:select.option value="">All companies</flux:select.option>
                @foreach ($companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="technician" placeholder="Any technician">
                <flux:select.option value="">Any technician</flux:select.option>
                @foreach ($technicians as $tech)
                    <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:input type="date" wire:model.live="from" label="From" />
        <flux:input type="date" wire:model.live="to" label="To" />
        <flux:button wire:click="clear" variant="subtle">Clear</flux:button>
    </div>

    {{-- Saved views (§10.2): re-apply a familiar queue in one click --}}
    <div class="flex flex-wrap items-center gap-2 text-sm">
        @if ($savedViews->isNotEmpty())
            <span class="text-fg-muted">Saved views:</span>
            @foreach ($savedViews as $view)
                <span class="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2 py-0.5">
                    <button type="button" wire:click="applyView({{ $view->id }})" class="text-accent-content hover:underline">{{ $view->name }}</button>
                    <button type="button" wire:click="deleteView({{ $view->id }})" wire:confirm="Delete this view?" class="text-fg-subtle hover:text-red-600" aria-label="Delete {{ $view->name }}">&times;</button>
                </span>
            @endforeach
        @endif
        <flux:spacer />
        <flux:input wire:model="saveViewName" placeholder="Name this view" size="sm" class="w-44" />
        <flux:button size="sm" variant="subtle" wire:click="saveView">Save view</flux:button>
    </div>

    <div class="card overflow-hidden">
        @if (session('success'))
            <div class="border-b border-border bg-green-50 px-4 py-2 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
        @endif

        {{-- Bulk action bar: appears when rows are selected --}}
        @if ($selected !== [])
            <div class="flex flex-wrap items-center gap-2 border-b border-border bg-surface-muted px-4 py-2">
                <span class="text-sm text-fg-muted">{{ count($selected) }} selected</span>
                <flux:spacer />
                @if (auth()->user()->isAdmin())
                    <flux:select wire:model="bulkAssignee" placeholder="Assign to…" class="w-44">
                        @foreach ($technicians as $tech)
                            <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" wire:click="bulkAssign">Assign</flux:button>
                @endif
                <flux:button size="sm" variant="subtle" wire:click="bulkClose" wire:confirm="Close the selected tickets?">Close</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="$set('selected', [])">Clear</flux:button>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-4 py-2 w-8">
                            <input type="checkbox" wire:click="toggleAll" @checked($selected !== [] && count($selected) === count($pageIds)) class="size-4 rounded border-border accent-accent" aria-label="Select all">
                        </th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Ticket</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Requester</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Company</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Category</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Priority</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Status</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">SLA</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Technician</th>
                        <th class="px-4 py-2 font-medium text-fg-subtle">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="px-4 py-2">
                                <input type="checkbox" wire:model="selected" value="{{ $ticket->id }}" class="size-4 rounded border-border accent-accent" aria-label="Select {{ $ticket->ticket_number }}">
                            </td>
                            <td class="px-4 py-2">
                                <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="font-medium text-accent-content hover:underline">
                                    {{ $ticket->ticket_number }}
                                </a>
                                <div class="text-fg-muted">{{ $ticket->subject }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $ticket->requesterName() ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $ticket->companyLabel() }}</td>
                            <td class="px-4 py-2">{{ $ticket->category->label() }}</td>
                            <td class="px-4 py-2"><flux:badge :color="match ($ticket->priority?->key) { 'critical' => 'red', 'high' => 'orange', default => 'zinc' }" size="sm">{{ $ticket->priority?->label() }}</flux:badge></td>
                            <td class="px-4 py-2"><flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket->status->label() }}</flux:badge></td>
                            <td class="px-4 py-2">
                                @php($sla = $ticket->slaSummaryState())
                                @if ($sla === null)
                                    <span class="text-fg-subtle">—</span>
                                @else
                                    <flux:badge :color="match ($sla) { 'breached' => 'red', 'at_risk' => 'amber', default => 'green' }" size="sm">{{ $sla === 'at_risk' ? 'At risk' : ucfirst($sla) }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-fg-muted">{{ $ticket->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-fg-muted">No tickets match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($tickets->hasPages())
            <div class="border-t border-border px-4 py-3">
                {{ $tickets->links() }}
            </div>
        @endif
    </div>
</div>
