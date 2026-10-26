<?php

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Services\WhatsAppInboundService;

beforeEach(function () {
    Department::factory()->create();
});

/** Media lookup (GET .../MEDIA123) -> signed URL; lookaside GET -> bytes; send POST -> ids. */
function waMediaHttp(?int $lookupStatus = 200): void
{
    Http::fake([
        '*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        '*/MEDIA123' => $lookupStatus === 200
            ? Http::response([
                'url' => 'https://lookaside.fbsbx.com/dl/abcdef',
                'mime_type' => 'image/jpeg',
                'file_size' => 13,
                'filename' => 'screenshot.jpg',
            ], 200)
            : Http::response(['error' => ['message' => 'boom']], $lookupStatus),
        '*lookaside*' => $lookupStatus === 200
            ? Http::response('BYTES-1234567', 200)
            : Http::response('', 200),
    ]);
}

function inboundMedia(array $overrides = []): void
{
    app(WhatsAppInboundService::class)->handle(array_merge([
        'from' => '639171234567',
        'message_id' => 'wamid.'.uniqid(),
        'type' => 'image',
        'body' => '',
        'media' => ['id' => 'MEDIA123', 'mime_type' => 'image/jpeg', 'filename' => null],
        'context_id' => null,
        'timestamp' => now()->timestamp,
    ], $overrides));
}

it('downloads an inbound media message and stores it as a private ticket attachment', function () {
    config(['services.whatsapp.enabled' => true]);
    Storage::fake('local');
    waMediaHttp();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inboundMedia();

    expect(Ticket::count())->toBe(1);

    $attachment = TicketAttachment::first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->ticket_id)->toBe(Ticket::first()->id)
        ->and($attachment->ticket_message_id)->not->toBeNull()
        ->and($attachment->original_filename)->toBe('screenshot.jpg')
        ->and($attachment->mime_type)->toBe('image/jpeg')
        ->and($attachment->file_size)->toBe(13);

    Storage::disk('local')->assertExists($attachment->storage_path);
    expect(Storage::disk('local')->get($attachment->storage_path))->toBe('BYTES-1234567');
});

it('links the attachment to the inbound media message on an existing ticket', function () {
    config(['services.whatsapp.enabled' => true]);
    Storage::fake('local');
    waMediaHttp();

    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);

    inboundMedia(['type' => 'document', 'media' => ['id' => 'MEDIA123', 'mime_type' => 'application/pdf', 'filename' => 'error.pdf']]);

    $attachment = TicketAttachment::first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->ticket_id)->toBe($ticket->id)
        ->and($attachment->message->direction)->toBe(MessageDirection::Inbound);
});

it('skips the media download when WHATSAPP_ENABLED is false but still records the message', function () {
    config(['services.whatsapp.enabled' => false]);
    Storage::fake('local');
    waMediaHttp();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inboundMedia();

    expect(Ticket::count())->toBe(1)
        ->and(TicketMessage::where('direction', MessageDirection::Inbound)->count())->toBe(1)
        ->and(TicketAttachment::count())->toBe(0);

    // No outbound media call was made while disabled.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'lookaside'));
});

it('records the message without an attachment when the media lookup fails', function () {
    config(['services.whatsapp.enabled' => true]);
    Storage::fake('local');
    waMediaHttp(lookupStatus: 400);
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inboundMedia();

    expect(Ticket::count())->toBe(1)
        ->and(TicketMessage::where('direction', MessageDirection::Inbound)->count())->toBe(1)
        ->and(TicketAttachment::count())->toBe(0);
});

it('processes a signed image webhook end-to-end into a stored attachment', function () {
    config(['services.whatsapp.enabled' => true, 'services.whatsapp.app_secret' => 'test-app-secret']);
    Storage::fake('local');
    waMediaHttp();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => ['messages' => [[
                    'from' => '639171234567',
                    'id' => 'wamid.IMG',
                    'type' => 'image',
                    'image' => ['id' => 'MEDIA123', 'mime_type' => 'image/jpeg', 'sha256' => 'x'],
                    'timestamp' => (string) now()->timestamp,
                ]]],
            ]],
        ]],
    ];
    $raw = json_encode($payload);
    $server = ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'test-app-secret')];

    test()->call('POST', '/api/whatsapp/webhook', [], [], [], $server, $raw)->assertOk();

    expect(Ticket::count())->toBe(1)
        ->and(TicketAttachment::count())->toBe(1)
        ->and(TicketAttachment::first()->mime_type)->toBe('image/jpeg');
});
