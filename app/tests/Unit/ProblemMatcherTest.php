<?php

use App\Support\ProblemMatcher;

// Pure text rules behind the Problem Tally's fuzzy repeat detection — no DB.

it('normalises case, punctuation and spacing into a stable match key', function () {
    expect(ProblemMatcher::normalized('WiFi is DOWN!!  in the office'))->toBe('wifi is down in the office')
        ->and(ProblemMatcher::normalized('printer jam (3rd floor)'))->toBe('printer jam 3rd floor');
});

it('keeps unicode letters and digits from any script', function () {
    expect(ProblemMatcher::tokens('Café wifi 🔌 écran'))
        ->toBe(['café', 'wifi', 'écran']);
});

it('treats blank and symbol-only subjects as tokenless so they never group', function () {
    expect(ProblemMatcher::tokens('   '))->toBe([])
        ->and(ProblemMatcher::normalized('??? !!!'))->toBe('');
});

it('scores jaccard word overlap between two token sets', function () {
    $a = ProblemMatcher::tokens('printer jam on 3rd floor');
    $b = ProblemMatcher::tokens('printer jams on 3rd floor');

    // intersect {printer,on,3rd,floor}=4 over union {printer,jam,jams,on,3rd,floor}=6
    expect(ProblemMatcher::similarity($a, $b))->toBeGreaterThan(0.6)
        ->and(ProblemMatcher::similarity($a, $a))->toBe(1.0)
        ->and(ProblemMatcher::similarity($a, ProblemMatcher::tokens('vpn keeps dropping')))->toBe(0.0)
        ->and(ProblemMatcher::similarity($a, []))->toBe(0.0);
});

it('collapses duplicate words into one token', function () {
    expect(ProblemMatcher::tokens('jam jam jam the the printer'))->toBe(['jam', 'the', 'printer']);
});
