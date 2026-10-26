<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidStatusTransition;
use App\Models\DashboardNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Ticket')] class extends Component {
    public Ticket $ticket;

    public string $remark = '';

    public string $assigneeId = '';

    public string $classCategory = '';

    public string $classPriority = '';

    public function mount(Ticket $ticket): void
    {
        // Server-side authorization on every page (FR-13).
        $this->authorize('view', $ticket);

        $this->classCategory = $ticket->category->value;
        $this->classPriority = $ticket->priority->value;

        $this->ticket = $ticket->load([
            'employee.department',
            'assignee',
            'messages' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'attachments',
            'progressUpdates' => fn ($q) => $q->with('user')->orderByDesc('created_at'),
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

        $this->reset('remark');
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

        $this->ticket->update([
            'priority' => TicketPriority::from($validated['classPriority']),
            'category' => TicketCategory::from($validated['classCategory']),
        ]);

        $this->refreshTicket();
        session()->flash('success', 'Ticket updated');
    }

    private function refreshTicket(): void
    {
        $this->ticket->refresh()->load([
            'assignee',
            'messages' => fn ($q) => $q->with('user')->orderBy('created_at'),
            'progressUpdates' => fn ($q) => $q->with('user')->orderByDesc('created_at'),
            'reports' => fn ($q) => $q->orderByDesc('created_at'),
            'statusHistories' => fn ($q) => $q->with('changer')->orderByDesc('created_at'),
        ]);
    }

    public function with(): array
    {
        // Notification/delivery records related to this ticket (§10.3).
        $admins = User::query()->where('role', UserRole::Admin->value)->pluck('id');

        return [
            'allowedTransitions' => $this->ticket->status->allowedTransitions(),
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
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">{{ $ticket->ticket_number }}</h1>
                <flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }">
                    {{ $ticket->status->label() }}
                </flux:badge>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $ticket->subject }}</p>
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
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Requester</h2>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                    <div><dt class="text-zinc-500 dark:text-zinc-400">Name</dt><dd>{{ $ticket->employee?->name ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">WhatsApp</dt><dd>{{ $ticket->employee?->whatsapp_number ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">Department</dt><dd>{{ $ticket->employee?->department?->name ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">Location</dt><dd>{{ $ticket->employee?->location ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">Email</dt><dd>{{ $ticket->employee?->email ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500 dark:text-zinc-400">Created</dt><dd>{{ $ticket->created_at->format('Y-m-d H:i') }}</dd></div>
                </dl>
                <h3 class="mt-4 text-sm font-semibold">Original request</h3>
                <p class="mt-1 whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-300">{{ $ticket->description }}</p>
            </div>

            {{-- Message history (§10.3) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">WhatsApp message history</h2>
                <div class="mt-3 space-y-3">
                    @forelse ($ticket->messages as $message)
                        <div class="flex {{ $message->direction->value === 'inbound' ? '' : 'justify-end' }}">
                            <div class="max-w-[80%] rounded-lg px-3 py-2 text-sm {{ $message->direction->value === 'inbound' ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-blue-50 dark:bg-blue-950' }}">
                                <div class="mb-1 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>{{ ucfirst($message->direction->value) }} · {{ $message->channel->value }}</span>
                                    <span>{{ $message->created_at->format('Y-m-d H:i') }}</span>
                                    @if ($message->is_internal)
                                        <span class="italic">internal</span>
                                    @endif
                                    <span class="capitalize">{{ $message->delivery_status->value }}</span>
                                </div>
                                {{ $message->body }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">No messages recorded yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Attachments --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Attachments</h2>
                @if ($ticket->attachments->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No attachments.</p>
                @else
                    <ul class="mt-2 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        @foreach ($ticket->attachments as $attachment)
                            <li class="flex items-center justify-between py-2">
                                <span>{{ $attachment->original_filename }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $attachment->mime_type }} · {{ round(($attachment->file_size ?? 0) / 1024) }} KB</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Progress history (§10.3) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Technician progress history</h2>
                @forelse ($ticket->progressUpdates as $update)
                    <div class="mt-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $update->user?->name ?? 'System' }} · {{ $update->created_at->format('Y-m-d H:i') }}</span>
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
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No progress updates yet.</p>
                @endforelse
            </div>

            {{-- Formal reports with draft marking (§10.3, FR-10) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Formal progress reports</h2>
                @forelse ($ticket->reports as $report)
                    <div class="mt-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ $report->title ?: $report->report_number }}</span>
                            <flux:badge :color="$report->isDraft() ? 'amber' : 'green'" size="sm">{{ $report->status->label() }}</flux:badge>
                        </div>
                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $report->report_type->label() }} · {{ $report->preparer?->name ?? 'Unassigned' }}
                            @if ($report->finalized_at) · finalized {{ $report->finalized_at->format('Y-m-d H:i') }}@endif
                        </div>
                        <p class="mt-2 text-zinc-600 dark:text-zinc-400"><span class="font-medium">Problem:</span> {{ $report->problem_summary ?: '—' }}</p>
                        <p class="text-zinc-600 dark:text-zinc-400"><span class="font-medium">Work:</span> {{ $report->work_performed ?: '— (pending technician input)' }}</p>
                        <p class="text-zinc-600 dark:text-zinc-400"><span class="font-medium">Findings:</span> {{ $report->findings ?: '—' }}</p>
                        <p class="text-zinc-600 dark:text-zinc-400"><span class="font-medium">Next:</span> {{ $report->next_steps ?: '—' }}</p>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No reports yet — draft is created on the first In Progress transition.</p>
                @endforelse
            </div>

            {{-- Status history (§10.4) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Status history</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach ($ticket->statusHistories as $history)
                        <li class="flex items-start justify-between gap-2">
                            <div>
                                <span class="font-medium">{{ $history->from_status?->label() ?? '—' }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400">→</span>
                                <span class="font-medium">{{ $history->to_status->label() }}</span>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $history->changer?->name ?? 'System' }} · {{ $history->created_at->format('Y-m-d H:i') }}</div>
                                @if ($history->remark)<div class="text-xs italic text-zinc-600 dark:text-zinc-400">{{ $history->remark }}</div>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Notification & delivery records (§10.3) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Notification records</h2>
                @forelse ($notificationRecords as $notification)
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span>{{ $notification->title }} <span class="text-zinc-500 dark:text-zinc-400">→ {{ $notification->user?->name }}</span></span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->created_at->format('Y-m-d H:i') }} · {{ $notification->read_at ? 'read' : 'unread' }}</span>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No dashboard notifications for this ticket.</p>
                @endforelse
            </div>
        </div>

        {{-- Action sidebar (§10.4) --}}
        <div class="flex flex-col gap-4">
            @if ($errors->has('status'))
                <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ $errors->first('status') }}</div>
            @endif

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="text-sm font-semibold">Details</h2>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Category</dt><dd>{{ $ticket->category->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Priority</dt><dd>{{ $ticket->priority->label() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Technician</dt><dd>{{ $ticket->assignee?->name ?? 'Unassigned' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">First response</dt><dd>{{ $ticket->first_response_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Started</dt><dd>{{ $ticket->started_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Resolved</dt><dd>{{ $ticket->resolved_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Closed</dt><dd>{{ $ticket->closed_at?->format('Y-m-d H:i') ?? '—' }}</dd></div>
                </dl>
            </div>

            @if ($canUpdate)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
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
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">No further transitions — ticket is closed.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-sm font-semibold">Category & priority</h2>
                    <div class="mt-2 space-y-2">
                        <flux:select wire:model="classCategory" placeholder="Category">
                            @foreach (TicketCategory::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="classPriority" placeholder="Priority">
                            @foreach (TicketPriority::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button size="sm" wire:click="updateClass">Save</flux:button>
                    </div>
                </div>
            @endif

            @if ($canAssign)
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 class="text-sm font-semibold">Assign technician</h2>
                    <flux:select wire:model="assigneeId" placeholder="Select technician…" class="mt-2">
                        <flux:select.option value="">Select technician…</flux:select.option>
                        @foreach ($technicians as $tech)
                            <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" wire:click="assign" class="mt-2">Assign / reassign</flux:button>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Assigning a New ticket moves it to Assigned (FR-06).</p>
                </div>
            @endif
        </div>
    </div>
</div>
