<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Detaches every selected record.
 *
 * Every record is authorized before any pivot row is removed, and the whole
 * set runs in one transaction: a selection containing one forbidden record
 * detaches nothing rather than detaching the permitted ones and failing
 * halfway.
 */
final class DetachBulkAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('detach')
            ->label('Detach selected')
            ->icon('unlink')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: 'Detach the selected records?',
                description: 'The records themselves are kept; only the links to them are removed.',
                button: 'Detach',
            )
            ->successMessage('Selected records detached.')
            ->visible(static fn (): bool => $manager::isManyToMany($owner))
            ->authorize(static fn (?Model $record): bool => $record === null
                ? $manager::canAttach($owner)
                : $manager::canDetach($owner, $record))
            ->bulkAction(static function (Collection $records) use ($manager, $owner): void {
                foreach ($records as $record) {
                    if (! $manager::canDetach($owner, $record)) {
                        throw new HttpException(403, 'You may not detach every selected record.');
                    }
                }

                $relation = $manager::relation($owner);

                if (! $relation instanceof BelongsToMany) {
                    return;
                }

                // Explicitly transactional whatever the panel says: "all or
                // nothing" is what this action authorized for, not a default
                // it inherits.
                DB::transaction(static function () use ($relation, $records): void {
                    $relation->detach($records->modelKeys());
                });
            });
    }
}
