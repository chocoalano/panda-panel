<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Forms;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Components\Toggle;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;

final class UserForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        $isCreate = $schema->getPage() === 'create';

        return $schema
            ->columns(2)
            ->schema([
                Section::make('Personal Information')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Ada Lovelace'),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // Unique on create, unique-except-self on edit.
                            // Without the ignore, saving a record without
                            // changing its email would fail against itself.
                            ->rulesUsing(static fn (?Model $record): array => [
                                $record === null
                                    ? Rule::unique('users', 'email')
                                    : Rule::unique('users', 'email')->ignore($record->getKey()),
                            ]),

                        // The users table has no status column, so the
                        // verified timestamp is the honest thing to toggle.
                        // Toggling it on keeps an existing timestamp rather
                        // than resetting it on every save.
                        Toggle::make('verified')
                            ->label('Email verified')
                            ->helperText('Marks the address as verified without sending an email.')
                            ->columnSpan(2)
                            ->formatUsing(static fn (mixed $value, ?Model $record): bool => $record instanceof User
                                && $record->email_verified_at !== null)
                            ->dehydrateTo('email_verified_at')
                            ->mutateUsing(static function (mixed $value, ?Model $record): mixed {
                                if ($value !== true) {
                                    return null;
                                }

                                return $record instanceof User && $record->email_verified_at !== null
                                    ? $record->email_verified_at
                                    : Date::now();
                            }),

                        Toggle::make('is_admin')
                            ->label('Administrator')
                            ->helperText('Administrators can reach the Admin panel and manage users.')
                            ->columnSpan(2),
                    ]),

                Section::make('Security')
                    ->description(
                        $isCreate
                            ? 'Set the initial password for this account.'
                            : 'Leave the password blank to keep the current one.',
                    )
                    ->columns(2)
                    ->schema([
                        PasswordInput::make('password')
                            ->confirmed()
                            ->rules(['min:8'])
                            ->columnSpan(2)
                            // Required on create, optional on edit, and never
                            // written back as an empty string.
                            ->when(
                                $isCreate,
                                static fn (PasswordInput $field): PasswordInput => $field->required(),
                                static fn (PasswordInput $field): PasswordInput => $field->optionalWhenFilled(),
                            ),
                    ]),
            ]);
    }
}
