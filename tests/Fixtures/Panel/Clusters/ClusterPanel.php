<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Clusters;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\RelationSchema;

/**
 * A panel of its own for the cluster fixtures.
 *
 * A cluster moves the URLs of everything in it, so proving one against the
 * admin panel would mean moving that panel's real pages to demonstrate a
 * framework feature — which is a change to the application rather than to
 * the framework.
 */
final class ClusterPanel
{
    public const ID = 'cluster-host';

    public const PATH = 'cluster-host';

    public static function boot(): Panel
    {
        RelationSchema::create();

        Gate::policy(Project::class, ProjectPolicy::class);

        $manager = app(PanelManager::class);

        if (! $manager->has(self::ID)) {
            $panel = $manager->register(
                Panel::make(self::ID)
                    ->path(self::PATH)
                    ->settings(false)
                    ->navigationGroups(['System', 'Access' => 'System'])
                    ->sidebarWidth('18rem', '4rem')
                    ->userMenuItems([
                        ['label' => 'Support', 'url' => '/support', 'icon' => 'info'],
                    ])
                    ->resources([ClusteredTaskResource::class])
                    ->pages([ClusteredReportPage::class]),
            );

            app(PanelRouteRegistrar::class)->register($panel);

            // Routes registered after boot are invisible to `route()` and
            // `Route::has()` until the name lookup is rebuilt.
            Route::getRoutes()->refreshNameLookups();
        }

        $panel = $manager->get(self::ID);

        $manager->setCurrentPanel($panel);

        return $panel;
    }

    public static function url(string $path = ''): string
    {
        return '/'.self::PATH.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
