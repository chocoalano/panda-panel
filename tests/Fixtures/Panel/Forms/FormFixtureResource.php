<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Forms;

use Illuminate\Support\Facades\Gate;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Enums\ConditionOperator;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Forms\Support\Get;
use PandaPanel\Forms\Support\Set;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;

/**
 * A resource whose form exercises the side endpoints: a file field, a live
 * field, and a field that only appears under a condition.
 *
 * It rides on the relation fixtures' `Project` model because none of what is
 * tested here reaches a column — an upload answers with a path and a form
 * rebuild answers with a schema, and neither writes anything.
 */
final class FormFixtureResource extends Resource
{
    protected static string $model = Project::class;

    protected static ?string $slug = 'form-fixtures';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * What the last action form handed its handler, so a test can assert on
     * what the schema let through rather than on a side effect of it.
     *
     * @var array<string, mixed>
     */
    public static array $lastData = [];

    /** What a `Get` in a reactive callback read, for asserting on injection. */
    public static mixed $lastGet = null;

    /**
     * The arguments a legacy positional callback was handed.
     *
     * @var list<mixed>|null
     */
    public static ?array $legacyArgs = null;

    public static function table(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->headerActions([
                // An action that carries a form, for the endpoint that
                // describes one.
                Action::make('rename')
                    ->label('Rename')
                    ->modalHeading('Rename everything')
                    ->modalSubmitLabel('Rename')
                    ->modalWidth(ModalWidth::Large)
                    ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                        TextInput::make('name')->required()->maxLength(255),
                    ]))
                    ->authorize(static fn (): bool => Project::query()->getModel()->exists
                        || Gate::allows('create', Project::class))
                    ->tableAction(static function (array $data): void {
                        self::$lastData = $data;

                        Project::query()->update(['name' => $data['name']]);
                    }),

                // One with no form at all, so the endpoint has something to
                // refuse.
                Action::make('deleteAll')
                    ->label('Delete all')
                    ->tableAction(static function (): void {
                        Project::query()->delete();
                    }),

                // An action whose form is reactive. Its `live()` field and its
                // dependent select are the two things that used to work on a
                // resource's own pages and be silently inert inside a dialog.
                Action::make('categorise')
                    ->label('Categorise')
                    ->schema(static fn (): FormSchema => FormSchema::make()->schema([
                        Select::make('group')
                            ->options(['a' => 'A', 'b' => 'B'])
                            ->live()
                            ->afterStateUpdated(static fn (Set $set) => $set('member', null)),
                        Select::make('member')
                            ->searchable()
                            ->optionsUsing(static fn (FormState $state): array => $state->get('group') === 'b'
                                ? ['b1' => 'B one', 'b2' => 'B two']
                                : ['a1' => 'A one']),
                    ]))
                    ->tableAction(static function (array $data): void {
                        self::$lastData = $data;
                    }),
            ]);
    }

    public static function form(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('kind')
                ->options(['plain' => 'Plain', 'special' => 'Special'])
                ->live(),
            TextInput::make('note')
                ->visibleWhen('kind', ConditionOperator::Equals, 'special'),
            FileUpload::make('attachment')
                ->disk('public')
                ->directory('attachments')
                ->acceptedTypes(['image/png'])
                ->maxSize(64),

            // The dependent pair. `parent` clears `child` when it changes, so
            // a value that was valid under the old parent does not survive as
            // a selection its own list no longer contains.
            Select::make('parent')
                ->options(['produksi' => 'Produksi', 'gudang' => 'Gudang'])
                ->live()
                ->afterStateUpdated(static function (Set $set, Get $get): void {
                    self::$lastGet = $get('parent');

                    $set('child', null);
                }),

            Select::make('child')
                ->searchable()
                ->optionsUsing(static fn (FormState $state): array => match ($state->get('parent')) {
                    'produksi' => ['welder' => 'Welder', 'operator' => 'Operator'],
                    'gudang' => ['picker' => 'Picker'],
                    default => [],
                }),

            // Part N — visibility decided on the server from the form state.
            // `hiddenWhen()` covers a comparison the browser can make; this
            // covers a rule it cannot, and is re-evaluated when a live field
            // asks for a rebuild.
            Select::make('employment_type')
                ->options(['permanent' => 'Permanent', 'contract' => 'Contract'])
                ->live(),

            TextInput::make('contract_end_date')
                ->visible(static fn (Get $get): bool => $get('employment_type') === 'contract'),

            // Written the way every callback in every existing application is
            // written. It must go on being called exactly as it was.
            Select::make('legacy')
                ->options(['x' => 'X'])
                ->live()
                ->afterStateUpdated(static function ($value, $previous, $record): void {
                    self::$legacyArgs = [$value, $previous, $record];
                }),
        ]);
    }

    /**
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return [
            'index' => ListFormFixtures::class,
            'create' => CreateFormFixture::class,
            'edit' => EditFormFixture::class,
        ];
    }
}
