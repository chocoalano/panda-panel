<?php

declare(strict_types=1);

namespace App\Panels\App\Widgets;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use PandaPanel\Widgets\Enums\StatColor;
use PandaPanel\Widgets\StatsWidget;
use PandaPanel\Widgets\Support\Stat;

/**
 * Facts about the signed-in user only.
 *
 * Scoped to `Auth::user()` rather than to the users table, which is what
 * makes the App dashboard a different thing from the Admin one even though
 * both are built from the same widget system.
 */
final class AccountSummary extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    /**
     * Hidden for a guest, which cannot normally happen behind the panel's
     * auth middleware but keeps the widget honest if it is ever reused.
     */
    public static function canView(): bool
    {
        return Auth::user() instanceof User;
    }

    /**
     * @return list<Stat>
     */
    public function stats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return [
            Stat::make('Signed in as', $user->name)->icon('user'),

            Stat::make('Email', $user->email_verified_at === null ? 'Unverified' : 'Verified')
                ->icon('mail')
                ->color($user->email_verified_at === null ? StatColor::Warning : StatColor::Success),

            Stat::make('Member since', $user->created_at?->format('M Y') ?? '—')
                ->icon('receipt'),
        ];
    }
}
