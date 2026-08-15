<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Scoped;

use PandaPanel\Resources\Pages\EditRecord;
use Tests\Fixtures\Panel\ScopedUserResource;

final class EditScopedUser extends EditRecord
{
    protected static string $resource = ScopedUserResource::class;
}
