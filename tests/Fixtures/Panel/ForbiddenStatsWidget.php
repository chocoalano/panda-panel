<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;
use RuntimeException;

/**
 * Unauthorized. Its `stats()` throws, so if authorization ever ran after
 * data resolution the test would fail loudly rather than silently leaking a
 * count.
 */
final class ForbiddenStatsWidget extends StatsWidget
{
    protected static int $sort = 1;

    public static function canView(): bool
    {
        return false;
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        throw new RuntimeException('Data resolved for an unauthorized widget.');
    }
}
