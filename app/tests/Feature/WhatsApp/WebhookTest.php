<?php

use App\Enums\WebhookProcessingStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WhatsAppWebhookEvent;

beforeEach(function () {
    config([
        'services.whatsapp.app_secret' => 'test-app-secret',
        'services.whatsapp.verify_token' => 'test-verify-token',
    ]);
});

/** Build a Meta-shaped inbound payload and a valid X-Hub-Signature-256 header. */
function waInboundPayload(string $from, string $body, string $wamid): array
{
    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Tester']]],
                    'messages' => [[
                        'from' => $from,
                        'id' => $wamid,
                        'type' => 'text',
                        'text' => ['body' => $body],
                        'timestamp' => (string) now()->timestamp,
                    ]],
                ],
            ]],
        ]],
    ];

    $raw = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $raw, config('services.whatsapp.app_secret'));

    return [$raw, ['HTTP_X-Hub-Signature-256' => $signature]];
}

function postRaw(string $raw, array $server)
{
    return test()->call('POST', '/api/whatsapp/webhook', [], [], [], $server, $raw);
}

it('returns the challenge when the verify token matches', function () {
    $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=ey123')
        ->assertOk()
        ->assertContent('ey123');
});

it('rejects verification with a wrong token', function () {
    $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=ey123')
        ->assertForbidden();
});

it('rejects a POST without a valid signature', function () {
    [$raw] = waInboundPayload('639171234567', 'printer broken', 'wamid.BAD');

    $this->call('POST', '/api/whatsapp/webhook', [], [], [], ['HTTP_X-Hub-Signature-256' => 'sha256=deadbeef'], $raw)
        ->assertUnauthorized();

    expect(WhatsAppWebhookEvent::count())->toBe(0);
});

it('ingests a valid signed message end-to-end: event processed and ticket created', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    Department::factory()->create();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    [$raw, $server] = waInboundPayload('639171234567', 'my laptop will not boot', 'wamid.IN1');

    postRaw($raw, $server)->assertOk();

    $event = WhatsAppWebhookEvent::first();
    expect($event)->not->toBeNull()
        ->and($event->processing_status)->toBe(WebhookProcessingStatus::Processed);

    expect(Ticket::count())->toBe(1);
    // Event row stores metadata only — the inbound body is not persisted there (§13.1).
    expect($event->payload)->not->toHaveKey('body');
    // An acknowledgment was queued back to the employee (§9.6).
    expect(TicketMessage::where('direction', 'outbound')->count())->toBe(1);
});

it('is idempotent — a replayed delivery creates no duplicate ticket', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

    Department::factory()->create();
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    [$raw, $server] = waInboundPayload('639171234567', 'vpn keeps disconnecting', 'wamid.DUP');

    postRaw($raw, $server)->assertOk();
    postRaw($raw, $server)->assertOk();

    expect(WhatsAppWebhookEvent::count())->toBe(1);
    expect(Ticket::count())->toBe(1);
});

it('deduplicates status events separately from inbound by composite key', function () {
    Http::fake();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => ['statuses' => [[
                    'id' => 'wamid.SAME',
                    'status' => 'delivered',
                    'recipient_id' => '639171234567',
                ]]],
            ]],
        ]],
    ];
    $raw = json_encode($payload);
    $server = ['HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, config('services.whatsapp.app_secret'))];

    postRaw($raw, $server)->assertOk();
    postRaw($raw, $server)->assertOk();

    expect(WhatsAppWebhookEvent::count())->toBe(1);
    expect(WhatsAppWebhookEvent::first()->event_key)->toBe('status:wamid.SAME');
});
