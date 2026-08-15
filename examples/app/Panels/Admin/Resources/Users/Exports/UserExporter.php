<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

/**
 * The user list as a spreadsheet.
 *
 * The password is not a column and never will be: an export is a copy of
 * records that leaves the application, and a hash is the one attribute whose
 * value is that nobody has it.
 */
final class UserExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
            ExportColumn::make('is_admin')
                ->label('Administrator')
                ->formatUsing(static fn (mixed $value): string => $value ? 'Yes' : 'No'),
            ExportColumn::make('email_verified_at')->label('Verified at'),
            ExportColumn::make('created_at')->label('Joined'),
            // Offered but unticked: useful occasionally, noise the rest of
            // the time.
            ExportColumn::make('updated_at')
                ->label('Last updated')
                ->enabledByDefault(false),
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function query(Builder $query): Builder
    {
        // Stable regardless of how the list was sorted, so two exports of the
        // same records can be compared line by line.
        return $query->reorder('id');
    }

    public static function fileName(): string
    {
        return 'users-'.date('Y-m-d');
    }
}
