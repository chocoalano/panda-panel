<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

final class FilteredStatsWidget extends StatsWidget
{
    public function filterSchema(): ?FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('scope')
                ->options([
                    'all' => 'All',
                    'active' => 'Active',
                ])
                ->default('all'),
        ]);
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        return [
            Stat::make('Scope', (string) $this->filter('scope', 'all')),
        ];
    }
}
