<?php

use App\Enums\MessageDirection;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Services\WhatsAppMediaService;
use App\Support\AttachmentPolicy;
use Illuminate\Support\Facades\Http;

// Phase 8 (Rec #9): uploads are validated against a MIME allow-list and size
// ceiling before anything is written to the private disk, and file names are
// sanitised to strip path separators and control characters.

it('accepts only allow-listed MIME types', function () {
    expect(AttachmentPolicy::allowsMime('image/jpeg'))->toBeTrue()
        ->and(AttachmentPolicy::allowsMime('application/pdf'))->toBeTrue()
        ->and(AttachmentPolicy::allowsMime('IMAGE/PNG'))->toBeTrue()
        ->and(AttachmentPolicy::allowsMime('application/x-php'))->toBeFalse()
        ->and(AttachmentPolicy::allowsMime('application/x-msdownload'))->toBeFalse();
});

it('enforces the configured size ceiling', function () {
    config(['tickets.attachments.max_size_kb' => 1]);

    expect(AttachmentPolicy::withinSize(500))->toBeTrue()
        ->and(AttachmentPolicy::withinSize(2048))->toBeFalse()
        ->and(AttachmentPolicy::withinSize(0))->toBeFalse();
});

it('strips directory traversal and unsafe characters from a file name', function () {
    $name = AttachmentPolicy::sanitizeName('../../etc/passwd');
    expect($name)->toBe('passwd')
        ->and($name)->not->toContain('/')
        ->and($name)->not->toContain('\\');

    $spaced = AttachmentPolicy::sanitizeName('my report (final).png');
    expect($spaced)->toBe('my_report__final_.png')
        ->and($spaced)->not->toContain(' ')
        ->and($spaced)->not->toContain('(');
});

it('rejects a disallowed attachment before writing it to disk', function () {
    config(['services.whatsapp.enabled' => true]);
    Storage::fake('local');
    Http::fake([
        '*/MEDIA123' => Http::response([
            'url' => 'https://lookaside.fbsbx.com/dl/x',
            'mime_type' => 'application/x-php',
            'file_size' => 20,
            'filename' => 'shell.php',
        ], 200),
        '*lookaside*' => Http::response('<?php system($_GET["c"]);', 200),
    ]);

    $ticket = Ticket::factory()->create();
    $message = TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Inbound,
        'message_type' => 'image',
        'body' => '',
    ]);

    $attachment = app(WhatsAppMediaService::class)->attach($ticket, $message, [
        'id' => 'MEDIA123', 'mime_type' => 'application/x-php', 'filename' => 'shell.php',
    ]);

    expect($attachment)->toBeNull()->and(TicketAttachment::count())->toBe(0);
});

it('stores an allow-listed attachment on the private disk', function () {
    config(['services.whatsapp.enabled' => true]);
    Storage::fake('local');
    Http::fake([
        '*/MEDIA456' => Http::response([
            'url' => 'https://lookaside.fbsbx.com/dl/y',
            'mime_type' => 'image/png',
            'file_size' => 8,
            'filename' => 'diagram.png',
        ], 200),
        '*lookaside*' => Http::response('PNGBYTES', 200),
    ]);

    $ticket = Ticket::factory()->create();
    $message = TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Inbound,
        'message_type' => 'image',
        'body' => '',
    ]);

    $attachment = app(WhatsAppMediaService::class)->attach($ticket, $message, [
        'id' => 'MEDIA456', 'mime_type' => 'image/png', 'filename' => 'diagram.png',
    ]);

    expect($attachment)->not->toBeNull()
        ->and(TicketAttachment::count())->toBe(1)
        ->and($attachment->mime_type)->toBe('image/png');
    Storage::disk('local')->assertExists($attachment->storage_path);
});
