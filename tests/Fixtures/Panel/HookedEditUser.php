<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;

final class HookedEditUser extends EditRecord
{
    /** @var list<string> */
    public static array $calls = [];

    protected static string $resource = UserResource::class;

    protected function beforeFill(): void
    {
        self::$calls[] = 'beforeFill';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        self::$calls[] = 'mutateFormDataBeforeFill';

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function afterFill(array $data): void
    {
        self::$calls[] = 'afterFill';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function beforeValidate(array $input): array
    {
        self::$calls[] = 'beforeValidate';

        return $input;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function afterValidate(array $data): array
    {
        self::$calls[] = 'afterValidate';

        return $data;
    }

    /**
     * Present so the test can prove the create-only hooks are not called on
     * update.
     */
    protected function beforeCreate(): void
    {
        self::$calls[] = 'beforeCreate';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        self::$calls[] = 'mutateFormDataBeforeCreate';

        return $data;
    }

    protected function afterCreate(Model $record): void
    {
        self::$calls[] = 'afterCreate';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
    {
        self::$calls[] = 'mutateFormDataBeforeSave';

        return $data;
    }

    protected function beforeSave(?Model $record): void
    {
        self::$calls[] = 'beforeSave';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordUpdate(Model $record, array $attributes): Model
    {
        self::$calls[] = 'handleRecordUpdate';

        return parent::handleRecordUpdate($record, $attributes);
    }

    protected function afterSave(Model $record): void
    {
        self::$calls[] = 'afterSave';
    }
}
