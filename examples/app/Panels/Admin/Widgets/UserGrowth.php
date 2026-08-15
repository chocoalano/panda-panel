<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Widgets\ChartWidget;
use PandaPanel\Widgets\Enums\ChartVariant;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\Support\ChartOptions;
use PandaPanel\Widgets\Support\ChartSeries;

/**
 * Sign-ups per month, over a window the reader chooses.
 *
 * Marked lazy so this grouped aggregate loads after the first paint instead
 * of holding the whole dashboard behind it.
 */
final class UserGrowth extends ChartWidget
{
    protected static int $sort = 30;

    protected static bool $lazy = true;

    protected static ChartVariant $variant = ChartVariant::Area;

    protected static ?string $heading = 'Sign-ups';

    protected static ?string $description = 'New accounts per month.';

    protected static int $maxHeight = 200;

    /** @var list<string> */
    private array $labels = [];

    /**
     * A window rather than a fixed six months: what "recently" means depends
     * on who is asking, and the answer belongs in the URL where it can be
     * linked to.
     */
    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('months')
                ->label('Window')
                ->options([
                    '6' => 'Last 6 months',
                    '12' => 'Last 12 months',
                    '24' => 'Last 24 months',
                ])
                ->default('6'),
        ]);
    }

    public function options(): ChartOptions
    {
        return ChartOptions::make()->legend(false)->curved()->filled();
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $this->build();

        return $this->labels;
    }

    /**
     * @return list<ChartSeries>
     */
    public function series(): array
    {
        $counts = $this->build();

        return [
            ChartSeries::make('Sign-ups', array_values($counts))
                ->color(StatColor::Info),
        ];
    }

    /**
     * One grouped query rather than one per month.
     *
     * @return array<string, int>
     */
    private function build(): array
    {
        $months = max(1, min(24, (int) $this->filter('months', 6)));
        $start = Date::now()->startOfMonth()->subMonths($months - 1);

        $rows = User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(static fn (User $user): string => $user->created_at?->format('Y-m') ?? '')
            ->map->count();

        $labels = [];
        $counts = [];

        for ($offset = 0; $offset < $months; $offset++) {
            $month = $start->copy()->addMonths($offset);
            $key = $month->format('Y-m');

            $labels[] = $month->format('M');
            $counts[$key] = (int) ($rows[$key] ?? 0);
        }

        $this->labels = $labels;

        return $counts;
    }
}
