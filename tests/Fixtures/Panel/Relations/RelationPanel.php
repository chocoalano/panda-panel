<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

/**
 * A panel of its own for the relation fixtures.
 *
 * The admin panel is already registered and registering it twice is an error
 * by design, so anything needing real routes builds its own — the same thing
 * the reorder tests do.
 */
final class RelationPanel
{
    public const ID = 'relation-host';

    public const PATH = 'relation-host';

    public static function boot(): Panel
    {
        RelationSchema::create();

        // `Gate::policy()`, not `Gate::define()`: a registered policy cannot
        // be overridden by a definition, and these fixtures switch abilities
        // on and off through the policy classes.
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Label::class, LabelPolicy::class);

        $manager = app(PanelManager::class);

        if (! $manager->has(self::ID)) {
            $panel = $manager->register(
                Panel::make(self::ID)
                    ->path(self::PATH)
                    ->settings(false)
                    ->resources([
                        ProjectRelationResource::class,
                        NestedTaskResource::class,
                        TaskSoftDeleteResource::class,
                        EditableTaskResource::class,
                    ]),
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
        TaskPolicy::reset();
        LabelPolicy::reset();
    }

    public static function url(string $path = ''): string
    {
        return '/'.self::PATH.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
