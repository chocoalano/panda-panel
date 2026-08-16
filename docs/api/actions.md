# Actions Reference

`PandaPanel\Actions\Action` and the built-in actions made from it. An action is a backend-owned operation the frontend can request by name: the serialized definition carries a label, an icon key, a variant, and confirmation copy, and never the handler. That indirection is the point — an action not declared on the schema being addressed does not exist, whatever the request says.

## Namespaces

| Class | Purpose |
| --- | --- |
| `PandaPanel\Actions\Action` | The action itself |
| `PandaPanel\Actions\Support\Modal` | How an action's dialog behaves |
| `PandaPanel\Actions\Enums\*` | Variant, type, modal width, spreadsheet format |
| `PandaPanel\Actions\{Create,View,Edit,Delete,Replicate,Restore,ForceDelete}Action` | Record and table CRUD |
| `PandaPanel\Actions\{DeleteBulk,RestoreBulk,ForceDeleteBulk}Action` | Bulk CRUD |
| `PandaPanel\Actions\{Export,Import}Action` | Spreadsheets |
| `PandaPanel\Actions\Exports\{Exporter,ExportColumn,ExportRun}` | What an export is |
| `PandaPanel\Actions\Imports\{Importer,ImportColumn,ImportRun}` | What an import is |
| `PandaPanel\Actions\Relations\*` | Attach, detach, associate, dissociate, and related CRUD |

## A custom action

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;

Action::make('approve')
    ->label('Approve')
    ->icon('check')
    ->variant(ActionVariant::Default)
    ->requiresConfirmation(
        heading: 'Approve this order?',
        description: 'The customer is charged immediately.',
        button: 'Approve',
    )
    ->successMessage('Order approved.')
    ->visible(fn (?Model $record): bool => $record?->status === 'pending')
    ->authorize(fn (?Model $record): bool => $record !== null && auth()->user()->can('approve', $record))
    ->action(function (Model $record): void {
        $record->update(['status' => 'approved']);
    });
```

Declare it where it is reachable from — `TableSchema::recordActions()`, `bulkActions()`, `headerActions()`, `toolbarActions()`, `emptyStateActions()`, `InfolistSchema::actions()`, `Section::headerActions()`, or `Entry::action()` — and the matching endpoint can run it.

The name may contain letters, numbers, dashes, dots, and underscores only. Anything else throws `PanelSchemaException::unusableActionName()`, because the name travels to the endpoint as an identifier and a name that can never match renders as a button that fails only when pressed.

## `Action`

`class Action`. Every setter returns `static`, so a subclass stays a subclass.

### Construction

```php
public static function make(string $name): static;
public function getName(): string;
```

`make()` runs the panel's `configureActions()` callback as the action is built, so anything the schema sets afterwards still wins:

```php
$panel->configureActions(static function (Action $action): void {
    if ($action->getVariant() === ActionVariant::Destructive) {
        $action->requiresConfirmation();
    }
});
```

Read through the current panel rather than a static configurator, so two panels can differ and nothing leaks between requests.

### Presentation

```php
public function label(string $label): static;            // Str::headline($name)
public function icon(string $icon): static;              // null
public function variant(ActionVariant $variant): static; // Ghost
public function requiresConfirmation(
    bool $requires = true,
    ?string $heading = null,
    ?string $description = null,
    ?string $button = null,
): static;                                               // false

public function getLabel(): string;
public function getIcon(): ?string;
public function getVariant(): ActionVariant;
```

Confirmation copy falls back to `"{label}?"`, `This cannot be undone.`, and the label.

`ActionVariant`: `Default`, `Secondary`, `Outline`, `Ghost`, `Destructive`.

### Visibility and authorization

```php
public function visible(Closure $callback): static;             // Closure(?Model): bool
public function authorize(Closure $callback): static;           // Closure(?Model): bool
public function authorizeEachUsing(Closure $callback): static;  // Closure(Model): bool
public function isVisibleFor(?Model $record): bool;
public function isAuthorizedFor(?Model $record): bool;
public function isAuthorizedForEach(Model $record): bool;
```

Two different questions. `visible()` hides the action without implying it is forbidden — a restore action on a record that is not trashed. `authorize()` answers whether it may be run, and is asked again on execution: hiding a button is never what protects a record.

`authorizeEachUsing()` is for bulk runs. `authorize()` answers for the action; this answers for each record it is about to touch, and `executeBulk()` checks every one *before* writing any. When `authorizeEachUsing()` is absent, that per-record pass falls back to `authorize($record)` — "all or nothing" has to be decided before the first write.

The record argument is nullable because a table, header, or bulk action is authorized with no record in hand:

```php
->authorize(fn (?Model $record): bool => $record === null
    ? OrderResource::canDeleteAny()
    : OrderResource::canDelete($record));
