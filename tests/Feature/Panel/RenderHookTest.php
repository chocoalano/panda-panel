<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Pages\Settings;
use App\Panels\Admin\Resources\Users\UserResource;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Enums\RenderHook;
use PandaPanel\Pages\Settings\ProfileSettings;

it('registers a hook against a point in the shell', function (): void {
    $panel = Panel::make('hooked')->renderHook(
        RenderHook::HeaderEnd,
        'Panels/Admin/Hooks/Announcement',
        ['message' => 'Maintenance at 5pm'],
    );

    expect($panel->getRenderHooks())->toBe([
        'header.end' => [[
            'component' => 'Panels/Admin/Hooks/Announcement',
            'data' => ['message' => 'Maintenance at 5pm'],
            'scopes' => [],
        ]],
    ]);
});

it('keeps several hooks at one point in registration order', function (): void {
    $panel = Panel::make('ordered')
        ->renderHook(RenderHook::PageStart, 'First')
        ->renderHook(RenderHook::PageStart, 'Second');

    expect(array_column($panel->getRenderHooks()['page.start'], 'component'))
        ->toBe(['First', 'Second']);
});

it('reduces a resource or page class to the slug its pages report', function (): void {
    $panel = Panel::make('scoped')->renderHook(
        RenderHook::PageEnd,
        'Note',
        scopes: [UserResource::class, Settings::class, ProfileSettings::class],
    );

    // Never a class name: page metadata may not carry one, so the scope it
    // is matched against may not either.
    expect($panel->getRenderHooks()['page.end'][0]['scopes'])
        ->toBe(['resource:users', 'page:settings', 'page:settings-profile']);
});

it('passes an unrecognized scope through as written', function (): void {
    $panel = Panel::make('literal')->renderHook(RenderHook::PageEnd, 'Note', scopes: ['page:custom']);

    expect($panel->getRenderHooks()['page.end'][0]['scopes'])->toBe(['page:custom']);
});

it('ships the hooks to the frontend without closures or class names', function (): void {
    app(PanelManager::class)->get('admin')->renderHook(
        RenderHook::SidebarEnd,
        'Panels/Admin/Hooks/Announcement',
        ['message' => 'Hello'],
        [UserResource::class],
    );

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $hooks = $page->toArray()['props']['panel']['renderHooks'];

            expect($hooks['sidebar.end'][0]['component'])->toBe('Panels/Admin/Hooks/Announcement')
                ->and($hooks['sidebar.end'][0]['scopes'])->toBe(['resource:users']);

            $encoded = json_encode($hooks);

            expect($encoded)->toBeString()
                ->and($encoded)->not->toContain('App\\\\Panels')
                ->and($encoded)->not->toContain('Closure');
        });
});

it('sends an empty map when a panel registered none', function (): void {
    expect(Panel::make('bare')->toSharedArray()['renderHooks'])->toBe([]);
});

/*
 * The scope each page reports, which is what a scoped hook matches against.
 */

it('reports a page scope from the page slug', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.scope', 'page:settings'));
});

it('reports the same resource scope on every page of a resource', function (): void {
    $admin = User::factory()->admin()->create();

    foreach (['/admin/users', '/admin/users/create', '/admin/users/'.$admin->getKey()] as $url) {
        $this->actingAs($admin)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('page.scope', 'resource:users'));
    }
});

it('reports a scope for the built-in settings pages too', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/settings/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.scope', 'page:settings-profile'));
});
