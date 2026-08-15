<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class CountingStatsWidget extends StatsWidget
{
    protected static int $sort = 5;

    protected static int|string|array $columnSpan = ['default' => 1, 'lg' => 2];

    public static int $dataCalls = 0;

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        self::$dataCalls++;

        return [Stat::make('Answer', 42)];
    }
}
