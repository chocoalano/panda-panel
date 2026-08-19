<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Tables;

use App\Models\User;
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Actions\ImportAction;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Actions\ViewAction;
use PandaPanel\Forms\Components\DatePicker;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\CardLayout;
use PandaPanel\Tables\Columns\BadgeColumn;
use PandaPanel\Tables\Columns\Column;
use PandaPanel\Tables\Columns\CustomColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\IconColumn;
use PandaPanel\Tables\Columns\ImageColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\TextInputColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\Enums\RecordActionsPosition;
use PandaPanel\Tables\Enums\SortDirection;
use PandaPanel\Tables\Filters\Constraints\BooleanConstraint;
use PandaPanel\Tables\Filters\Constraints\DateConstraint;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\DateFilter;
use PandaPanel\Tables\Filters\Filter;
use PandaPanel\Tables\Filters\FormFilter;
use PandaPanel\Tables\Filters\QueryBuilderFilter;
use PandaPanel\Tables\Filters\TernaryFilter;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Summaries\Count;
use PandaPanel\Tables\Summaries\Range;
use PandaPanel\Tables\Summaries\Sum;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns(self::columns())
            ->filters(self::filters())
            ->groups([
                Group::make('is_admin')
                    ->label('Role')
                    ->titleUsing(static fn (Model $record): string => $record->getAttribute('is_admin')
                        ? 'Administrators'
                        : 'Members'),

                Group::make('email_verified_at')
                    ->label('Verification')
                    ->titleUsing(static fn (Model $record): string => $record->getAttribute('email_verified_at') === null
                        ? 'Unverified'
                        : 'Verified'),
            ])
            ->headerActions(self::headerActions())
            ->toolbarActions([
                Action::make('purgeUnverified')
                    ->label('Purge unverified')
                    ->icon('trash-2')
                    ->variant(ActionVariant::Ghost)
                    ->requiresConfirmation(
                        heading: 'Delete every unverified account?',
                        description: 'Accounts that never confirmed their email address are removed. This cannot be undone.',
                        button: 'Delete them',
                    )
                    ->authorize(static fn (): bool => self::actorIsAdmin())
                    ->successMessageUsing(static fn (int $count): string => 'Unverified accounts removed.')
                    ->tableAction(static function (): void {
                        User::query()
                            ->whereNull('email_verified_at')
                            ->whereKeyNot(auth()->id())
                            ->delete();
                    }),
            ])
            ->recordActions([
                ViewAction::make(UserResource::class)->icon('info'),
                EditAction::make(UserResource::class)->icon('pencil'),
                // The copy keeps neither the address nor the verification: an
                // email is unique, and a duplicated verification would mark an
                // account confirmed that never confirmed anything.
                ReplicateAction::make(
                    UserResource::class,
                    except: ['email', 'email_verified_at'],
                    using: static function (Model $copy, Model $original): void {
                        $copy->forceFill([
                            'name' => $original->getAttribute('name').' (copy)',
                            'email' => 'copy-'.Str::random(8).'@example.test',
                        ]);
                    },
                ),
                DeleteAction::make(UserResource::class)->icon('trash'),
            ])
            ->recordActionsPosition(RecordActionsPosition::AfterColumns)
            ->recordActionsLabel('Manage')
            ->bulkActions([
                DeleteBulkAction::make(UserResource::class),

                // The selection as a spreadsheet, through the same dialog the
                // header's export uses.
                ExportAction::bulk(UserExporter::class, UserResource::class),

                Action::make('verify')
                    ->label('Mark as verified')
                    ->icon('check')
                    ->variant(ActionVariant::Outline)
                    // Asked for every record before any is written, so a
                    // selection containing one refused account changes nothing.
                    ->authorizeEachUsing(static fn (Model $record): bool => self::actorIsAdmin())
                    ->authorize(static fn (?Model $record): bool => self::actorIsAdmin())
                    ->successMessageUsing(static fn (int $count): string => $count === 1
                        ? '1 account marked as verified.'
                        : "{$count} accounts marked as verified.")
                    ->bulkAction(static function (Collection $records): void {
                        $records
                            ->filter(static fn (Model $record): bool => $record->getAttribute('email_verified_at') === null)
                            ->each(static fn (Model $record) => $record
                                ->forceFill(['email_verified_at' => now()])
                                ->save());
                    }),
            ])
            ->defaultSort('created_at', SortDirection::Descending)
            ->defaultSortOptionLabel('newest first')
            ->searchPlaceholder('Search by name, email, or passkey...')
            ->searchDebounce(400)
            // Two words narrow rather than having to appear together, so
            // "ada admin" finds the administrator called Ada.
            ->splitSearchTerms()
            ->persistSearchInSession()
            ->persistSortInSession()
            ->persistFiltersInSession()
            ->persistColumnsInSession()
            // The query builder can take several rules; running the table on
            // every keystroke of a half-built condition is wasted work.
            ->deferFilters()
            ->filtersApplyLabel('Apply filters')
            ->filtersResetLabel('Clear all')
            ->reorderableColumns()
            ->columnManagerInModal()
            ->columnManagerTrigger('Columns', 'settings')
            // A user is a person, so a card reads better than a row of
            // figures. Declared rather than inferred here because the
            // inference would take `email` as a detail row and the avatar as
            // the picture, and a directory wants the address under the name.
            ->cards(
                CardLayout::make()
                    ->image('avatar')
                    ->title('name')
                    ->description('email')
                    ->badges(['email_verified_at', 'is_admin'])
                    ->details(['passkeys_count', 'created_at']),
            )
            ->emptyStateActions(self::headerActions())
            ->emptyState(
                heading: 'No users match this view',
                description: 'Adjust the search or filters, or add a new user.',
                icon: 'users',
            );
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            ImageColumn::make('avatar')
                ->label('')
                ->circular()
                ->width('3.5rem')
                ->toggleable(false),

            // Editable in place. The policy lets a user edit their own
            // account, which is exactly right for a display name.
            TextInputColumn::make('name')
                ->maxLength(255)
                ->rules(['required'])
                ->searchable(individually: true)
                ->sortable()
                ->width('14rem')
                ->toggleable(false)
                ->tooltip(static fn (Model $record): string => 'Joined '
                    .self::asDate($record->getAttribute('created_at'))?->diffForHumans()),

            TextColumn::make('email')
                ->searchable(individually: true)
                ->sortable()
                ->headerTooltip('The address sign-in and notifications use')
                ->url(static fn (Model $record): string => 'mailto:'.$record->getAttribute('email'))
                ->extraAttributes(static fn (Model $record): array => [
                    'data-user' => (string) $record->getKey(),
                ]),

            // Clicking the badge verifies the account, which is the thing an
            // administrator actually wants to do from this column.
            BadgeColumn::make('email_verified_at')
                ->label('Status')
                ->formatUsing(static fn (mixed $value): string => $value === null ? 'unverified' : 'verified')
                ->labels(['verified' => 'Verified', 'unverified' => 'Unverified'])
                ->colors([
                    'verified' => BadgeColor::Success,
                    'unverified' => BadgeColor::Warning,
                ])
                ->sortable()
                ->action(
                    Action::make('verifyOne')
                        ->label('Mark as verified')
                        ->successMessage('Account marked as verified.')
                        ->visible(static fn (?Model $record): bool => $record !== null
                            && $record->getAttribute('email_verified_at') === null)
                        ->authorize(static fn (?Model $record): bool => self::actorIsAdmin())
                        ->action(static function (Model $record): void {
                            $record->forceFill(['email_verified_at' => now()])->save();
                        }),
                ),

            IconColumn::make('two_factor_confirmed_at')
                ->label('2FA')
                ->headerTooltip('Whether two-factor authentication is confirmed')
                ->boolean(trueIcon: 'shield', falseIcon: 'shield-off')
                ->alignment(Alignment::Center)
                ->width('5rem'),

            // A privilege flag, so it is editable only by an administrator
            // and never on their own account: the policy permits self-edit,
            // which without this guard would be a way to grant yourself
            // administrator rights. The endpoint re-checks the same rule.
            ToggleColumn::make('is_admin')
                ->label('Admin')
                ->alignment(Alignment::Center)
                ->width('5rem')
                ->disabledUsing(static function (Model $record): bool {
                    $actor = auth()->user();

                    return ! self::actorIsAdmin()
                        || ($actor instanceof User && $actor->is($record));
                }),

            NumberColumn::make('passkeys_count')
                ->label('Passkeys')
                ->counts('passkeys')
                ->sortable()
                ->alignment(Alignment::End)
                ->width('6rem')
                ->summarize([Sum::make()->label('Total'), Count::make()->label('Accounts')]),

            // A relation read as a column: the names are joined on the server
            // and the search reaches them with `whereHas`.
            TextColumn::make('passkeys.name')
                ->label('Passkey names')
                ->searchable()
                ->visible(false)
                ->placeholder('None registered')
                ->formatUsing(static function (mixed $value): ?string {
                    $names = $value instanceof \Illuminate\Support\Collection
                        ? $value->all()
                        : (is_array($value) ? $value : []);

                    return $names === [] ? null : implode(', ', array_map(strval(...), $names));
                }),

            CustomColumn::make('accountAge')
                ->label('Account age')
                ->component('Panels/Admin/Columns/AccountAge')
                ->width('9rem')
                ->state(static function (Model $record): array {
                    $created = self::asDate($record->getAttribute('created_at'));

                    return [
                        'days' => $created === null ? 0 : (int) $created->diffInDays(now()),
                        'label' => $created?->diffForHumans() ?? 'Unknown',
                    ];
                }),

            DateTimeColumn::make('created_at')
                ->label('Registered')
                ->sortable()
                ->placeholder('Unknown')
                ->summarize([Range::make()->label('Between')]),

            // An ordering the schema cannot express as a column name:
            // unverified accounts first, then oldest.
            TextColumn::make('attention')
                ->label('Needs attention')
                ->visible(false)
                ->alignment(Alignment::Center)
                ->formatUsing(static fn (mixed $value, Model $record): string => $record->getAttribute('email_verified_at') === null
                    ? 'Unverified'
                    : '—')
                ->sortUsing(static function (Builder $query, SortDirection $direction): void {
                    $query
                        ->orderByRaw('email_verified_at is null '.($direction === SortDirection::Ascending ? 'asc' : 'desc'))
                        ->orderBy('created_at');
                }),
        ];
    }

    /**
     * @return list<Filter>
     */
    private static function filters(): array
    {
        return [
            TernaryFilter::make('verified')
                ->label('Email verification')
                ->column('email_verified_at')
                ->nullable()
                ->labels('Verified', 'Unverified', 'Anyone'),

            TernaryFilter::make('is_admin')
                ->label('Role')
                ->labels('Administrators', 'Members', 'Anyone'),

            TernaryFilter::make('twoFactor')
                ->label('Two-factor')
                ->column('two_factor_confirmed_at')
                ->nullable()
                ->labels('Enabled', 'Disabled', 'Anyone'),

            DateFilter::make('registered')
                ->label('Registered between')
                ->column('created_at'),

            // More than one answer, so it is a form rather than a control.
            FormFilter::make('passkeyActivity')
                ->label('Passkey activity')
                ->form(static fn (FormSchema $schema): FormSchema => $schema->schema([
                    Select::make('has')
                        ->label('Passkeys')
                        ->options(['yes' => 'Has passkeys', 'no' => 'None registered']),
                    DatePicker::make('usedSince')->label('Used since'),
                ]))
                ->query(static function (Builder $query, mixed $data): void {
                    $has = $data['has'] ?? null;
                    $since = $data['usedSince'] ?? null;

                    if ($has === 'yes') {
                        $query->whereHas('passkeys');
                    }

                    if ($has === 'no') {
                        $query->whereDoesntHave('passkeys');
                    }

                    if (is_string($since) && $since !== '') {
                        $query->whereHas(
                            'passkeys',
                            static fn (Builder $passkeys) => $passkeys->whereDate('last_used_at', '>=', $since),
                        );
                    }
                }),

            QueryBuilderFilter::make('conditions')
                ->label('Advanced')
                ->maxRules(5)
                ->constraints([
                    TextConstraint::make('name'),
                    TextConstraint::make('email'),
                    DateConstraint::make('created_at')->label('Registered'),
                    DateConstraint::make('email_verified_at')->label('Verified at'),
                    BooleanConstraint::make('is_admin')->label('Administrator'),
                ]),
        ];
    }

    /**
     * The same actions the header and the empty state both offer — an empty
     * table is where "add a user" is most useful and hardest to find.
     *
     * @return list<Action>
     */
    private static function headerActions(): array
    {
        return [
            // The full form in a dialog, so adding one user does not cost a
            // page. The create page is still there for anybody who wants it.
            CreateAction::modal(UserResource::class)->label('New user'),

            ImportAction::make(UserImporter::class, UserResource::class),

            ExportAction::make(UserExporter::class, UserResource::class),
        ];
    }

    private static function actorIsAdmin(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->is_admin;
    }

    private static function asDate(mixed $value): ?Carbon
    {
        return $value instanceof Carbon ? $value : null;
    }
}
