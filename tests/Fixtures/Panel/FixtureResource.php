<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Contracts\ResourceContract;
use PandaPanel\Support\NavigationItem;

/**
 * Base for the navigation and registry fixtures.
 *
 * The real Resource base class arrives with the resource phase. These
 * fixtures exist so the registries and the navigation builder can be tested
 * against the contract now, rather than left unproven until then.
 */
abstract class FixtureResource implements ResourceContract
{
    /**
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function query(): Builder
    {
        return User::query();
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [];
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return null;
    }

    /**
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string
    {
        return null;
    }
}
