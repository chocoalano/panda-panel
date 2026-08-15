<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use App\Panels\Admin\Widgets\RecentUsers;
use App\Panels\Admin\Widgets\UserGrowth;
use App\Panels\Admin\Widgets\UserStats;
use BackedEnum;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Pages\Dashboard;
use PandaPanel\Widgets\Widget;

/**
 * A second dashboard, about accounts specifically.
 *
 * A dashboard rather than a filter on the first one: "how are accounts doing"
 * and "is the system healthy" are different questions read by different
 * people, and one page that answers both with a dropdown answers neither
 * well.
 *
 * It names its widgets rather than taking the panel's, which is the other
 * half of what makes more than one dashboard useful.
 */
final class AccountsDashboard extends Dashboard
{
    protected static ?string $title = 'Accounts';

    protected static ?string $slug = 'accounts';

    protected static ?string $navigationIcon = 'users';

    protected static string|BackedEnum|null $navigationGroup = 'User Management';

    protected static ?string $subheading = 'Sign-ups and verification at a glance.';

    /**
     * One control every widget on this page reads.
     */
    public function filterSchema(): FormSchema
    {
        return FormSchema::make()->schema([
            Select::make('period')
                ->label('Period')
                ->options([
                    'month' => 'This month',
                    'quarter' => 'This quarter',
                    'year' => 'This year',
                ])
                ->default('month'),
        ]);
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function widgets(): array
    {
        return [
            UserStats::class,
            UserGrowth::class,
            RecentUsers::class,
        ];
    }
}
