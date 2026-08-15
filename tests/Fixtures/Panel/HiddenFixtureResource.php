<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

/**
 * Authorized but opts out of navigation by returning no item, which is how a
 * resource keeps its routes while staying off the sidebar.
 */
final class HiddenFixtureResource extends FixtureResource
{
    public static function slug(): string
    {
        return 'audit-logs';
    }
}
