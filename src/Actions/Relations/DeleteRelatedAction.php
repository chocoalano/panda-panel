<?php

declare(strict_types=1);

namespace PandaPanel\Actions\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Resources\RelationManager;

/**
 * Deletes a related record.
 *
 * Distinct from detaching: this removes the record itself, so it is offered
 * where the related record has no life outside the relation. The confirmation
 * copy says which of the two is about to happen, because "remove" reads as
 * either one.
 */
final class DeleteRelatedAction
{
    /**
     * @param  class-string<RelationManager>  $manager
     */
    public static function make(string $manager, Model $owner): Action
    {
        return Action::make('delete')
            ->label('Delete')
            ->icon('trash-2')
            ->variant(ActionVariant::Destructive)
            ->requiresConfirmation(
                heading: 'Delete this record?',
                description: 'This removes the record itself, not just its link to this one.',
                button: 'Delete',
            )
            ->successMessage('Record deleted.')
            ->authorize(static fn (?Model $record): bool => $record !== null
                && $manager::canDelete($owner, $record))
            ->action(static function (Model $record): void {
                $record->delete();
            });
    }
}
