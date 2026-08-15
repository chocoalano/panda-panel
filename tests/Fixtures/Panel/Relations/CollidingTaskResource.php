<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

/**
 * A nested resource whose slug puts it on the same path as
 * `ProjectRelationResource`'s own `{record}/tasks` relation page.
 *
 * `{record}` and `{parentRecord}` are different names for the same wildcard
 * segment, so the router would match whichever registered first and silently
 * ignore the other. Registration must refuse this rather than ship a page
 * nobody can reach.
 */
final class CollidingTaskResource extends NestedTaskResource
{
    protected static ?string $slug = 'tasks';
}
