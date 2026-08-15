<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use PandaPanel\Resources\Pages\ManageRelatedRecords;

/**
 * The same manager the record pages show inline, given a page of its own.
 */
final class ManageProjectTasks extends ManageRelatedRecords
{
    protected static string $resource = ProjectRelationResource::class;

    protected static string $relationManager = TasksRelationManager::class;
}
