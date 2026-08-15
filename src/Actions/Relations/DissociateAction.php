<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;

/**
 * Removes a child from a one-to-many relation by nulling its foreign key.
 *
 * The record survives; it simply belongs to nobody. Only offered where the
 * foreign key is nullable, because on a non-nullable column the write fails
 * at the database and the honest operation is a delete.
 */
final class DissociateAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('dissociate')
            ->label('Dissociate')
            ->icon('unlink')
            ->variant(ActionVariant::Ghost)
            ->requiresConfirmation(
                heading: 'Dissociate this record?',
                description: 'The record is kept but no longer belongs to this one.',
                button: 'Dissociate',
            )
            ->successMessage('Record dissociated.')
            ->visible(static fn (): bool => $manager::isOneToMany($owner))
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canDissociate($owner, $record))
            ->action(static function (Model $record) use ($manager, $owner): void {
                $relation = $manager::relation($owner);

                if (! $relation instanceof HasOneOrMany) {
                    return;
                }

                $foreignKey = $relation->getForeignKeyName();

                $record->setAttribute($foreignKey, null)->save();
            });
    }
}
