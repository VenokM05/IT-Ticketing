<?php

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransition;
use App\Models\DashboardNotification;
use App\Models\CannedResponse;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Services\WhatsAppOutboundService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Ticket')] class extends Component {
    public Ticket $ticket;

    public string $remark = '';

    public string $assigneeId = '';

    public string $classCategory = '';

    public string $classPriority = '';

    public string $progressWork = '';

    public string $progressFindings = '';

    public string $progressNext = '';

    public string $progressMinutes = '';

    public string $progressNote = '';

    public bool $progressInternal = false;

    public string $noteBody = '';

    public bool $noteInternal = true;

    // Selected canned-response id for each composer's "Insert macro" picker.
    public string $progressMacroId = '';

    public string $noteMacroId = '';

    // Optional requester satisfaction captured when resolving/closing (CSAT, Phase 6).
    public string $csatRating = '';

    public string $csatComment = '';

    public function mount(Ticket $ticket): void
    {
        // Server-side authorization on every page (FR-13).
        $this->authorize('view', $ticket);

        $this->classCategory = $ticket->category->value;
        $this->classPriority = $ticket->priority->value;

        $this->ticket = $ticket->load([
            'employee.department',
            'company',
            'assignee',
            'messages' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'attachments',
            'progressUpdates' => fn ($q) => $q->with('author')->orderByDesc('created_at'),
            'reports' => fn ($q) => $q->orderByDesc('created_at'),
            'statusHistories' => fn ($q) => $q->with('changer')->orderByDesc('created_at'),
        ]);
    }

    public function changeStatus(string $to): void
    {
        $this->authorize('update', $this->ticket);

        $target = TicketStatus::tryFrom($to);

        if ($target === null) {
            return;
        }

        try {
            app(TicketService::class)->changeStatus(
                $this->ticket,
                $target,
                auth()->user(),
                $this->remark ?: null,
            );
        } catch (InvalidStatusTransition $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        // Capture "How was IT?" alongside a resolve/close when a score was given.
        if ($this->csatRating !== '' && in_array($target, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            app(TicketService::class)->recordCsat($this->ticket, (int) $this->csatRating, $this->csatComment ?: null);
        }

        $this->reset(['remark', 'csatRating', 'csatComment']);
        $this->refreshTicket();
        $this->dispatch('notification-refresh');
        session()->flash('success', 'Status updated');
    }

    public function assign(): void
    {
        $this->authorize('assign', $this->ticket);

        $technician = User::query()
            ->where('role', UserRole::Technician->value)
            ->where('is_active', true)
            ->whereKey($this->assigneeId)
            ->firstOrFail();

        app(TicketService::class)->assignTechnician(
            $this->ticket,
            $technician,
            auth()->user(),
            $this->remark ?: null,
        );

        $this->reset(['remark', 'assigneeId']);
        $this->refreshTicket();
        $this->dispatch('notification-refresh');
        session()->flash('success', 'Technician assigned');
    }

    public function updateClass(): void
    {
        $this->authorize('update', $this->ticket);

        $validated = $this->validate([
            'classPriority' => ['required', 'string'],
            'classCategory' => ['required', 'string'],
        ]);

        app(TicketService::class)->updateClassification(
            $this->ticket,
            Priority::resolve($validated['classPriority']),
            Category::resolve($validated['classCategory']),
            auth()->user(),
        );

        $this->refreshTicket();
        session()->flash('success', 'Ticket updated');
    }

    public function addProgress(): void
    {
        $this->authorize('addProgress', $this->ticket);

        $this->validate([
            'progressWork' => ['nullable', 'string', 'max:2000'],
            'progressFindings' => ['nullable', 'string', 'max:2000'],
            'progressNext' => ['nullable', 'string', 'max:500'],
            'progressMinutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'progressNote' => ['nullable', 'string', 'max:2000'],
        ]);

        // Require at least one substantive field so an empty entry is never stored.
        if (collect([$this->progressWork, $this->progressFindings, $this->progressNext, $this->progressNote])->every(fn ($v) => trim((string) $v) === '')) {
            $this->addError('progressWork', 'Enter at least one progress field.');

            return;
        }

        app(TicketService::class)->addProgress($this->ticket, auth()->user(), [
            'work_performed' => $this->progressWork ?: null,
            'findings' => $this->progressFindings ?: null,
            'next_action' => $this->progressNext ?: null,
            'minutes_spent' => $this->progressMinutes === '' ? null : (int) $this->progressMinutes,
            'update_text' => $this->progressNote ?: null,
            'is_internal' => $this->progressInternal,
        ]);

        $this->reset(['progressWork', 'progressFindings', 'progressNext', 'progressMinutes', 'progressNote', 'progressInternal']);
        $this->refreshTicket();
        session()->flash('success', 'Progress recorded');
    }

    public function addNote(): void
    {
        $this->authorize('update', $this->ticket);

        $validated = $this->validate(['noteBody' => ['required', 'string', 'max:2000']]);
        $body = $validated['noteBody'];
        $wasInternal = $this->noteInternal;
        $employee = $this->ticket->employee;

        // Employee-visible notes dispatch over WhatsApp when live; internal notes
        // (and visible notes while WhatsApp is disabled) persist to the history only.
        if (! $wasInternal && config('services.whatsapp.enabled') && $employee?->whatsapp_number) {
            app(WhatsAppOutboundService::class)->sendSession($this->ticket, $employee, $body);
        } else {
            TicketMessage::create([
                'ticket_id' => $this->ticket->id,
                'user_id' => auth()->id(),
                'direction' => MessageDirection::Outbound,
                'channel' => MessageChannel::WhatsApp,
                'message_type' => MessageType::Text,
                'body' => $body,
                'is_internal' => $wasInternal,
            ]);
        }

        $this->reset('noteBody');
        $this->noteInternal = true;
        $this->refreshTicket();
        session()->flash('success', $wasInternal ? 'Internal note added' : 'Note added');
    }

    private function refreshTicket(): void
    {
        $this->ticket->refresh()->load([
            'assignee',
            'messages' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'progressUpdates' => fn ($q) => $q->with('author')->orderByDesc('created_at'),
            'reports' => fn ($q) => $q->orderByDesc('created_at'),
            'statusHistories' => fn ($q) => $q->with('changer')->orderByDesc('created_at'),
        ]);
    }

    /**
     * Append a canned response (placeholders rendered against this ticket) to the
     * progress "Note" field. Reuses the existing addProgress save path.
     */
    public function insertProgressMacro(): void
    {
        $rendered = $this->renderMacro($this->progressMacroId);

        if ($rendered === null) {
            return;
        }

        $this->progressNote = trim($this->progressNote) === '' ? $rendered : $this->progressNote."\n".$rendered;
        $this->reset('progressMacroId');
    }

    public function insertNoteMacro(): void
    {
        $rendered = $this->renderMacro($this->noteMacroId);

        if ($rendered === null) {
            return;
        }

        $this->noteBody = trim($this->noteBody) === '' ? $rendered : $this->noteBody."\n".$rendered;
        $this->reset('noteMacroId');
    }

    private function renderMacro(string $macroId): ?string
    {
        if ($macroId === '') {
            return null;
        }

        $macro = CannedResponse::find((int) $macroId);

        return $macro?->render($this->ticket);
    }

    public function with(): array
    {
        // Notification/delivery records related to this ticket (§10.3).
        $admins = User::query()->where('role', UserRole::Admin->value)->pluck('id');

        return [
            'allowedTransitions' => $this->ticket->status->allowedTransitions(),
            'macros' => CannedResponse::query()->orderBy('title')->get(),
            'technicians' => User::query()
                ->where('role', UserRole::Technician->value)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'notificationRecords' => DashboardNotification::query()
                ->where('ticket_id', $this->ticket->id)
                ->whereIn('user_id', $admins)
                ->latest()
                ->limit(10)
                ->get(),
            'canUpdate' => auth()->user()->can('update', $this->ticket),
            'canAssign' => auth()->user()->can('assign', $this->ticket),
            'canProgress' => auth()->user()->can('addProgress', $this->ticket),
            'categories' => Category::query()->active()->ordered()->get(),
            'priorities' => Priority::query()->active()->ordered()->get(),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight">{{ $ticket->ticket_number }}</h1>
                <flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }">
                    {{ $ticket->status->label() }}
                </flux:badge>
            </div>
            <p class="text-sm text-fg-muted">{{ $ticket->subject }}</p>
        </div>
        <flux:button :href="route('tickets')" wire:navigate variant="subtle" icon="arrow-left">Back to list</flux:button>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Main column --}}
        <div class="flex flex-col gap-4 lg:col-span-2">
            @if (session('success'))
                <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('success') }}</div>
            @endif
        
            {{-- Employee details + original request (§10.3) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Requester</h2>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                    <div><dt class="text-fg-muted">Name</dt><dd>{{ $ticket->requesterName() ?? '—' }}</dd></div>
                    <div><dt class="text-fg-muted">Company</dt><dd>{{ $ticket->companyLabel() }}</dd></div>
                    <div><dt class="text-fg-muted">Contact</dt><dd>{{ $ticket->requesterContact() ?? '—' }}</dd></div>
                    {{-- Intake email: where the public receipt (number + tracking link) went. --}}
                    @if ($ticket->requester_email)
                        <div><dt class="text-fg-muted">Email</dt><dd>{{ $ticket->requester_email }}</dd></div>
                    @endif
                    <div><dt class="text-fg-muted">Department</dt><dd>{{ $ticket->requesterDepartment() ?? '—' }}</dd></div>
                    <div><dt class="text-fg-muted">Position</dt><dd>{{ $ticket->requesterPosition() ?? '—' }}</dd></div>
                    @if ($ticket->anydesk_id)
                        <div><dt class="text-fg-muted">AnyDesk ID</dt><dd class="font-mono">{{ $ticket->anydesk_id }}</dd></div>
                    @endif
                    <div><dt class="text-fg-muted">Source</dt><dd>{{ $ticket->employee ? 'Enrolled' : 'Web request' }}</dd></div>
                    <div><dt class="text-fg-muted">Created</dt><dd>{{ $ticket->created_at->format('Y-m-d H:i') }}</dd></div>
                </dl>
                <h3 class="mt-4 text-sm font-semibold">Original request</h3>
                <p class="mt-1 whitespace-pre-line text-sm text-fg-muted">{{ $ticket->description }}</p>
            </div>

            {{-- Message history (§10.3) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">WhatsApp message history</h2>
                <div class="mt-3 space-y-3">
                    @forelse ($ticket->messages as $message)
                        <div class="flex {{ $message->direction->value === 'inbound' ? '' : 'justify-end' }}">
                                <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm {{ $message->direction->value === 'inbound' ? 'bg-surface-muted' : 'bg-accent/10' }}">
                                <div class="mb-1 flex items-center gap-2 text-xs text-fg-muted">
                                    <span>{{ ucfirst($message->direction->value) }} · {{ $message->channel->value }}</span>
                                    <span>{{ $message->created_at->format('Y-m-d H:i') }}</span>
                                    @if ($message->is_internal)
                                        <span class="italic">internal</span>
                                    @endif
                                    <span class="capitalize">{{ $message->delivery_status?->value ?? 'not delivered' }}</span>
                                </div>
                                {{ $message->body }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-fg-muted">No messages recorded yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Attachments --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Attachments</h2>
                @if ($ticket->attachments->isEmpty())
                    <p class="mt-2 text-sm text-fg-muted">No attachments.</p>
                @else
                    <ul class="mt-2 divide-y divide-border text-sm">
                        @foreach ($ticket->attachments as $attachment)
                            <li class="flex items-center justify-between py-2">
                                <span>{{ $attachment->original_filename }}</span>
                                <span class="text-fg-muted">{{ $attachment->mime_type }} · {{ round(($attachment->file_size ?? 0) / 1024) }} KB</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Progress history (§10.3) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Technician progress history</h2>
                @forelse ($ticket->progressUpdates as $update)
                    <div class="mt-3 rounded-lg border border-border p-3 text-sm">
                        <div class="flex items-center justify-between text-xs text-fg-muted">
                            <span>{{ $update->author?->name ?? 'System' }} · {{ $update->created_at->format('Y-m-d H:i') }}</span>
                            <span>
                                @if ($update->minutes_spent) {{ $update->minutes_spent }} min · @endif
                                {{ $update->is_internal ? 'Internal' : 'Employee-visible' }}
                            </span>
                        </div>
                        @if ($update->work_performed)<p class="mt-2"><span class="font-medium">Work:</span> {{ $update->work_performed }}</p>@endif
                        @if ($update->findings)<p class="mt-1"><span class="font-medium">Findings:</span> {{ $update->findings }}</p>@endif
                        @if ($update->next_action)<p class="mt-1"><span class="font-medium">Next:</span> {{ $update->next_action }}</p>@endif
                        @if ($update->update_text)<p class="mt-1 whitespace-pre-line">{{ $update->update_text }}</p>@endif
                    </div>
                @empty
                    <p class="mt-2 text-sm text-fg-muted">No progress updates yet.</p>
                @endforelse
            </div>

            {{-- Formal reports with draft marking (§10.3, FR-10) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Formal progress reports</h2>
                @forelse ($ticket->reports as $report)
                    <div class="mt-3 rounded-lg border border-border p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ $report->title ?: $report->report_number }}</span>
                            <flux:badge :color="$report->isDraft() ? 'amber' : 'green'" size="sm">{{ $report->status->label() }}</flux:badge>
                        </div>
                        <div class="mt-1 text-xs text-fg-muted">
                            {{ $report->report_type->label() }} · {{ $report->preparer?->name ?? 'Unassigned' }}
                            @if ($report->finalized_at) · finalized {{ $report->finalized_at->format('Y-m-d H:i') }}@endif
                        </div>
                        <p class="mt-2 text-fg-muted"><span class="font-medium">Problem:</span> {{ $report->problem_summary ?: '—' }}</p>
                        <p class="text-fg-muted"><span class="font-medium">Work:</span> {{ $report->work_performed ?: '— (pending technician input)' }}</p>
                        <p class="text-fg-muted"><span class="font-medium">Findings:</span> {{ $report->findings ?: '—' }}</p>
                        <p class="text-fg-muted"><span class="font-medium">Next:</span> {{ $report->next_steps ?: '—' }}</p>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-fg-muted">No reports yet — draft is created on the first In Progress transition.</p>
                @endforelse
            </div>

            {{-- Status history (§10.4) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Status history</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($ticket->statusHistories as $history)
                        <li class="flex items-start justify-between gap-2">
                            <div>
                                <span class="font-medium">{{ $history->from_status?->label() ?? '—' }}</span>
                                <span class="text-fg-muted">→</span>
                                <span class="font-medium">{{ $history->to_status->label() }}</span>
                                <div class="text-xs text-fg-muted">{{ $history->changer?->name ?? 'System' }} · {{ $history->created_at->format('Y-m-d H:i') }}</div>
                                @if ($history->remark)<div class="text-xs italic text-fg-muted">{{ $history->remark }}</div>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Notification & delivery records (§10.3) --}}
            <div class="card p-4">
                <h2 class="text-sm font-semibold">Notification records</h2>
                @forelse ($notificationRecords as $notification)
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span>{{ $notification->title }} <span class="text-fg-muted">→ {{ $notification->user?->name }}</span></span>
                        <span class="text-xs text-fg-muted">{{ $notification->created_at->format('Y-m-d H:i') }} · {{ $notification->read_at ? 'read' : 'unread' }}</span>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-fg-muted">No dashboard notifications for this ticket.</p>
                @endforelse
            </div>
        </div>

        {{-- Action sidebar (§10.4) --}}
        <div class="flex flex-col gap-5">
            @if ($errors->has('status'))
                <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ $errors->first('status') }}</div>
            @endif

            <div class="card p-4">
                <h2 class="text-sm font-semibold">Details</h2>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-fg-muted">Category</dt><dd>{{ $ticket->category->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">Priority</dt><dd>{{ $ticket->priority->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">Technician</dt><dd>{{ $ticket->assignee?->name ?? 'Unassigned' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">First response</dt><dd>{{ $ticket->first_response_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">Started</dt><dd>{{ $ticket->started_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">Resolved</dt><dd>{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-fg-muted">Closed</dt><dd>{{ $ticket->closed_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                </dl>
            </div>

            <div class="card p-4">
                <h2 class="text-sm font-semibold">SLA</h2>
                @php($responseState = $ticket->slaResponseState())
                @php($resolveState = $ticket->slaResolveState())
                @if ($responseState === null && $resolveState === null)
                    <p class="mt-2 text-sm text-fg-muted">No SLA tracked for this priority.</p>
                @else
                    <dl class="mt-2 space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-fg-muted">First response</dt>
                            <dd class="flex items-center gap-2">
                                <span class="text-fg-muted">{{ $ticket->response_due_at?->format('Y-m-d H:i') ?? '—' }}</span>
                                @if ($responseState !== null)
                                    <flux:badge :color="match ($responseState) { 'breached' => 'red', 'at_risk' => 'amber', default => 'green' }" size="sm">{{ $responseState === 'at_risk' ? 'At risk' : ucfirst($responseState) }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-fg-muted">Resolution</dt>
                            <dd class="flex items-center gap-2">
                                <span class="text-fg-muted">{{ $ticket->resolve_due_at?->format('Y-m-d H:i') ?? '—' }}</span>
                                @if ($resolveState !== null)
                                    <flux:badge :color="match ($resolveState) { 'breached' => 'red', 'at_risk' => 'amber', default => 'green' }" size="sm">{{ $resolveState === 'at_risk' ? 'At risk' : ucfirst($resolveState) }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                    </dl>
                @endif
            </div>

            @if ($ticket->rating !== null)
                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Requester satisfaction</h2>
                    <div class="mt-2 flex items-center gap-2">
                        <flux:badge color="green" size="sm">{{ $ticket->rating }}/5</flux:badge>
                        <span class="text-xs text-fg-muted">rated {{ $ticket->rated_at?->format('Y-m-d H:i') }}</span>
                    </div>
                    @if ($ticket->rating_comment)
                        <p class="mt-2 text-sm text-fg-muted">{{ $ticket->rating_comment }}</p>
                    @endif
                </div>
            @endif

            @if ($canUpdate)
                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Change status</h2>
                    <flux:input wire:model="remark" label="Remark" placeholder="Optional remark…" class="mt-2" />
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($allowedTransitions as $target)
                            <flux:button
                                size="sm"
                                variant="subtle"
                                wire:click="changeStatus('{{ $target->value }}')"
                            >{{ $target->label() }}</flux:button>
                        @empty
                            <p class="text-sm text-fg-muted">No further transitions — ticket is closed.</p>
                        @endforelse
                    </div>

                    {{-- "How was IT?" — optional CSAT captured alongside a resolve/close --}}
                    @if (collect($allowedTransitions)->contains(fn ($t) => in_array($t->value, ['resolved', 'closed'], true)))
                        <div class="mt-3 border-t border-border pt-3">
                            <p class="text-xs text-fg-muted">How was IT? (optional) — captured with the status change above.</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                @foreach ([1, 2, 3, 4, 5] as $star)
                                    <flux:button size="xs" variant="{{ (int) $csatRating === $star ? 'primary' : 'subtle' }}" wire:click="$set('csatRating', '{{ $star }}')">{{ $star }}</flux:button>
                                @endforeach
                            </div>
                            <flux:input wire:model="csatComment" placeholder="Comments (optional)" class="mt-2" />
                        </div>
                    @endif
                </div>

                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Category & priority</h2>
                    <div class="mt-2 space-y-2">
                        <flux:select wire:model="classCategory" placeholder="Category">
                            @foreach ($categories as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="classPriority" placeholder="Priority">
                            @foreach ($priorities as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button size="sm" wire:click="updateClass">Save</flux:button>
                    </div>
                </div>
            @endif

            @if ($canAssign)
                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Assign technician</h2>
                    <flux:select wire:model="assigneeId" placeholder="Select technician…" class="mt-2">
                        <flux:select.option value="">Select technician…</flux:select.option>
                        @foreach ($technicians as $tech)
                            <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" wire:click="assign" class="mt-2">Assign / reassign</flux:button>
                    <p class="mt-2 text-xs text-fg-muted">Assigning a New ticket moves it to Assigned (FR-06).</p>
                </div>
            @endif

            @if ($canProgress)
                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Log progress</h2>
                    <div class="mt-2 space-y-2">
                        <flux:textarea wire:model="progressWork" label="Work performed" rows="2" placeholder="What did you do?" />
                        <flux:textarea wire:model="progressFindings" label="Findings" rows="2" placeholder="What did you find?" />
                        <flux:input wire:model="progressNext" label="Next steps" placeholder="What is next?" />
                        <flux:input wire:model="progressMinutes" label="Time spent (minutes)" type="number" min="0" />
                        <flux:textarea wire:model="progressNote" label="Note" rows="2" placeholder="Free-text note (optional)" />
                        @if ($macros->isNotEmpty())
                            <div class="flex items-end gap-2">
                                <flux:select wire:model="progressMacroId" placeholder="Insert canned response…" class="grow">
                                    @foreach ($macros as $macro)
                                        <flux:select.option value="{{ $macro->id }}">{{ $macro->title }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button size="sm" variant="subtle" wire:click="insertProgressMacro">Insert into note</flux:button>
                            </div>
                        @endif
                        <flux:checkbox wire:model="progressInternal" label="Internal only (hide from employee)" />
                        @error('progressWork') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <flux:button size="sm" variant="primary" wire:click="addProgress">Record progress</flux:button>
                        <p class="text-xs text-fg-muted">The first entry finalizes the draft formal report (FR-10).</p>
                    </div>
                </div>
            @endif

            @if ($canUpdate)
                <div class="card p-4">
                    <h2 class="text-sm font-semibold">Add note</h2>
                    <div class="mt-2 space-y-2">
                        <flux:textarea wire:model="noteBody" rows="2" placeholder="Internal memo or employee-visible message…" />
                        @if ($macros->isNotEmpty())
                            <div class="flex items-end gap-2">
                                <flux:select wire:model="noteMacroId" placeholder="Insert canned response…" class="grow">
                                    @foreach ($macros as $macro)
                                        <flux:select.option value="{{ $macro->id }}">{{ $macro->title }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button size="sm" variant="subtle" wire:click="insertNoteMacro">Insert</flux:button>
                            </div>
                        @endif
                        <flux:checkbox wire:model="noteInternal" label="Internal only (hide from employee)" />
                        @error('noteBody') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <flux:button size="sm" wire:click="addNote">Add note</flux:button>
                        <p class="text-xs text-fg-muted">Unchecking “internal” sends the note to the employee over WhatsApp when messaging is enabled (§11).</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