```

### What the action does

```php
public function url(Closure $callback): static;         // Closure(Model): string — a link
public function action(Closure $callback): static;      // Closure(Model, array): void — one record
public function bulkAction(Closure $callback): static;  // Closure(Collection<int, Model>, array): void
public function tableAction(Closure $callback): static; // Closure(array): void — no record
public function before(Closure $callback): static;
public function after(Closure $callback): static;

public function type(): ActionType;
public function isExecutable(): bool;
public function isBulkExecutable(): bool;
public function isTableExecutable(): bool;
public function isInert(): bool;
```

`ActionType` is derived, not declared: `Link` when a URL is set, `Form` when a form URL or schema is, `Callback` otherwise.

An action with no URL, no handler, no schema, no form URL, no modal, and no modal actions is **inert**, and every `TableSchema` action setter refuses one — `PanelSchemaException::inertAction()`. It would otherwise render a button that responds to being pressed by doing nothing.

`before()` and `after()` run inside the handler's transaction, so an `after` hook that throws undoes the operation. They live on the action rather than on a page because the action endpoint executes without a page instance — a hook declared on the page would never be called.

```php
Action::make('ship')
    ->before(fn (Model $record) => $record->reserveStock())
    ->action(fn (Model $record) => $record->markShipped())
    ->after(fn (Model $record) => ShipmentQueued::dispatch($record));
```

### Bulk and table shapes

```php
Action::make('archive')
    ->label('Archive selected')
    ->authorizeEachUsing(fn (Model $record): bool => auth()->user()->can('archive', $record))
    ->bulkAction(function (Collection $records): void {
        $records->each->update(['archived_at' => now()]);
    });
```

A bulk action with no `bulkAction()` but with an `action()` runs the record handler once per record — `executeBulk()` falls through to `execute()`.

```php
Action::make('recalculate')
    ->label('Recalculate totals')
    ->tableAction(function (array $data): void {
        RecalculateTotals::dispatch();
    });
```

A table action carries no record and is authorized with none. It is what a header, toolbar, or empty-state action is.

### Success messages

```php
public function successMessage(string $message): static;
public function successMessageUsing(Closure $callback): static;   // Closure(int $affected): string
public function getSuccessMessage(): string;                      // '{Label} completed.'
public function affectedCount(): int;
```

```php
Action::make('approve')
    ->successMessageUsing(fn (int $count): string => "{$count} orders approved.");
```

`affectedCount()` is 1 after a record run and the size of the selection after a bulk one.

### Transactions

```php
public function databaseTransaction(bool $databaseTransaction = true): static;   // null — inherits the panel
public function hasDatabaseTransaction(): ?bool;
```

`->databaseTransaction(false)` on an action that calls an external service keeps the connection from being held open across a network round trip; `->databaseTransaction()` turns one on inside a panel that has them off.

### Forms

```php
public function schema(Closure $callback): static;      // Closure(?Model): FormSchema
public function form(Closure $callback): static;        // Closure(?Model): string — a URL
public function hasForm(): bool;
public function resolveSchema(?Model $record): ?FormSchema;
```

`schema()` gives the action a form of its own, built per record and fetched when the dialog opens rather than serialized into every row — a table of twenty records would otherwise ship twenty copies of a form to open at most one.

```php
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

Action::make('note')
    ->label('Add a note')
    ->modalHeading('Note about this account')
    ->modalSubmitLabel('Save note')
    ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('note')->rows(6)->required()->maxLength(1000),
    ]))
    ->action(function (Model $record, array $data): void {
        $record->notes()->create(['body' => $data['note']]);
    });
