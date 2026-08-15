<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * Reports what the page told it, so a test can prove the context reaches a
 * widget rather than being assembled and dropped.
 */
final class ContextAwareStatsWidget extends StatsWidget
{
    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $record = $this->context()->record();

        return [
            Stat::make('Rows', $this->context()->count()),
            Stat::make('Record', $record === null ? 'none' : (string) $record->getKey()),
        ];
    }
}
