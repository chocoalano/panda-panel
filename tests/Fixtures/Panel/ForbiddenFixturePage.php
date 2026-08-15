<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

/**
 * Unauthorized page. It is the only member of its group, so the group must
 * disappear entirely rather than render as an empty heading.
 */
final class ForbiddenFixturePage implements PageContract
{
    public static function slug(): string
    {
        return 'billing';
    }

    public static function routePath(): string
    {
        return 'billing';
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Billing',
            href: '/'.$panel->getPath().'/billing',
            sort: 10,
            group: 'Finance',
        );
    }

    /**
     * @return class-string<Cluster>|null
     */
    public static function cluster(): ?string
    {
        return null;
    }
}
