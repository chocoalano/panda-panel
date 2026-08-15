<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class LazyStatsWidget extends StatsWidget
{
    protected static int $sort = 50;

    protected static bool $lazy = true;

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [Stat::make('Slow', 7)];
    }
}
