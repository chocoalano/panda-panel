<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * A create page whose submit button says what saving it does.
 *
 * "Process schedule" rather than "Create schedule" is the page's own statement
 * about itself. Before this it took a `Wizard` or a custom page to change one
 * word, which is a lot of machinery to leave the standard lifecycle behind
 * for.
 */
final class LabelledCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $submitLabel = 'Process schedule';
}
