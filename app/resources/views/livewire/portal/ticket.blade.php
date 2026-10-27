<?php

use App\Models\EmployeeToken;
use App\Models\KbArticle;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Tokenized self-service lookup (PRD Phase 10, Rec #13). A requester opens the
 * magic link handed to them at intake — no login. They see their own ticket's
 * live status and public activity and can post a follow-up comment, which is
 * stored as an inbound portal message. An unknown or expired token is a 404.
 */
new #[Layout('components.layouts.auth')] #[Title('Track your request')] class extends Component {
    public string $token = '';

    public string $comment = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        // Fail closed before rendering anything if the link is not valid.
        $this->ticket();
    }

    private function ticket(): Ticket
    {
        $record = EmployeeToken::resolve($this->token);

        abort_unless($record !== null, 404, 'This tracking link is invalid or has expired.');

        return $record->ticket()->firstOrFail();
    }

    public function addComment(): void
    {
        $ticket = $this->ticket();

        $this->validate(['comment' => ['required', 'string', 'max:5000']], [
            'comment.required' => 'Please type a message.',
        ]);

        app(TicketService::class)->addRequesterComment($ticket, $this->comment);

        $this->reset('comment');
        session()->flash('posted', 'Thanks — your update was added to the ticket.');
    }

    public function with(): array
    {
        $ticket = $this->ticket();
        $ticket->loadMissing(['category', 'priority', 'assignee', 'statusHistories']);

        // Public activity only: non-internal technician progress + requester/staff messages.
        $updates = $ticket->progressUpdates()->where('is_internal', false)->latest()->get();
        $messages = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('is_internal', false)
            ->latest()
            ->get();

        $timeline = $updates->map(fn ($u) => [
            'at' => $u->created_at,
            'who' => $u->user?->name ?? 'Technician',
            'text' => $u->update_text ?: $u->work_performed ?: $u->next_action,
        ])->concat($messages->map(fn ($m) => [
            'at' => $m->created_at,
            'who' => $m->direction->value === 'inbound' ? 'You' : ($m->user?->name ?? 'IT'),
            'text' => $m->body,
        ]))
            ->filter(fn ($row) => filled($row['text']))
            ->sortByDesc('at')
            ->values();

        return [
            'ticket' => $ticket,
            'timeline' => $timeline,
            'suggestions' => KbArticle::suggestionsFor($ticket->category?->key),
        ];
    }
}; ?>

<div class="w-full">
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-semibold tracking-tight">Track your request</h1>
        <p class="mt-1 text-sm text-fg-muted">{{ $ticket->ticket_number }} · submitted {{ $ticket->created_at->format('d M Y H:i') }}</p>
    </div>

    <div class="card p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">{{ $ticket->subject }}</h2>
                <p class="mt-1 text-sm text-fg-muted">Category: {{ $ticket->category?->label() ?? 'Uncategorised' }}</p>
            </div>
            <flux:badge :color="$ticket->status->isOpen() ? 'amber' : 'green'" size="sm">{{ $ticket->status->label() }}</flux:badge>
        </div>

        <p class="mt-3 rounded-lg bg-surface-muted/50 p-3 text-sm text-fg-muted">{{ $ticket->description }}</p>

        @if (session('posted'))
            <div class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-700 dark:bg-green-950 dark:text-green-300">{{ session('posted') }}</div>
        @endif

        <form wire:submit="addComment" class="mt-4">
            <flux:textarea wire:model="comment" rows="3" label="Add an update for IT" placeholder="Anything the technician should know…" />
            @error('comment') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <div class="mt-2 flex justify-end">
                <flux:button type="submit" variant="primary">Send update</flux:button>
            </div>
        </form>
    </div>

    <div class="card mt-5 p-5">
        <h3 class="text-sm font-semibold">Activity</h3>
        <ul class="mt-3 space-y-3">
            @forelse ($timeline as $row)
                <li class="border-l-2 border-border pl-3">
                    <p class="text-xs text-fg-subtle">{{ $row['who'] }} · {{ $row['at']->format('d M Y H:i') }}</p>
                    <p class="text-sm">{{ $row['text'] }}</p>
                </li>
            @empty
                <li class="text-sm text-fg-muted">No updates yet — IT will post progress here.</li>
            @endforelse
        </ul>
    </div>

    @if ($suggestions->isNotEmpty())
        <div class="card mt-5 p-5">
            <h3 class="text-sm font-semibold">You might also try</h3>
            <ul class="mt-2 space-y-2">
                @foreach ($suggestions as $article)
                    <li class="text-sm">
                        <span class="font-medium">{{ $article->title }}</span>
                        <span class="block text-xs text-fg-muted">{{ \Illuminate\Support\Str::limit($article->body, 160) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
