<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Support\TrashedRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Deletes every selected related record for good.
 */
final class ForceDeleteBulkAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
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
            ->visible(static fn (): bool => $manager::usesSoftDeletes($owner))
            ->authorize(static fn (?Model $record): bool => $record === null
                || $manager::canForceDelete($owner, $record))
            ->bulkAction(static function (Collection $records) use ($manager, $owner): void {
                foreach ($records as $record) {
                    if (! $manager::canForceDelete($owner, $record)) {
                        throw new HttpException(403, 'You may not permanently delete every selected record.');
                    }
                }

                DB::transaction(static function () use ($records): void {
                    $records->each(static fn (Model $record) => TrashedRecord::forceDelete($record));
                });
            });
    }
}
