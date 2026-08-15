<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

/**
 * An ungrouped page, which is where the dashboard lives. The ungrouped
 * bucket must always sort ahead of every labelled group.
 */
final class DashboardFixturePage implements PageContract
{
    public static function slug(): string
    {
        return 'overview';
    }

    public static function routePath(): string
    {
        return 'overview';
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Overview',
            href: '/'.$panel->getPath(),
            icon: 'layout-grid',
            sort: 0,
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