```

The validated, dehydrated data arrives as the handler's second argument. A handler that takes one argument never sees it, which is why adding a form to an existing action is additive.

A schema holding a `Wizard` is a stepped dialog and needs nothing else said.

`form()` is the other shape: a URL the dialog fetches its form from. It is what the relation actions use, because a relation form names an owner and an operation the action itself knows nothing about.

### Modals

```php
public function modal(Closure $callback): static;                       // Closure(Modal): void
public function modalWidth(ModalWidth $width): static;                  // Medium
public function slideOver(bool $slideOver = true): static;              // false
public function modalHeading(string $heading): static;
public function modalDescription(string $description): static;
public function modalSubmitLabel(string $label): static;
public function modalContent(string $component, array $config = []): static;
public function getModal(): Modal;
public function hasModal(): bool;
```

The modal is built lazily: most actions never open one, and an action that does not is not carrying a modal's worth of defaults. `getModal()` creates it on first call, so `hasModal()` answers whether one was ever configured.

```php
use PandaPanel\Actions\Support\Modal;

Action::make('bulk-edit')
    ->modal(function (Modal $modal): void {
        $modal
            ->width(ModalWidth::TwoExtraLarge)
            ->stickyHeader()
            ->stickyFooter()
            ->closeByClickingAway(false)
            ->cancelLabel('Discard');
    });
