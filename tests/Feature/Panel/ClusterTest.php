<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Enums\ClusterPosition;
use PandaPanel\Support\ClusterNavigation;
use Tests\Fixtures\Panel\Clusters\ClusteredReportPage;
use Tests\Fixtures\Panel\Clusters\ClusteredTaskResource;
use Tests\Fixtures\Panel\Clusters\ClusterPanel;
use Tests\Fixtures\Panel\Clusters\OperationsCluster;
use Tests\Fixtures\Panel\Clusters\RightBarCluster;

beforeEach(function (): void {
    $this->panel = ClusterPanel::boot();

    $this->actingAs(User::factory()->create());
});

/*
 * Routing
 */

it('prefixes a member\'s path without changing its route name', function (): void {
    // The route name is what every `url()` in the application already uses,
    // so a cluster moves the URL and nothing else.
    expect(Route::has('panel.cluster-host.resources.clustered-tasks.index'))->toBeTrue()
        ->and(ClusteredTaskResource::url(panel: $this->panel))
        ->toBe('/cluster-host/ops/clustered-tasks')
        ->and(ClusteredReportPage::url($this->panel))
        ->toBe('/cluster-host/ops/throughput')
        ->and(ClusteredReportPage::routePath())->toBe('ops/throughput');
});

it('takes its slug and title from its class when it states neither', function (): void {
    expect(RightBarCluster::slug())->toBe('reports')
        ->and(RightBarCluster::title())->toBe('Reports')
        ->and(RightBarCluster::position())->toBe(ClusterPosition::RightBar)
        ->and(OperationsCluster::position())->toBe(ClusterPosition::Header);
});

/*
 * Navigation
 */

it('lists a cluster once, with its members as children', function (): void {
    $this->get(ClusterPanel::url())
        ->assertInertia(function (AssertableInertia $page): void {
            $items = collect($page->toArray()['props']['navigation'])
                ->flatMap(static fn (array $group): array => $group['items']);

            $cluster = $items->firstWhere('label', 'Operations');

            // One item that expands, not one item per member beside it.
            expect($cluster)->not->toBeNull()
                ->and(array_column($cluster['children'], 'label'))
                ->toBe(['Tasks', 'Throughput'])
                ->and($items->firstWhere('label', 'Tasks'))->toBeNull()
                ->and($items->firstWhere('label', 'Throughput'))->toBeNull();
        });
});

it('points a cluster at the first member the user may see', function (): void {
    $items = ClusterNavigation::all($this->panel)[OperationsCluster::class] ?? [];

    expect($items)->not->toBe([])
        ->and($items[0]->href)->toBe('/cluster-host/ops/clustered-tasks');
});

it('carries an active icon on every item, active or not', function (): void {
    $this->get(ClusterPanel::url())
        ->assertInertia(function (AssertableInertia $page): void {
            $cluster = collect($page->toArray()['props']['navigation'])
                ->flatMap(static fn (array $group): array => $group['items'])
                ->firstWhere('label', 'Operations');

            // Sent whether or not it is active, so the swap happens on a
            // client-side navigation without a round trip.
            expect($cluster['icon'])->toBe('settings')
                ->and($cluster['activeIcon'])->toBe('shield')
                // A member that declared only one icon still has both.
                ->and($cluster['children'][1]['activeIcon'])
                ->toBe($cluster['children'][1]['icon']);
        });
});

/*
 * The bar a member page renders
 */

it('sends a member page its cluster\'s sub-navigation', function (): void {
    $this->get(ClusterPanel::url('ops/throughput'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $cluster = $page->toArray()['props']['page']['cluster'];

            expect($cluster['label'])->toBe('Operations')
                ->and($cluster['position'])->toBe('header')
                ->and(array_column($cluster['items'], 'label'))
                ->toBe(['Tasks', 'Throughput']);
        });
});

it('sends the bar to a resource page in the cluster too', function (): void {
    $this->get(ClusterPanel::url('ops/clustered-tasks'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            expect($page->toArray()['props']['page']['cluster']['label'])
                ->toBe('Operations');
        });
});

it('marks the member being looked at as the active one', function (): void {
    $cluster = ClusterNavigation::for(
        $this->panel,
        OperationsCluster::class,
        'cluster-host/ops/throughput',
    );

    expect(collect($cluster['items'])->firstWhere('active', true)['label'])
        ->toBe('Throughput');
});

it('renders nothing for a cluster with no visible members', function (): void {
    // Nothing declared itself part of this one, so there is no bar to draw
    // rather than an empty one.
    expect(ClusterNavigation::for($this->panel, RightBarCluster::class, '/'))
        ->toBeNull();
});

/*
 * Shell configuration
 */

it('sends the widths, the groups, and the menu the panel declared', function (): void {
    $this->get(ClusterPanel::url())
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $panel = $props['panel'];

            // Lengths rather than sizes: they become custom properties, and a
            // class built by interpolation would not exist in the bundle.
            expect($panel['sidebar']['width'])->toBe('18rem')
                ->and($panel['sidebar']['collapsedWidth'])->toBe('4rem')
                ->and($panel['shell']['navigation'])->toBeTrue()
                ->and($panel['shell']['userMenuItems'])->toBe([
                    ['label' => 'Support', 'url' => '/support', 'icon' => 'info'],
                ]);

            // `'Access' => 'System'` nests Access under System.
            $groups = collect($props['navigation'])->pluck('parent', 'label');

            expect($groups['System'] ?? null)->toBeNull();
        });
});
