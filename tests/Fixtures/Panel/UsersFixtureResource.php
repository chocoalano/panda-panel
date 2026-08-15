<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

/**
 * A grouped, badged resource. The badge is a closure so the builder's
 * closure resolution is exercised by a real item.
 */
final class UsersFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'users';
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Users',
            href: '/'.$panel->getPath().'/users',
            icon: 'users',
            badge: static fn (): int => 7,
            sort: 10,
            group: 'User Management',
        );
    }
}
