<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;

/**
 * An edit page whose subheading depends on the record, which a static
 * property cannot express.
 */
final class TitledEditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $heading = 'Account';

    public function getSubheading(?Model $record = null): ?string
    {
        return $record === null ? null : 'Editing '.$record->getAttribute('email');
    }
}
