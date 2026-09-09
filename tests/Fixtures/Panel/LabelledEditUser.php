<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Resources\Pages\EditRecord;

/**
 * The edit half of `LabelledCreateUser`, resolved through the method rather
 * than the property — so a label that needs the locale, the record, or
 * anything else only the page knows has somewhere to be computed.
 */
final class LabelledEditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getSubmitLabel(): ?string
    {
        return __('Save and continue');
    }
}