```

### Modal actions

```php
public function registerModalActions(array $actions): static;   // array<array-key, static>
public function getModalActions(): array;                       // array<string, static>
public function getModalAction(string $name): ?static;
```

Actions reachable from inside this one's dialog. They are not rendered beside the trigger and are not found by the table's own lookup — the parent action is the only route to them, which is what keeps "runnable from this modal" from meaning "runnable by name from anywhere".

### Executing

```php
public function execute(Model $record, array $data = []): void;
public function executeBulk(Collection $records, array $data = []): void;
public function executeWithoutRecord(array $data = []): void;
public function toArray(?Model $record = null): ?array;
```

`toArray()` returns `null` when the action is hidden or unauthorized for this record, so a user never receives a button they may not press. Its keys: `name`, `label`, `icon`, `variant`, `type`, `url`, `formUrl`, `hasForm`, `modal`, `modalActions`, `confirmation`.

`executeBulk()` throws a 403 `HttpException` when any record fails `isAuthorizedForEach()`, before anything is written.

## `Modal`

```php
public static function make(): self;
public function width(ModalWidth $width): self;                 // Medium
public function slideOver(bool $slideOver = true): self;        // false
public function stickyHeader(bool $sticky = true): self;        // false
public function stickyFooter(bool $sticky = true): self;        // false
public function closeByClickingAway(bool $close = true): self;  // true
public function closeByEscaping(bool $close = true): self;      // true
public function autofocus(bool $autofocus = true): self;        // true
public function heading(string $heading): self;
public function description(string $description): self;
public function submitLabel(string $label): self;
public function cancelLabel(string $label): self;
public function withoutCancel(bool $without = true): self;      // keeps cancel by default
public function content(string $component, array $config = []): self;
public function getHeading(): ?string;
public function getSubmitLabel(): ?string;
public function toArray(): array;
```

`content()` takes a build-time registry key, never markup: the component's path below `resources/js/pages/` without the extension, and the glob only sees `Panels/**/Modals/*.vue` — so `Panels/Admin/Modals/Explanation`. It renders above whatever else the modal holds, so a form action can explain itself in its own words.

`ModalWidth`: `Small` (`sm`), `Medium` (`md`), `Large` (`lg`), `ExtraLarge` (`xl`), `TwoExtraLarge` (`2xl`), `FourExtraLarge` (`4xl`), `Screen`.

## Built-in record actions

Each is a factory returning a configured `Action`, so anything on it can still be overridden.

| Factory | Name | Variant | Confirms | Needs |
| --- | --- | --- | --- | --- |
| `ViewAction::make($resource)` | `view` | Ghost | no | a `view` page |
| `EditAction::make($resource)` | `edit` | Ghost | no | an `edit` page |
| `DeleteAction::make($resource)` | `delete` | Destructive | yes | `canDelete()` |
| `ReplicateAction::make($resource, $except = [], $using = null)` | `replicate` | Outline | yes | `canCreate()` and `canView()` |
| `RestoreAction::make($resource)` | `restore` | Outline | no | a trashed record, `canRestore()` |
| `ForceDeleteAction::make($resource)` | `forceDelete` | Destructive | yes | `canForceDelete()` |

```php
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\EditAction;
use PandaPanel\Actions\ReplicateAction;
use PandaPanel\Actions\ViewAction;

$table->recordActions([
    ViewAction::make(PostResource::class),
    EditAction::make(PostResource::class),
    ReplicateAction::make(
        PostResource::class,
        except: ['slug', 'published_at'],
        using: fn (Model $copy, Model $original) => $copy->title = $original->title.' (copy)',
    ),
    DeleteAction::make(PostResource::class),
]);
```

`ViewAction` and `EditAction` are link actions: nothing is posted, and the route authorizes again on arrival. They disappear when the resource declares no such page.

`ReplicateAction` uses Eloquent's own `replicate()`, so the copy already excludes the key and the timestamps. `except` names the columns *this* model must not duplicate — a unique slug, an invoice number, an API token — which are left at their database defaults rather than carried into a row that will collide.

`RestoreAction` is hidden for a record that is not trashed, so a row shows either restore or delete and never both. It needs `$softDeletes` on the resource and a `TrashedFilter` on the table to be reachable at all.

## Built-in table and bulk actions

```php
use PandaPanel\Actions\CreateAction;
use PandaPanel\Actions\DeleteBulkAction;
use PandaPanel\Actions\ExportAction;

$table
    ->headerActions([
        CreateAction::make(PostResource::class),        // a link to the create page
    ])
    ->toolbarActions([
        ExportAction::make(PostExporter::class, PostResource::class),
    ])
    ->bulkActions([
        DeleteBulkAction::make(PostResource::class),
        ExportAction::bulk(PostExporter::class, PostResource::class),
    ]);
```

| Factory | Shape |
| --- | --- |
| `CreateAction::make($resource)` | a link to the create page |
| `CreateAction::modal($resource)` | the same form in a dialog, as a table action |
| `DeleteBulkAction::make($resource)` | deletes the selection in one transaction |
| `RestoreBulkAction::make($resource)` | restores the selection |
| `ForceDeleteBulkAction::make($resource)` | permanently deletes the selection |

`CreateAction::modal()` goes through `Resource::form()` like the page does, so the two cannot validate or persist differently. Override the write with `->tableAction(...)` when creating is not a plain insert.

`DeleteBulkAction` authorizes every record before deleting any and wraps the whole set in `DB::transaction()` explicitly, whatever the panel's setting — "all or nothing" is the guarantee it advertises rather than a default it inherits.

## Export

```php
ExportAction::make(string $exporter, string $resource): Action;   // the list as currently filtered
ExportAction::bulk(string $exporter, string $resource): Action;   // the selection only
```

Both open the same dialog — pick the columns, pick the format — because "which columns" is a question about the file, not about how the records were chosen. Both are named `export`, variant `Outline`, modal width `Large`.

Small exports run in the request. Larger ones are queued as `RunPanelExport` and arrive as a notification with a download link; the threshold is the exporter's own `queueAfter()`.

### `Exporter`

```php
abstract class Exporter
{
    abstract public static function columns(): array;              // list<ExportColumn>

    public static function query(Builder $query): Builder;         // returns it unchanged
    public static function fileName(): string;                     // kebab class name + '-Y-m-d-His'
    public static function disk(): string;                         // 'local'
    public static function directory(): string;                    // 'panel-exports'
    public static function formats(): array;                       // [Csv, Xlsx]
    public static function escapesFormulas(): bool;                // true
    public static function chunkSize(): int;                       // 500
    public static function queueAfter(): int;                      // 2000
    public static function completedMessage(int $records): string;
}
```

```php
use Illuminate\Database\Eloquent\Builder;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
            ExportColumn::make('is_admin')
                ->label('Administrator')
                ->formatUsing(static fn (mixed $value): string => $value ? 'Yes' : 'No'),
            ExportColumn::make('updated_at')->label('Last updated')->enabledByDefault(false),
        ];
    }

    public static function query(Builder $query): Builder
    {
        return $query->reorder('id');
    }

    public static function fileName(): string
    {
        return 'users-'.date('Y-m-d');
    }
}
```

`disk()` is `local` rather than `public` on purpose: an export is a copy of records somebody was allowed to see, and a public disk would put it at a guessable URL. The download goes through `panel.{id}.export-file`, which asks the question again.

`escapesFormulas()` neutralises a CSV cell a spreadsheet would run as a formula. Turn it off only for a file another *program* reads.

### `ExportColumn`

```php
public static function make(string $name): self;
public function label(string $label): self;                  // Str::headline($name)
public function formatUsing(Closure $callback): self;        // Closure(mixed, Model): mixed
public function enabledByDefault(bool $enabled = true): self;// true
public function getName(): string;
public function getLabel(): string;
public function isEnabledByDefault(): bool;
public function toCell(Model $record): string;
```

Dot notation reads through relations, so `author.name` is a column rather than a reason to write a formatter. Two columns on one name throw `PanelSchemaException::duplicateExportColumns()`.

### `ExportRun`

```php
public static function write(
    string $exporter,
    Builder $query,
    array $columns,
    SpreadsheetFormat $format,
    int|string $owner,
): array;
```

`SpreadsheetFormat` is `Csv` or `Xlsx`, with `mimeTypes()` and `fromPath()`.

## Import

```php
ImportAction::make(string $importer, string $resource): Action;
```

Upload a file, map its headings onto the importer's columns, then run. Above `Importer::queueAfter()` rows it is queued as `RunPanelImport`.

### `Importer`

```php
abstract class Importer
{
    abstract public static function model(): string;               // class-string<Model>
    abstract public static function columns(): array;              // list<ImportColumn>

    public static function resolve(array $data): ?Model;           // new model — override to update
    public static function rules(): array;                         // []
    public static function chunkSize(): int;                       // 200
    public static function queueAfter(): int;                      // 500
    public static function disk(): string;                         // 'local'
    public static function directory(): string;                    // 'panel-imports'
    public static function completedMessage(int $imported, int $failed): string;
}
```

`resolve()` is what makes an import an update rather than an insert. Returning `null` skips the row without counting it as a failure.

```php
public static function resolve(array $data): ?Model
{
    $email = $data['email'] ?? null;

    if (! is_string($email) || $email === '') {
        return null;
    }

    return User::query()->where('email', $email)->first() ?? new User;
}
```

### `ImportColumn`

```php
public static function make(string $name): self;
public function label(string $label): self;
public function guess(array $guesses): self;                 // headings this column recognises itself under
public function rules(array $rules): self;
public function required(bool $required = true): self;       // false
public function castUsing(Closure $callback): self;          // Closure(string): mixed
public function relationship(string $relationship, string $column = 'name'): self;
public function createRelated(bool $create = true): self;    // false
public function getName(): string;
public function getLabel(): string;
public function isRequired(): bool;
public function getRelationship(): ?string;
public function headings(): array;
public function validationRules(): array;
public function cast(string $value): mixed;
public function resolveRelated(Model $model, mixed $value): ?int;
public function attribute(Model $model): string;
```

```php
ImportColumn::make('email')
    ->label('Email')
    ->guess(['e-mail', 'email address'])
    ->required()
    ->rules(['email', 'max:255'])
    ->castUsing(static fn (string $value): string => mb_strtolower(trim($value)));

ImportColumn::make('company')
    ->relationship('company', 'name')
    ->createRelated();
```

A spreadsheet has no types — every cell is a string, and `1` / `yes` / `TRUE` all mean the same thing to a person and nothing to a boolean column, which is what `castUsing()` is for. Rules are Laravel's, applied per row: a file is request input like any other, and arriving as a spreadsheet does not make it trustworthy.

### `ImportRun`

```php
public static function run(string $importer, string $path, array $mapping, int|string $owner): array;
public static function headings(string $path): array;
public static function countRows(string $path): int;
public static function guessMapping(string $importer, array $headings): array;
public static function unmappedRequiredColumns(string $importer, array $mapping): array;
public static function missingColumnsMessage(array $missing, array $headings): string;
```

## Relation actions

Built by `RelationTable` for a record's relation managers. Each is authorized by the manager, not the resource.

| Factory | Signature | Offered when |
| --- | --- | --- |
| `CreateRelatedAction::make($resource, $manager, $owner)` | opens the manager's form | `canCreate()` |
| `EditRelatedAction::make($resource, $manager, $owner)` | opens it for one row | `canEdit()` |
| `DeleteRelatedAction::make($manager, $owner)` | deletes the related record | `canDelete()` |
| `AttachAction::make($resource, $manager, $owner)` | many-to-many only | `canAttach()` |
| `DetachAction::make($manager, $owner)` | many-to-many only | `canDetach()` |
| `DetachBulkAction::make($manager, $owner)` | the selection | `canDetach()` per record |
| `AssociateAction::make($resource, $manager, $owner)` | one-to-many only | `canAssociate()` |
| `DissociateAction::make($manager, $owner)` | one-to-many only | `canDissociate()` |
| `RestoreAction::make($manager, $owner)` | soft-deleting relations | `canRestore()` |
| `RestoreBulkAction::make($manager, $owner)` | the selection | `canRestore()` |
| `ForceDeleteAction::make($manager, $owner)` | soft-deleting relations | `canForceDelete()` |
| `ForceDeleteBulkAction::make($manager, $owner)` | the selection | `canForceDelete()` |

Detach and dissociate are two names because they are two decisions: detaching removes a pivot row and leaves both records; dissociating nulls a child's foreign key.

The attach dialog's options come from `RelationManager::attachableOptions()` and its pivot values validate against `pivotForm()`, so a key the user was never offered and a pivot column the manager never declared are both refused rather than written.

## Endpoints

| Route name | Verb | Whitelist it resolves against |
| --- | --- | --- |
| `panel.{id}.actions.record` | POST | `TableSchema::getRecordAction()` |
| `panel.{id}.actions.bulk` | POST | `TableSchema::getBulkAction()` |
| `panel.{id}.actions.table` | POST | `TableSchema::getTableAction()` |
| `panel.{id}.actions.infolist` | POST | `InfolistSchema::getAction()` |
| `panel.{id}.actions.reorder` | POST | `TableSchema::getReorderColumn()` |
| `panel.{id}.actions.cell` | POST | the table's editable columns |
| `panel.{id}.actions.form` | GET | the action's own schema |
| `panel.{id}.actions.submit` | POST | the same |
| `panel.{id}.relations.action` / `.bulk` | POST | `RelationTable::actionFor()` / `bulkActionFor()` |

A view page's actions are a different whitelist from a table's, resolved through a different endpoint, so an action shown on one page cannot be run from the other.

Every endpoint validates the resource and action names, resolves the resource against *this panel's* registry, loads records through `Resource::findRecord()`, and asks `isAuthorizedFor()` before running anything.

## Testing

`PandaPanel\Testing\TestsActions` has four entry points — `record()`, `table()`, `bulk()`, `infolist()` — and four free functions that wrap them.

```php
panelRecordActions(PostResource::class)
    ->assertExists('delete')
    ->assertVisible('delete', $post)
    ->assertCanRun('delete', $post)
    ->call('delete', $post);

panelBulkActions(PostResource::class)->assertExists('delete');
panelTableActions(PostResource::class)->assertExists('export');
panelInfolistActions(PostResource::class)->assertCanNotRun('note', $post);
```

```php
public function find(string $name): ?Action;
public function call(string $name, ?Model $record = null, array $data = []): self;
public function assertExists(string $name): self;
public function assertDoesNotExist(string $name): self;
public function assertVisible(string $name, ?Model $record = null): self;
public function assertHidden(string $name, ?Model $record = null): self;
public function assertCanRun(string $name, ?Model $record = null): self;
public function assertCanNotRun(string $name, ?Model $record = null): self;
```

Every lookup goes through the same schema the controller resolves against, so an action the helper can find is one the endpoint can find — and one it cannot find is one a request could not run either.

## Notes

- **The handler never crosses the wire.** The frontend sends an action name, a record key, and an optional payload. Everything else is looked up on the server.
- **Authorization is asked twice and both matter.** Once to decide whether to render the button, again before running. The second is the control.
- **`toArray()` returning null is the mechanism, not an error.** A hidden or unauthorized action is absent from the payload rather than present and disabled.
- **An inert action is refused at definition time.** Checked where a set of actions is declared rather than per row, so the mistake surfaces once rather than as a button that disappoints on click.
- **A bulk action's `authorize()` can be asked twice with different arguments.** It is always asked with `null` before anything is selected. It is also asked with each record when no `authorizeEachUsing()` override exists.
- **`before`/`after` share the handler's transaction; page lifecycle hooks do not apply.** Deletion runs through the endpoint, which has no page instance.
- **Modal actions are reachable only through their parent.** They are not in the table's own lookup, so registering one does not make it runnable by name.

## See also

- [Actions overview](../actions/overview.md)
- [Row actions](../actions/row-actions.md)
- [Bulk actions](../actions/bulk-actions.md)
- [Table actions](../actions/table-actions.md)
- [Built-in actions](../actions/built-in-actions.md)
- [Custom actions](../actions/custom-actions.md)
- [Action forms](../actions/forms.md)
- [Modals](../actions/modals.md)
- [Authorization](../actions/authorization.md)
- [Transactions](../actions/transactions.md)
- [Import and export](../actions/import-export.md)
- [Relation actions](../actions/relation-actions.md)
- [Tables reference](tables.md)
- [Infolists reference](infolists.md)
- [Notifications reference](notifications.md)
- [Events, jobs and controllers](events-jobs-controllers.md)
