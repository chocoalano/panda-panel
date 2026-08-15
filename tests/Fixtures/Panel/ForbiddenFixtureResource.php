<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Contracts\PanelContract;
use PandaPanel\Support\NavigationItem;
use RuntimeException;

/**
 * Unauthorized resource. Its badge closure throws, which proves the
 * authorization filter runs before badge evaluation: if the item ever
 * reached serialization the test would fail loudly instead of silently
 * leaking an entry.
 */
final class ForbiddenFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'secrets';
    }

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function navigationItem(PanelContract $panel): ?NavigationItem
    {
        return NavigationItem::make(
            label: 'Secrets',
            href: '/'.$panel->getPath().'/secrets',
            badge: static fn (): int => throw new RuntimeException('Badge evaluated for an unauthorized item.'),
            sort: 30,
            group: 'User Management',
        );
    }
}
