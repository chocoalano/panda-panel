<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Routing\PanelRouteRegistrar;

/**
 * A tenant-scoped panel, identified by a query parameter.
 *
 * A query parameter rather than a subdomain because what is being tested is
 * what the framework does *after* identification — resolve, authorize, bind,
 * scope. Identification itself is the application's, and every real
 * arrangement (a subdomain through `stancl/tenancy`, a path segment, one
 * tenant per user) hands the resolver the same thing: a model or null.
 */
final class TenancyPanel
{
    public const ID = 'tenancy-host';

    public const PATH = 'tenancy-host';

    /** Lets a test refuse access without changing the pivot. */
    public static bool $accessible = true;

    public static function boot(): Panel
    {
        TenancySchema::create();

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Workspace::class, DocumentPolicy::class);

        $manager = app(PanelManager::class);

        if (! $manager->has(self::ID)) {
            $panel = $manager->register(
                Panel::make(self::ID)
                    ->path(self::PATH)
                    ->settings(false)
                    ->tenant(
                        Workspace::class,
                        static fn (Request $request): ?Workspace => Workspace::query()
                            ->find($request->query('workspace')),
                    )
                    // The other half of the resolver: this fixture identifies
                    // by query parameter, so that is what its URLs carry.
                    ->tenantUrlUsing(static fn (Workspace $workspace, Panel $panel): string => '/'
                        .$panel->getPath().'/documents?workspace='.$workspace->getKey())
                    ->resources([
                        DocumentResource::class,
                        WorkspaceResource::class,
                        BrokenTenantResource::class,
                    ]),
            );

            app(PanelRouteRegistrar::class)->register($panel);

            Route::getRoutes()->refreshNameLookups();
        }

        $panel = $manager->get(self::ID);

        $manager->setCurrentPanel($panel);

        return $panel;
    }

    public static function reset(): void
    {
        self::$accessible = true;
    }

    public static function url(string $path = ''): string
    {
        return '/'.self::PATH.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
