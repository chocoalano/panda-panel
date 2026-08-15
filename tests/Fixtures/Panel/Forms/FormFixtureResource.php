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
