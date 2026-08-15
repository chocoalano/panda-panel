<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\Enums\WidgetType;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * Minimal widget for registry coverage.
 *
 * Extends the real base class so the registry is exercised against the same
 * contract application widgets implement.
 */
final class StatsFixtureWidget extends StatsWidget
{
    public static function id(): string
    {
        return 'stats-fixture';
    }

    public static function type(): WidgetType
    {
        return WidgetType::Stats;
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [Stat::make('Fixture', 1)];
    }
}
