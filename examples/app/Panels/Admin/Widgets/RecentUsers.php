<?php

declare(strict_types=1);

namespace App\Panels\Admin\Widgets;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\TableWidget;

final class RecentUsers extends TableWidget
{
    protected static int $sort = 20;

    protected static string $emptyMessage = 'No one has signed up yet.';

    protected static ?string $heading = 'Recent sign-ups';

    protected static ?string $description = 'The newest accounts, searchable and sortable.';

    public function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                DateTimeColumn::make('created_at')->label('Joined')->relative()->sortable(),
            ])
            ->defaultSort('created_at', SortDirection::Descending);
    }

    /**
     * Selects only the displayed columns, so a wide users table does not
     * become a wide payload.
     *
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->select(['id', 'name', 'email', 'created_at']);
    }
}
