<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\NavigationBuilder;
use Tests\Fixtures\Panel\SettingsFixturePage;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

/*
 * prefetch()
 */

it('prefetches on hover by default', function (): void {
    expect(app(PanelManager::class)->get('admin')->getPrefetch())->toBe('hover')
        ->and(app(PanelManager::class)->get('admin')->toSharedArray()['prefetch'])->toBe('hover');
});

it('accepts a mode, a boolean, or off', function (): void {
    expect(Panel::make('a')->prefetch('mount')->getPrefetch())->toBe('mount')
        ->and(Panel::make('b')->prefetch(false)->getPrefetch())->toBeNull()
        ->and(Panel::make('c')->prefetch(true)->getPrefetch())->toBe('hover')
        ->and(Panel::make('d')->prefetch('click')->getPrefetch())->toBe('click');
});

/*
 * fullPageUrls()
 */

it('marks no navigation item full page until the panel declares one', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $items = collect($page->toArray()['props']['navigation'])
                ->flatMap(fn (array $group): array => $group['items']);

            expect($items)->not->toBeEmpty()
                ->and($items->pluck('fullPage')->unique()->all())->toBe([false]);
        });
});

it('marks only the declared paths as full page', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('exceptions')
            ->path('exceptions')
            ->settings(false)
            ->fullPageUrls('/exceptions/settings')
            ->pages([SettingsFixturePage::class]),
    );

    $items = collect(app(NavigationBuilder::class)->for($panel, '/exceptions'))
        ->flatMap(fn (array $group): array => $group['items']);

    expect($items->firstWhere('label', 'Settings')['fullPage'])->toBeTrue();
});

it('matches a wildcard pattern, and an absolute url by its path', function (): void {
    $panel = Panel::make('patterns')->fullPageUrls('/reports/*');

    expect($panel->isFullPageUrl('/reports/monthly'))->toBeTrue()
        ->and($panel->isFullPageUrl('https://example.test/reports/monthly'))->toBeTrue()
        ->and($panel->isFullPageUrl('/reports'))->toBeFalse()
        ->and($panel->isFullPageUrl('/invoices/1'))->toBeFalse();
});

it('accumulates patterns rather than replacing them', function (): void {
    $panel = Panel::make('accumulates')
        ->fullPageUrls('/one')
        ->fullPageUrls('/two', '/three');

    expect($panel->getFullPageUrls())->toBe(['/one', '/two', '/three']);
});

it('treats a panel with no patterns as having no full-page links', function (): void {
    expect(Panel::make('none')->isFullPageUrl('/anything'))->toBeFalse();
});

/*
 * error notifications
 */

it('ships a default notification for the statuses a panel usually hits', function (): void {
    $notifications = app(PanelManager::class)->get('admin')->getErrorNotifications();

    expect(array_keys($notifications))->toBe([403, 404, 419, 429, 500, 503])
        ->and($notifications[403]['title'])->toBe('Not allowed');
});

it('lets a panel replace one status without restating the rest', function (): void {
    $panel = Panel::make('custom')
        ->errorNotification(403, 'Nope', 'Ask an administrator.');

    $notifications = $panel->getErrorNotifications();

    expect($notifications[403])->toBe(['title' => 'Nope', 'body' => 'Ask an administrator.'])
        ->and($notifications[404]['title'])->toBe('Not found');
});

it('records a hidden status as null so the frontend shows nothing at all', function (): void {
    $panel = Panel::make('quiet')->hideErrorNotification(404);

    expect($panel->getErrorNotifications()[404])->toBeNull()
        ->and($panel->toSharedArray()['errorNotifications'][404])->toBeNull();
});

it('serializes notifications without closures', function (): void {
    $shared = app(PanelManager::class)->get('admin')->toSharedArray();

    expect(json_encode($shared['errorNotifications']))->toBeString();

    array_walk_recursive($shared['errorNotifications'], function (mixed $value): void {
        expect($value)->not->toBeInstanceOf(Closure::class);
    });
});
