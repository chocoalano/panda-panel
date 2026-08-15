<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Forms;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;
use Tests\Fixtures\Panel\Relations\RelationSchema;

/**
 * A panel of its own for the form fixtures.
 *
 * The admin panel is already registered and registering it twice is an error
 * by design, so anything needing real routes builds its own.
 */
final class FormFixturePanel
{
    public const ID = 'form-host';

    public const PATH = 'form-host';

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
                    ->resources([FormFixtureResource::class]),
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

    public static function reset(): void
    {
        ProjectPolicy::reset();
    }

    public static function url(string $path = ''): string
    {
        return '/'.self::PATH.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
