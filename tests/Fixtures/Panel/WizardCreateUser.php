<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Step;
use PandaPanel\Forms\Layouts\Wizard;
use PandaPanel\Resources\Pages\CreateRecord;

/**
 * The same fields as the ordinary create page, arranged as a wizard, so a
 * test can prove that stepping changes nothing the server does.
 */
final class WizardCreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function schema(): FormSchema
    {
        return FormSchema::make()
            ->model(UserResource::getModel())
            ->forPage('create')
            ->schema([
                Wizard::make([
                    Step::make('Identity')
                        ->description('Who they are')
                        ->icon('user')
                        ->schema([
                            TextInput::make('name')->required(),
                            TextInput::make('email')->email()->required(),
                        ]),
                    Step::make('Access')->schema([
                        PasswordInput::make('password')->confirmed()->required(),
                        Toggle::make('is_admin'),
                    ]),
                ])->submitLabel('Create user'),
            ]);
    }
}
