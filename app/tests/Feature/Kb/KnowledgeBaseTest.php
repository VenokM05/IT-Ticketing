<?php

use App\Models\KbArticle;
use App\Models\User;
use Livewire\Volt\Volt;

// Phase 10 (Rec #14): a knowledge base of admin-authored self-help articles,
// surfaced to requesters (intake form + receipt + tracking page) so a common fix
// can deflect a ticket. Only published articles show, matched by intake category
// plus any general (untagged) ones.

it('gates the knowledge base to administrators', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('settings.knowledge-base'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.knowledge-base'))->assertOk()->assertSee('Knowledge Base');
});

it('lets an admin author a published article and audits it', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.knowledge-base')
        ->call('startCreate')
        ->set('fTitle', 'Clear a stuck print job')
        ->set('fBody', 'Open the print queue and cancel the pending documents.')
        ->set('fCategory', 'printer')
        ->set('fPublished', true)
        ->call('save')
        ->assertHasNoErrors();

    $article = KbArticle::firstWhere('title', 'Clear a stuck print job');
    expect($article)->not->toBeNull()
        ->and($article->published)->toBeTrue()
        ->and($article->category_key)->toBe('printer');
});

it('requires a title and body', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.knowledge-base')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors(['fTitle', 'fBody']);
});

it('only suggests published articles for the matching category', function () {
    KbArticle::create(['title' => 'Published printer', 'body' => 'b', 'category_key' => 'printer', 'published' => true]);
    KbArticle::create(['title' => 'Draft printer', 'body' => 'b', 'category_key' => 'printer', 'published' => false]);
    KbArticle::create(['title' => 'Network guide', 'body' => 'b', 'category_key' => 'network', 'published' => true]);

    $titles = KbArticle::suggestionsFor('printer')->pluck('title')->all();

    expect($titles)->toContain('Published printer')
        ->and($titles)->not->toContain('Draft printer')
        ->and($titles)->not->toContain('Network guide');
});

it('treats an untagged article as general and offers it everywhere', function () {
    KbArticle::create(['title' => 'General tip', 'body' => 'x', 'category_key' => null, 'published' => true]);

    expect(KbArticle::suggestionsFor('printer')->pluck('title'))->toContain('General tip')
        ->and(KbArticle::suggestionsFor('access')->pluck('title'))->toContain('General tip');
});

it('surfaces published self-help on the public intake form for the chosen category', function () {
    KbArticle::create(['title' => 'Reset your password', 'body' => 'Use the portal.', 'category_key' => 'access', 'published' => true]);
    KbArticle::create(['title' => 'Hidden draft', 'body' => 'x', 'category_key' => 'access', 'published' => false]);

    Volt::test('public.submit-ticket')
        ->set('category', 'access')
        ->assertSee('Reset your password')
        ->assertDontSee('Hidden draft');
});
