<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\Date;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * Three aggregates, three queries, plus one grouped query for the sparklines.
 *
 * Everything here counts in the database. Hydrating users to count them is
 * the usual way a dashboard becomes the slowest page in an application.
 */
final class UserStats extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    /**
     * A minute. These are counts of a table that changes when somebody signs
     * up, which is often enough to be worth watching and rare enough that a
     * shorter interval would be a request for nothing.
     */
    protected static ?int $pollingInterval = 60;

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $startOfMonth = Date::now()->startOfMonth();
        $trend = $this->signUpsPerMonth();

        return [
            Stat::make('Total users', User::query()->count())
                ->color(StatColor::Info)
                ->icon('users')
                // The whole point of a stat that links: the number and the
                // list it counts are the same thing said two ways.
                ->url(UserResource::url()),

            Stat::make('Verified', User::query()->whereNotNull('email_verified_at')->count())
                ->icon('shield')
                ->color(StatColor::Success)
                ->description('Confirmed email address'),

            Stat::make('New this month', User::query()->where('created_at', '>=', $startOfMonth)->count())
                ->icon('user')
                ->color(StatColor::Info)
                ->description($startOfMonth->format('F Y'))
                // A single number says nothing about whether this is a good
                // month; six of them do.
                ->chart($trend),
        ];
    }

    /**
     * Sign-ups for the last six months, oldest first.
     *
     * One query rather than six: a sparkline is decoration, and decoration
     * that costs six round trips is not worth having.
     *
     * @return list<int>
     */
    private function signUpsPerMonth(): array
    {
        $start = Date::now()->startOfMonth()->subMonths(5);

        $rows = User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(static fn (User $user): string => $user->created_at?->format('Y-m') ?? '')
            ->map->count();

        $counts = [];

        for ($offset = 0; $offset < 6; $offset++) {
            $key = $start->copy()->addMonths($offset)->format('Y-m');

            $counts[] = (int) ($rows[$key] ?? 0);
        }

        return $counts;
    }
}
