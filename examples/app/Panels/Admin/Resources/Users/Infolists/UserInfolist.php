<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Infolists;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\CodeEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\IconEntry;
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Grid;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Infolists\Layouts\Tab;
use PandaPanel\Infolists\Layouts\Tabs;
use PandaPanel\Tables\Enums\BadgeColor;

/**
 * What the view page shows for a user.
 *
 * The password is absent rather than masked: an infolist that never reads it
 * cannot leak it, which a form-derived view could only promise by filtering.
 */
final class UserInfolist
{
    public static function configure(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->columns(2)
            ->actions([
                Action::make('note')
                    ->label('Add a note')
                    ->icon('pencil')
                    ->variant(ActionVariant::Outline)
                    ->modalHeading('Note about this account')
                    ->modalSubmitLabel('Save note')
                    ->modalWidth(ModalWidth::Large)
                    ->slideOver()
                    ->successMessage('Note saved.')
                    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
                        Textarea::make('note')
                            ->label('Note')
                            ->rows(6)
                            ->required()
                            ->maxLength(1000),
                    ]))
                    ->authorize(static fn (?Model $record): bool => $record !== null
                        && self::actorIsAdmin())
                    // The note is not a column on `users`; it is written to
                    // the log, which is where an audit trail belongs.
                    ->action(static function (Model $record, array $data): void {
                        logger()->info('Panel note', [
                            'user' => $record->getKey(),
                            'note' => $data['note'] ?? '',
                            'by' => auth()->id(),
                        ]);
                    }),
            ])
            ->schema([
                Tabs::make([
                    Tab::make('Account')
                        ->icon('user')
                        ->columns(2)
                        ->schema([
                            Section::make('Identity')
                                ->columns(2)
                                ->headerActions([
                                    Action::make('resendVerification')
                                        ->label('Resend verification')
                                        ->icon('mail')
                                        ->variant(ActionVariant::Ghost)
                                        ->requiresConfirmation(
                                            heading: 'Send a verification email?',
                                            description: 'The account will be asked to confirm its address again.',
                                            button: 'Send it',
                                        )
                                        ->successMessage('Verification email sent.')
                                        ->visible(static fn (?Model $record): bool => $record !== null
                                            && $record->getAttribute('email_verified_at') === null)
                                        ->authorize(static fn (?Model $record): bool => self::actorIsAdmin())
                                        ->action(static function (Model $record): void {
                                            if ($record instanceof User) {
                                                $record->sendEmailVerificationNotification();
                                            }
                                        }),
                                ])
                                ->schema([
                                    TextEntry::make('name'),
                                    TextEntry::make('email'),
                                    BadgeEntry::make('role')
                                        ->label('Role')
                                        ->formatUsing(static fn (mixed $value, Model $record): string => $record instanceof User && $record->is_admin
                                            ? 'Administrator'
                                            : 'Member')
                                        ->colors(['Administrator' => BadgeColor::Info]),
                                    BooleanEntry::make('email_verified_at')
                                        ->label('Email verified')
                                        ->labels('Verified', 'Unverified'),
                                ]),

                            Section::make('Activity')
                                ->columns(2)
                                ->schema([
                                    DateTimeEntry::make('created_at')->label('Joined'),
                                    DateTimeEntry::make('updated_at')->label('Last updated')->since(),
                                    IconEntry::make('two_factor_confirmed_at')
                                        ->label('Two-factor')
                                        ->formatUsing(static fn (mixed $value): string => $value === null ? 'off' : 'on')
                                        ->icons(['on' => 'shield', 'off' => 'x'])
                                        ->colors([
                                            'on' => BadgeColor::Success,
                                            'off' => BadgeColor::Neutral,
                                        ]),
                                ]),
                        ]),

                    Tab::make('Security')
                        ->icon('shield')
                        ->schema([
                            Section::make('Passkeys')
                                ->description('Devices this account can sign in with.')
                                ->schema([
                                    RepeatableEntry::make('passkeys')
                                        ->label('Registered')
                                        ->itemLabel('Passkey')
                                        ->placeholder('No passkeys registered.')
                                        ->schema([
                                            Grid::make(3)->schema([
                                                TextEntry::make('name'),
                                                DateTimeEntry::make('created_at')->label('Added'),
                                                DateTimeEntry::make('last_used_at')
                                                    ->label('Last used')
                                                    ->since()
                                                    ->placeholder('Never'),
                                            ]),
                                        ]),
                                ]),

                            Section::make('Diagnostics')
                                ->schema([
                                    CodeEntry::make('summary')
                                        ->label('Account summary')
                                        ->copyable()
                                        ->formatUsing(static fn (mixed $value, Model $record): array => [
                                            'id' => $record->getKey(),
                                            'admin' => (bool) $record->getAttribute('is_admin'),
                                            'verified' => $record->getAttribute('email_verified_at') !== null,
                                            'age_days' => self::ageInDays($record),
                                        ]),
                                ]),
                        ]),
                ])->persistTab(),
            ]);
    }

    private static function actorIsAdmin(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->is_admin;
    }

    private static function ageInDays(Model $record): int
    {
        $created = $record->getAttribute('created_at');

        return $created === null ? 0 : (int) Date::now()->diffInDays($created, absolute: true);
    }
}
