<?php

use App\Support\MessageGrammar;

it('parses the fixed command grammar case-insensitively', function () {
    expect(MessageGrammar::parseCommand('new ticket my printer jammed'))
        ->toBe(['name' => 'new', 'arg' => 'my printer jammed'])
        ->and(MessageGrammar::parseCommand('STATUS IT-2026-000007'))
        ->toBe(['name' => 'status', 'arg' => 'IT-2026-000007'])
        ->and(MessageGrammar::parseCommand('close it-2026-000007'))
        ->toBe(['name' => 'close', 'arg' => 'IT-2026-000007'])
        ->and(MessageGrammar::parseCommand('just a normal sentence'))
        ->toBeNull();
});

it('detects pure greetings, acknowledgements and emoji-only text', function () {
    expect(MessageGrammar::isPureGreeting('Hi'))->toBeTrue()
        ->and(MessageGrammar::isPureGreeting('thank you!'))->toBeTrue()
        ->and(MessageGrammar::isPureGreeting('😂'))->toBeTrue()
        ->and(MessageGrammar::isPureGreeting('printer shows out of paper'))->toBeFalse();
});

it('validates a candidate new request against §9.5', function () {
    expect(MessageGrammar::isValidNewRequest('ok', false))->toBeFalse()
        ->and(MessageGrammar::isValidNewRequest('email is down', false))->toBeTrue()
        ->and(MessageGrammar::isValidNewRequest('', true))->toBeTrue(); // media always valid
});

it('extracts distinct ticket references', function () {
    expect(MessageGrammar::extractTicketNumbers('see IT-2026-000001 and it-2025-000002 again IT-2026-000001'))
        ->toBe(['IT-2026-000001', 'IT-2025-000002']);
});
