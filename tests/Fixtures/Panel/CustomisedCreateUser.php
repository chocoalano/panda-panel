<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * Overrides the three things a create page most often needs to change: where
 * it goes, what it says, and how the record is actually written.
 */
final class CustomisedCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $preservesDataOnCreateAnother = true;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordCreation(array $attributes): Model
    {
        $attributes['name'] = 'Written by the page';

        return parent::handleRecordCreation($attributes);
    }

    protected function getRedirectUrl(Model $record): string
    {
        return self::$resource::url();
    }

    /**
     * @return array{type: string, message: string}|null
     */
    protected function createdNotification(Model $record): ?array
    {
        return ['type' => 'info', 'message' => 'Welcome aboard.'];
    }
}
