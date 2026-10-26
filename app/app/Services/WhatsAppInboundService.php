<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\MessageGrammar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Inbound message pipeline (PRD §8.4). Identifies the employee, then classifies
 * the message in the exact §9.4 order — command, explicit ticket reference,
 * reply/quote threading, recent-conversation auto-attach, then candidate new
 * request validated against §9.5 — creating or updating tickets and queuing the
 * employee acknowledgment. Unknown senders follow §9.2 (no ticket, no data
 * exposure, rate-limited guidance).
 */
class WhatsAppInboundService
{
    /** Tickets considered "still being worked" for auto-attach (PRD §9.4 rule 4). */
    private const OPEN_STATES = [
        TicketStatus::New,
        TicketStatus::Assigned,
        TicketStatus::InProgress,
        TicketStatus::Pending,
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly WhatsAppOutboundService $outbound,
        private readonly RelinkService $relinks,
        private readonly WhatsAppMediaService $media,
    ) {
    }

    /**
     * @param  array{from?: string, message_id?: string, type?: string, body?: string|null, context_id?: string|null, timestamp?: int}  $message
     */
    public function handle(array $message): void
    {
        $employee = Employee::findByNumber($message['from'] ?? null);

        if (! $employee) {
            $this->handleUnknownSender($message);

            return;
        }

        $body = trim((string) ($message['body'] ?? ''));
        $type = $message['type'] ?? 'text';

        // §9.4 rule 1 — commands.
        if (MessageGrammar::parseCommand($body) !== null) {
            $this->handleCommand($employee, $body, $type, $message);

            return;
        }

        // §9.4 rules 2-4 — resolve an existing ticket to attach to.
        $target = $this->resolveExistingTicket($employee, $body, $message['context_id'] ?? null);

        if ($target instanceof Ticket) {
            $this->attach($target, $message, $employee);

            return;
        }

        if ($target === 'ambiguous') {
            $this->replyMatchingTickets($employee, $body);

            return;
        }

        // §9.4 rule 5 — candidate new request, validated by §9.5.
        $this->maybeCreateTicket($employee, $body, $type, $message);
    }

    private function handleUnknownSender(array $message): void
    {
        $number = Employee::normalizeNumber($message['from'] ?? '');
        $body = trim((string) ($message['body'] ?? ''));

        // §9.3 — a registered employee asking to relink a new number.
        if (MessageGrammar::isRegister($body)) {
            $this->relinks->request($number, $message['message_id'] ?? null);
            $this->sendOnce('wa:relink:ack:'.$number, $number,
                'Your number-relink request was received. An IT administrator will confirm it shortly.');

            return;
        }

        // §9.2 — one registration-guidance reply per number per 24h; no ticket, no data.
        $this->sendOnce('wa:guidance:unknown:'.$number, $number,
            'This number is not registered with the IT helpdesk. Please contact your IT department to enroll.');
    }

    private function handleCommand(Employee $employee, string $body, string $type, array $message): void
    {
        $command = MessageGrammar::parseCommand($body);

        match ($command['name']) {
            'new' => $this->createTicket($employee, $command['arg'], $type, $body, $message),
            'status' => $this->replyStatus($employee, $command['arg']),
            'close' => $this->closeTicket($employee, $command['arg'], $body),
            'register' => $this->outbound->sendPlainText($employee->whatsapp_number,
                "Your number {$employee->whatsapp_number} is already registered. No relink is needed."),
            default => null,
        };
    }

    /**
     * §9.4 rules 2-4. Returns a Ticket to attach, the string 'ambiguous' when
     * multiple owned tickets match an explicit reference, or null for no match.
     *
     * @return Ticket|'ambiguous'|null
     */
    private function resolveExistingTicket(Employee $employee, string $body, ?string $contextId): Ticket|string|null
    {
        // Rule 2 — explicit ticket reference(s) in the body.
        $numbers = MessageGrammar::extractTicketNumbers($body);

        if ($numbers !== []) {
            $owned = Ticket::query()
                ->where('employee_id', $employee->id)
                ->whereIn('ticket_number', $numbers)
                ->get();

            if ($owned->count() > 1) {
                return 'ambiguous';
            }

            if ($owned->count() === 1) {
                return $owned->first();
            }
        }

        // Rule 3 — reply/quote threading to one of the sender's tickets.
        if ($contextId) {
            $threaded = TicketMessage::query()
                ->where('direction', MessageDirection::Outbound)
                ->where('whatsapp_message_id', $contextId)
                ->with('ticket')
                ->get()
                ->filter(fn (TicketMessage $m) => $m->ticket && $m->ticket->employee_id === $employee->id);

            if ($threaded->count() > 1) {
                return 'ambiguous';
            }

            if ($threaded->count() === 1) {
                return $threaded->first()->ticket;
            }
        }

        // Rule 4 — a single open ticket within the follow-up window.
        $open = Ticket::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', array_map(fn (TicketStatus $s) => $s->value, self::OPEN_STATES))
            ->get();

        if ($open->count() === 1) {
            $ticket = $open->first();
            $lastInbound = $this->lastInboundAt($ticket);
            $window = (int) config('services.whatsapp.followup_window_minutes', 60);

            if ($lastInbound && $lastInbound->gt(now()->subMinutes($window))) {
                return $ticket;
            }
        }

        return null;
    }

    private function maybeCreateTicket(Employee $employee, string $body, string $type, array $message): void
    {
        // §9.5 duplicate-inquiry guard: attach to a very recent auto-created ticket.
        $recent = Ticket::query()
            ->where('employee_id', $employee->id)
            ->where('created_at', '>=', now()->subMinutes((int) config('services.whatsapp.duplicate_inquiry_minutes', 10)))
            ->latest()
            ->first();

        if ($recent) {
            $this->attach($recent, $message, $employee);

            return;
        }

        $isMedia = in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true);

