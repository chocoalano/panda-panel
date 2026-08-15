<?php

declare(strict_types=1);

namespace PandaPanel\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\TrashedRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deletes every selected record for good.
 *
 * The most destructive thing the panel can be asked to do, so it authorizes
 * `forceDeleteAny` first, then every record individually, and only then
 * writes — in one transaction, so a selection containing one forbidden record
 * destroys nothing.
 */
final class ForceDeleteBulkAction
{
    /**
     * @param  class-string<PanelResource>  $resource
     */
    public static function make(string $resource): Action
    {
        return Action::make('forceDelete')
            ->label('Delete selected permanently')
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: 'Delete the selected records permanently?',
                description: 'This cannot be undone and the records cannot be restored afterwards.',
                button: 'Delete permanently',
            )
            ->successMessage('Selected records permanently deleted.')
            ->authorize(static fn (?Model $record): bool => $record === null
                ? $resource::canForceDeleteAny()
                : $resource::canForceDelete($record))
            ->bulkAction(static function (Collection $records) use ($resource): void {
                foreach ($records as $record) {
                    if (! $resource::canForceDelete($record)) {
                        throw new HttpException(403, 'You may not permanently delete every selected record.');
                    }
                }

                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::forceDelete($record));
                });
            });
    }
}
