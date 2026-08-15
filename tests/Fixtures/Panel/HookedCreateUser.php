<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * Records which hooks ran, in order, so the documented lifecycle is asserted
 * against real invocations rather than against the documentation.
 */
final class HookedCreateUser extends CreateRecord
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

        $data['name'] = 'Prefilled';

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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data, ?Model $record): array
    {
        self::$calls[] = 'mutateFormDataBeforeSave';

        $data['name'] = Str::upper((string) $data['name']);

        return $data;
    }

    protected function beforeSave(?Model $record): void
    {
        self::$calls[] = 'beforeSave';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function handleRecordCreation(array $attributes): Model
    {
        self::$calls[] = 'handleRecordCreation';

        return parent::handleRecordCreation($attributes);
    }

    protected function afterCreate(Model $record): void
    {
        self::$calls[] = 'afterCreate';
    }

    protected function afterSave(Model $record): void
    {
        self::$calls[] = 'afterSave';
    }
}
