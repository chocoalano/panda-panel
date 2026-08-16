<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Search\GlobalSearch;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

/*
 * Opting in
 */

it('searches only resources that declared attributes', function (): void {
    expect(UserResource::isGloballySearchable())->toBeTrue()
        ->and(UserResource::globalSearchAttributes())->toBe(['name', 'email']);
});

it('tells the frontend where to ask and how to behave', function (): void {
    $this->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('search.enabled', true)
            ->where('search.url', '/admin/search')
            ->where('search.debounce', 300)
            ->where('search.keyBindings', ['mod+k']));
});

it('disables the palette on a panel with nothing searchable', function (): void {
    // The App panel registers no resources at all, so a palette there could
    // only ever answer nothing.
    $this->get('/app')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('search.enabled', false)
            ->where('search.url', null));
});

it('disables the palette when the panel turns it off', function (): void {
    app(PanelManager::class)->get('admin')->globalSearch(false);

    $this->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('search.enabled', false));
});

/*
 * Searching
 */

it('finds a record by any declared attribute', function (): void {
    $byName = $this->getJson('/admin/search?q=Lovelace')->json('groups');
    $byEmail = $this->getJson('/admin/search?q=ada@example')->json('groups');

    expect($byName[0]['results'][0]['title'])->toBe('Ada Lovelace')
        ->and($byEmail[0]['results'][0]['title'])->toBe('Ada Lovelace');
});

it('groups hits under the resource they came from', function (): void {
    $groups = $this->getJson('/admin/search?q=Lovelace')->json('groups');

    expect($groups[0]['resource'])->toBe('users')
        ->and($groups[0]['label'])->toBe('Users')
        ->and($groups[0]['icon'])->toBe('users');
});

it('sends the details the resource chose', function (): void {
    $result = $this->getJson('/admin/search?q=Lovelace')->json('groups.0.results.0');

    expect($result['details'])->toBe([
        'Email' => 'ada@example.com',
        'Role' => 'Administrator',
    ]);
});

it('sends a url the server generated', function (): void {
    $result = $this->getJson('/admin/search?q=Lovelace')->json('groups.0.results.0');

    expect($result['url'])->toBe('/admin/users/'.$this->admin->getKey());
});

it('answers nothing for a term too short to mean anything', function (): void {
    expect($this->getJson('/admin/search?q=a')->json('groups'))->toBe([])
        ->and($this->getJson('/admin/search?q=')->json('groups'))->toBe([]);
});

it('answers nothing when there is no match', function (): void {
    expect($this->getJson('/admin/search?q=nobody-here')->json('groups'))->toBe([]);
});

it('treats SQL wildcard characters as literal search text', function (): void {
    User::factory()->create(['name' => 'Searchable Person']);

    expect($this->getJson('/admin/search?q=%%')->json('groups'))->toBe([])
        ->and($this->getJson('/admin/search?q=__')->json('groups'))->toBe([]);
});

/*
 * Limits and order
 */

it('keeps a resource within its own limit', function (): void {
    User::factory()->count(10)->create(['name' => 'Searchable Person']);

    $results = $this->getJson('/admin/search?q=Searchable')->json('groups.0.results');

    // UserResource allows five.
    expect($results)->toHaveCount(5);
});

it('keeps the whole search within the panel limit', function (): void {
    app(PanelManager::class)->get('admin')->globalSearch(limit: 2);

    User::factory()->count(6)->create(['name' => 'Searchable Person']);

    $results = $this->getJson('/admin/search?q=Searchable')->json('groups.0.results');

    expect($results)->toHaveCount(2);
});

it('orders resources deterministically', function (): void {
    expect(UserResource::globalSearchSort())->toBe(0);
});

/*
 * Authorization and scope
 */

it('refuses a panel the user may not enter', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/admin/search?q=Lovelace')
        ->assertForbidden();
});

it('sends a guest to login rather than searching', function (): void {
    auth()->logout();

    $this->get('/admin/search?q=Lovelace')->assertRedirect(route('login'));
});

it('skips a resource the user may not view at all', function (): void {
    // `canViewAny()` is checked before the resource is queried, so a refused
    // resource costs nothing and reveals nothing.
    $panel = app(PanelManager::class)->register(
        Panel::make('empty-search')->path('empty-search')->settings(false),
    );

    expect(app(GlobalSearch::class)->for($panel, 'Lovelace'))->toBe([]);
});

it('never leaves a model or a closure in the payload', function (): void {
    $encoded = $this->getJson('/admin/search?q=Lovelace')->content();

    expect($encoded)->not->toContain('App\\\\Models')
        ->and($encoded)->not->toContain('Closure')
        // The password hash is not among the details, whatever the model has.
        ->and($encoded)->not->toContain('$2y$');
});
