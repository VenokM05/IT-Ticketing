<?php

use App\Services\TicketNumberGenerator;

beforeEach(function () {
    $this->generator = app(TicketNumberGenerator::class);
});

it('formats the first number of a year as IT-YYYY-000001', function () {
    expect($this->generator->next(2026))->toBe('IT-2026-000001');
});

it('increments sequentially within the same year', function () {
    expect($this->generator->next(2026))->toBe('IT-2026-000001')
        ->and($this->generator->next(2026))->toBe('IT-2026-000002')
        ->and($this->generator->next(2026))->toBe('IT-2026-000003');
});

it('starts a fresh sequence for each year', function () {
    expect($this->generator->next(2026))->toBe('IT-2026-000001')
        ->and($this->generator->next(2027))->toBe('IT-2027-000001');
});

it('generates unique, gap-free numbers across many calls', function () {
    $numbers = [];
    for ($i = 0; $i < 100; $i++) {
        $numbers[] = $this->generator->next(2030);
    }

    expect($numbers)->toHaveCount(100)
        ->and(count(array_unique($numbers)))->toBe(100)
        ->and($numbers[0])->toBe('IT-2030-000001')
        ->and($numbers[99])->toBe('IT-2030-000100');
});
