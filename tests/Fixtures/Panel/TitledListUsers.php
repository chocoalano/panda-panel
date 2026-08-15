<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\ListRecords;

/**
 * A list page that names itself rather than taking the resource's label.
 */
final class TitledListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Team directory';

    protected static ?string $subheading = 'Everyone with an account.';
}
