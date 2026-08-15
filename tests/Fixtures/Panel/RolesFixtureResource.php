<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

/**
 * Shares a group with the users fixture and sorts after it, so intra-group
 * ordering is observable.
 */
final class RolesFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'roles';
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Roles',
            href: '/'.$panel->getPath().'/roles',
            icon: 'shield',
            sort: 20,
            group: 'User Management',
        );
    }
}
