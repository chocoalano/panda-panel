<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

it('ships the shell configuration the panel layout needs', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('panel.sidebar.variant', 'sidebar')
            // Appearance is a taste the panel owns; what the shell needs is
            // that it arrives as a value the Vue side knows how to render.
            ->where('panel.sidebar.appearance', fn (string $appearance): bool => in_array(
                $appearance, ['sidebar', 'floating', 'inset'], true,
            ))
            ->where('panel.sidebar.collapsible', true)
            ->where('panel.sidebar.defaultOpen', true)
            ->where('panel.darkMode', true)
            ->where('panel.maxContentWidth', null)
            ->has('sidebarOpen')
        );
});

it('lets a panel choose the header shell instead of a sidebar', function (): void {
    $panel = app(PanelManager::class)->register(
        Panel::make('top-nav')->path('top-nav')->sidebar(variant: 'header'),
    );

    expect($panel->getSidebar())->toBe([
        'collapsible' => true,
        'defaultOpen' => true,
        'variant' => 'header',
        'appearance' => 'inset',
        'width' => '16rem',
        'collapsedWidth' => '3rem',
        'component' => null,
    ])
        ->and($panel->toSharedArray()['sidebar']['variant'])->toBe('header');
});

it('lets a panel choose how the sidebar rail is drawn', function (): void {
    $panel = Panel::make('floating-rail')->sidebar(appearance: 'floating');

    expect($panel->getSidebar()['appearance'])->toBe('floating')
        ->and($panel->toSharedArray()['sidebar']['appearance'])->toBe('floating');
});

it('lets a panel turn collapsing off', function (): void {
    $panel = Panel::make('fixed')->sidebar(collapsible: false, defaultOpen: false);

    expect($panel->getSidebar()['collapsible'])->toBeFalse()
        ->and($panel->getSidebar()['defaultOpen'])->toBeFalse();
});

it('serializes a content width token the frontend can map to a literal class', function (): void {
    $panel = Panel::make('narrow')->maxContentWidth('5xl');

    expect($panel->toSharedArray()['maxContentWidth'])->toBe('5xl');
});

it('sends page metadata in the shape the layout reads', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('page', fn (AssertableInertia $meta) => $meta
                ->where('title', 'Dashboard')
                ->where('heading', 'Dashboard')
                ->where('subheading', null)
                ->has('breadcrumbs.0.label')
                ->has('breadcrumbs.0.href')
                ->has('breadcrumbs.0.current')
                ->has('headerActions')
                // Render hook scoping matches on this, and it is a slug
                // rather than a class name.
                ->where('scope', 'page:dashboard')
                // Null for a page in no cluster, which is most of them.
                ->where('cluster', null)
            )
        );
});

it('keeps the starter kit shell on non-panel pages', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->where('panel', null)
        );
});

it('keeps the auth shell reachable for guests', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Login')
            ->where('panel', null)
        );
});
