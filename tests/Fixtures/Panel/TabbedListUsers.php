<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Resources\Pages\ListRecords;
use PandaPanel\Tables\Tab;

/**
 * Three tabs, one of which narrows the resource query, so a test can prove a
 * tab scopes what the page shows rather than filtering after the fact.
 */
final class TabbedListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<string, Tab>
     */
    public function tabs(): array
    {
        return [
            'all' => Tab::make('all')->badge(static fn (): int => User::query()->count()),
            'admins' => Tab::make('admins', 'Administrators')
                ->icon('shield')
                ->query(static fn (Builder $query): Builder => $query->where('is_admin', true)),
            'members' => Tab::make('members')
                ->query(static fn (Builder $query): Builder => $query->where('is_admin', false)),
        ];
    }
}
