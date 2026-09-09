<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Relations\DeleteRelatedAction;
use PandaPanel\Actions\Relations\DissociateAction;
use PandaPanel\Actions\Relations\EditRelatedAction;
use PandaPanel\Actions\Relations\ForceDeleteAction;
use PandaPanel\Actions\Relations\RestoreAction;
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\Components\NumberInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Support\FormState;
use PandaPanel\Forms\Support\Set;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

/**
 * A `hasMany` whose children soft delete: create, edit, delete, restore,
 * force delete, and dissociate all apply, and attach does not.
 */
final class TasksRelationManager extends RelationManager
{
    /** What the header action's handler was handed. */
    public static array $lastHeaderData = [];

    /** Whether the form-less header action ran. */
    public static bool $archivedAll = false;

    /**
     * What the row action's handler was handed, so a test can prove the file
     * reference survived the whole round trip rather than only that the
     * upload endpoint answered.
     */
    public static array $lastRowData = [];

    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    protected static ?string $icon = 'list';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $softDeletes = true;

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
            ])
            ->filters([
                TrashedFilter::make('trashed'),
            ])
            ->recordActions([
                EditRelatedAction::make(ProjectRelationResource::class, self::class, $owner),
                DissociateAction::make(self::class, $owner),
                RestoreAction::make(self::class, $owner),
                ForceDeleteAction::make(self::class, $owner),
                DeleteRelatedAction::make(self::class, $owner),
                self::renameAction(),
                self::statusAction(),
            ])
            ->bulkActions([
                self::bulkRenameAction(),
            ])
            ->headerActions([
                self::addSpecialAction(),
                self::archiveAllAction(),
            ]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255),
        ]);
    }

    /**
     * A relation action that collects something before it runs.
     *
     * The shape the audit found twenty-three of and the one that had no
     * working path at all: the resource action-form endpoint cannot resolve an
     * action declared here, and the plain relation action endpoint ran the
     * handler without the values.
     */
    public static function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('reason')->required(),
                FileUpload::make('evidence')
                    ->disk('public')
                    ->directory('evidence')
                    ->maxSize(64),
            ]))
            ->authorize(static fn (?Model $record): bool => TaskPolicy::$canRename)
            ->action(function (Model $record, array $data): void {
                self::$lastRowData = $data;

                $record->forceFill(['name' => $data['name']])->save();
            });
    }

    /**
     * A form whose second field depends on the first, on a relation action —
     * three capabilities at once: a form on this surface, `live()` on it, and
     * a server-side reset of a now-invalid sibling.
     */
    public static function statusAction(): Action
    {
        return Action::make('setStatus')
            ->label('Set status')
            ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
                Select::make('kind')
                    ->options(['open' => 'Open', 'closed' => 'Closed'])
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('detail', null)),
                Select::make('detail')
                    ->searchable()
                    ->optionsUsing(fn (FormState $state): array => $state->get('kind') === 'closed'
                        ? ['done' => 'Done', 'cancelled' => 'Cancelled']
                        : ['todo' => 'To do', 'doing' => 'Doing']),
            ]))
            ->action(function (Model $record, array $data): void {
                $record->forceFill(['status' => $data['detail'] ?? null])->save();
            });
    }

    /**
     * CASE 2 — a header action carrying a multi-field form.
     *
     * About the relation rather than about a row, so it has no related record
     * and runs through `tableAction()`. The owner is closed over rather than
     * passed in: `table()` already receives it, which is what makes a header
     * action able to write into the relation without the framework having to
     * invent a record for it.
     */
    public static function addSpecialAction(): Action
    {
        return Action::make('addSpecial')
            ->label('Add special')
            ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
                TextInput::make('name')->required()->maxLength(255),
                NumberInput::make('priority')->required(),
            ]))
            ->authorize(static fn (?Model $record): bool => TaskPolicy::$canAddSpecial)
            ->tableAction(static function (array $data): void {
                self::$lastHeaderData = $data;
            });
    }

    /**
     * A header action with no form at all, so the form-less path has something
     * to prove it still runs.
     */
    public static function archiveAllAction(): Action
    {
        return Action::make('archiveAll')
            ->label('Archive all')
            ->tableAction(static function (): void {
                self::$archivedAll = true;
            });
    }

    public static function bulkRenameAction(): Action
    {
        return Action::make('bulkRename')
            ->label('Rename all')
            ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
                TextInput::make('name')->required(),
            ]))
            ->bulkAction(function ($records, array $data): void {
                foreach ($records as $record) {
                    $record->forceFill(['name' => $data['name']])->save();
                }
            });
    }
}