        if (! MessageGrammar::isValidNewRequest($body, $isMedia)) {
            // Greeting / acknowledgement / emoji-only: usage guidance, no ticket.
            $this->sendOnce('wa:guidance:usage:'.$employee->id, $employee->whatsapp_number,
                'To open a ticket, text: NEW TICKET <your issue>. To check one, text: STATUS <ticket-number>.');

            return;
        }

        $this->createTicket($employee, $body, $type, $body, $message);
    }

    private function createTicket(Employee $employee, string $content, string $type, string $rawBody, array $message = []): void
    {
        $subject = $this->deriveSubject($content);

        $ticket = $this->tickets->createTicket([
            'employee_id' => $employee->id,
            'subject' => $subject,
            'description' => $content,
            'category' => TicketCategory::General,
            'priority' => TicketPriority::Medium,
            'status' => TicketStatus::New,
        ]);

        $this->storeInbound($ticket, $message, $rawBody, $type);

        $this->outbound->notifyTicket(
            $ticket,
            $employee,
            "Ticket {$ticket->ticket_number} created: \"{$subject}\". Our IT team will follow up here.",
            'ticket_acknowledgment',
            [$ticket->ticket_number, $subject],
        );
    }

    private function attach(Ticket $ticket, array $message, Employee $employee): void
    {
        $this->storeInbound($ticket, $message, (string) ($message['body'] ?? ''), $message['type'] ?? 'text');
    }

    private function replyStatus(Employee $employee, string $ticketNumber): void
    {
        $ticket = Ticket::query()->where('employee_id', $employee->id)->where('ticket_number', $ticketNumber)->first();

        if (! $ticket) {
            $this->outbound->sendPlainText($employee->whatsapp_number, "No ticket {$ticketNumber} found for your number.");

            return;
        }

        $this->outbound->sendSession($ticket, $employee, "Ticket {$ticket->ticket_number} is currently {$ticket->status->label()}.");
    }

    private function closeTicket(Employee $employee, string $ticketNumber, string $rawBody): void
    {
        $ticket = Ticket::query()->where('employee_id', $employee->id)->where('ticket_number', $ticketNumber)->first();

        if (! $ticket) {
            $this->outbound->sendPlainText($employee->whatsapp_number, "No ticket {$ticketNumber} found for your number.");

            return;
        }

        $this->storeInbound($ticket, [], $rawBody, 'text');

        if ($ticket->status === TicketStatus::Closed) {
            $this->outbound->sendSession($ticket, $employee, "Ticket {$ticket->ticket_number} is already closed.");

            return;
        }

        $closed = $this->tickets->changeStatus($ticket, TicketStatus::Closed, null, 'Closed by employee via WhatsApp');
        $this->outbound->sendSession($closed, $employee, "Ticket {$closed->ticket_number} has been closed. Reply with a new message any time to reopen or add details.");
    }

    private function replyMatchingTickets(Employee $employee, string $body): void
    {
        $numbers = MessageGrammar::extractTicketNumbers($body);

        $owned = Ticket::query()
            ->where('employee_id', $employee->id)
            ->whereIn('ticket_number', $numbers)
            ->get();

        if ($owned->isEmpty()) {
            $owned = Ticket::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', array_map(fn (TicketStatus $s) => $s->value, self::OPEN_STATES))
                ->latest()
                ->limit(5)
                ->get();
        }

        $lines = $owned->map(fn (Ticket $t, $i) => ($i + 1).'. '.$t->ticket_number.' — '.$t->subject);

        $this->outbound->sendPlainText(
            $employee->whatsapp_number,
            "Which ticket do you mean? Reply with the ticket number:\n".$lines->implode("\n"),
        );
    }

    private function storeInbound(Ticket $ticket, array $message, string $body, string $type): TicketMessage
    {
        $ticketMessage = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'direction' => MessageDirection::Inbound,
            'channel' => \App\Enums\MessageChannel::WhatsApp,
            'message_type' => MessageType::tryFrom($type) ?? MessageType::Text,
            'body' => $body !== '' ? $body : null,
            'is_internal' => false,
        ]);

        // §8.4 — download and attach media (image/audio/video/document/sticker).
        // No-op when WhatsApp is disabled or the message carries no media id.
        $this->media->attach($ticket, $ticketMessage, $message['media'] ?? []);

        $this->touchFirstResponse($ticket);

        return $ticketMessage;
    }

    private function touchFirstResponse(Ticket $ticket): void
    {
        if ($ticket->first_response_at === null) {
            $ticket->forceFill(['first_response_at' => now()])->save();
        }
    }

    private function lastInboundAt(Ticket $ticket): ?Carbon
    {
        $max = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('direction', MessageDirection::Inbound)
            ->max('created_at');

        return $max ? Carbon::parse($max) : null;
    }

    private function deriveSubject(string $content): string
    {
        $first = trim(preg_replace('/\s+/', ' ', $content));

        return \Illuminate\Support\Str::limit($first !== '' ? $first : 'WhatsApp request', 80);
    }

    /** Rate-limited reply: at most one send per cache key per cooldown (§9.2). */
    private function sendOnce(string $cacheKey, string $number, string $text): void
    {
        if (Cache::has($cacheKey)) {
            return;
        }

        $this->outbound->sendPlainText($number, $text);
        Cache::put($cacheKey, 1, now()->addHours((int) config('services.whatsapp.unknown_guidance_cooldown_hours', 24)));
    }
}
