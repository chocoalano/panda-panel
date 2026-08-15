<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Clusters\Cluster;
use PandaPanel\Contracts\PageContract;
use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;

/**
 * A standalone page in an undeclared group, so undeclared-group ordering is
 * observable alongside the declared ones.
 */
final class SettingsFixturePage implements PageContract
{
    public static function slug(): string
    {
        return 'settings';
    }

    public static function routePath(): string
    {
        return 'settings';
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Settings',
            href: '/'.$panel->getPath().'/settings',
            icon: 'settings',
            sort: 100,
            group: 'System',
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
