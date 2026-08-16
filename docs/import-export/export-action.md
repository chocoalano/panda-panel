# ExportAction

`PandaPanel\Actions\ExportAction` turns the records a table is showing — or the ones a user ticked — into a spreadsheet they can download. It is a factory rather than a class of its own: both methods hand back a configured `PandaPanel\Actions\Action`, so everything a normal action can do to its label, icon, modal, or authorization still applies.

Reach for it whenever somebody asks "can I get this list in Excel". The action owns the dialog, the record selection, and the download link; the `Exporter` class you pass owns the columns, the file name, and where the file lands.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Exports;

use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Exports\Exporter;

final class UserExporter extends Exporter
{
    /**
     * @return list<ExportColumn>
     */
    public static function columns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users\Tables;

use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ExportAction;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class UsersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
            ])
            ->headerActions([
                ExportAction::make(UserExporter::class, UserResource::class),
            ]);
    }
}
```

An **Export** button appears above the table. It opens a dialog offering the three columns and the two formats, writes the file, and comes back with a toast carrying a **Download** link.

## The two factories

```php
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Exports\Exporter;
use PandaPanel\Resources\Resource;

/** @param class-string<Exporter> $exporter @param class-string<Resource> $resource */
ExportAction::make(string $exporter, string $resource): Action;   // the list, as currently filtered
ExportAction::bulk(string $exporter, string $resource): Action;   // the ticked records only
```

Both take **class names**, never instances or closures. A large export is handed to a queued job that runs in another process, and only a class name crosses that gap.

```php
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Actions\ExportAction;

$table
    ->headerActions([
        ExportAction::make(UserExporter::class, UserResource::class),
    ])
    ->bulkActions([
        ExportAction::bulk(UserExporter::class, UserResource::class),
    ]);
```

They share the dialog and differ only in which records they cover, because "which columns" is a question about the file rather than about how the records were chosen.

## What the factory configures

Both factories return an action already carrying this, and every one of these is a normal `Action` setter you can override afterwards.

| Setting | Value | Set by |
| --- | --- | --- |
| name | `export` | `Action::make('export')` |
| label | `Export` | `->label()` |
| icon | `download` | `->icon()` |
| variant | `ActionVariant::Outline` | `->variant()` |
| modal heading | `Export records` | `->modalHeading()` |
| modal submit label | `Export` | `->modalSubmitLabel()` |
| modal width | `ModalWidth::Large` | `->modalWidth()` |
| success message | `Your export is ready.` | `->successMessage()` |
| authorization | `$resource::canViewAny()` | `->authorize()` |
| handler | `->tableAction()` (`make`) / `->bulkAction()` (`bulk`) | — |
| form | the column and format dialog | `->schema()` |

Overriding is ordinary chaining:

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Actions\ExportAction;

ExportAction::make(UserExporter::class, UserResource::class)
    ->label('Download users')
    ->icon('file-spreadsheet')
    ->variant(ActionVariant::Default)
    ->modalHeading('Download the user list')
    ->successMessage('Preparing your file.');
```

Authorization is `Resource::canViewAny()` because an export is a copy of the list — the same question the list itself asks. Tightening it further is a matter of chaining another `->authorize()`, which replaces the one the factory set:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

ExportAction::make(UserExporter::class, UserResource::class)
    ->authorize(static fn (?Model $record): bool => Gate::allows('export-users'));
```

## The dialog

The form is built from the exporter, not from the action, and both of its fields are ordinary form fields:

```php
use PandaPanel\Forms\Components\CheckboxList;
use PandaPanel\Forms\Components\Radio;

CheckboxList::make('columns')
    ->label('Columns')
    ->options(/* name => label, from Exporter::columns() */)
    ->columns(2)
    ->bulkToggleable()
    ->required()
    ->default(/* the names whose enabledByDefault() is true */);

Radio::make('format')
    ->label('Format')
    ->options(/* value => label, from Exporter::formats() */)
    ->inline()
    ->required()
    ->default(/* the first format the exporter offers */);
```

Because they are fields, a column name that is not one of the exporter's is refused by the same `in:` rule any other choice field uses. Two further passes happen on the server after validation:

- Names the exporter does not declare are dropped; an **empty** choice writes **every** column, because a file with a header and nothing else is not what anybody meant by "export".
- The format is read with `SpreadsheetFormat::tryFrom()` and falls back to `SpreadsheetFormat::Csv`.

Which columns end up in the file, and in what order, is decided by the exporter — see [Exporter classes](exporters.md).

## Which records end up in the file

| Factory | Query |
| --- | --- |
| `bulk()` | `Resource::query()->whereKey($selectedKeys)` |
| `make()` | `Resource::query()` constrained by `PandaPanel\Tables\TableQuery` using the table state the list was showing |

The client sends the list's current query string as `tableState` with the request, and `PandaPanel\Support\TableState::fromRequest()` narrows it to string keys holding scalars or one level of array. Every value then goes back through the table's own schema, which is the whitelist: a filter the table never declared is ignored there exactly as it is when it arrives in a URL. The worst a crafted payload can describe is a list the user could have navigated to.

That is also why the file and the screen cannot disagree — both are produced by the same `TableQuery`.

## Inline or queued

The record count is taken **before** anything is written: `count()` on the constrained query for `make()`, `count($keys)` for `bulk()`.

```php
if ($exporter::queueAfter() >= 0 && $count > $exporter::queueAfter()) {
    // PandaPanel\Jobs\RunPanelExport is dispatched and the request returns.
}
```

| `queueAfter()` | Behaviour |
| --- | --- |
| `0` | always queued |
| `2000` (default) | queued above 2000 records |
| negative | never queued, whatever the count |

Below the threshold the file is written in the request by `PandaPanel\Actions\Exports\ExportRun::write()` and the response carries the link. Above it, the job writes the file and the link arrives as a notification. Both go through the same `ExportRun`, so the file is identical either way. See [Queued exports](queued-exports.md).

## What comes back

For an export that ran in the request, two things are sent:

```php
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->broadcast(false)          // the response is right here; broadcasting would show it twice
    ->actions([
        NotificationAction::make('download')->label('Download')->url($url),
    ])
    ->send($user);

Inertia::flash('toast', [
    'type' => 'success',
    'message' => $exporter::completedMessage($result['records']),
    'url' => $url,
    'urlLabel' => 'Download',
]);
```

The URL is `route($panel->routeName('export-file'), ['file' => $file, 'exporter' => $exporter], absolute: false)` — the panel's `export-file` route with the file's basename and the exporter class as a query parameter. See [Import and export notifications](notifications.md).

## Downloading

```text
GET {panel}/exports/{file}?exporter=App\Panels\Admin\…\UserExporter
route name: panel.{panelId}.export-file
```

`PandaPanel\Http\Controllers\PanelExportController` answers it:

1. no authenticated user is a 403;
2. a `file` that is empty or contains `/`, `\`, or `..` is a 404 — the caller names a file, never a path;
3. an `exporter` that is not a subclass of `PandaPanel\Actions\Exports\Exporter` is a 404;
4. the path is `{$exporter::directory()}/{$user->getAuthIdentifier()}/{$file}` — built from **whoever is asking**, so one user cannot name another's export however they spell it;
5. a missing file is a 404, and anything else is `Storage::disk($exporter::disk())->download($path, $file)`.

## Where to put the action

| Placement | Factory | Runs through |
| --- | --- | --- |
| `headerActions()` | `ExportAction::make()` | `tableAction()` — no record |
| `toolbarActions()` | `ExportAction::make()` | `tableAction()` |
| `bulkActions()` | `ExportAction::bulk()` | `bulkAction()` — the selection |

`ExportAction::make()` declares only a table handler, so putting it in `recordActions()` produces an action that cannot execute: the record scope asks for `isExecutable()` and gets a 400. Put the table version in the header or toolbar, and the bulk version in `bulkActions()`.

## Gotchas

- **The success flash and the export toast are two different messages.** The action endpoint always redirects `back()->with('success', $action->getSuccessMessage())`. For an inline export, the explicit `Inertia::flash('toast', …)` carrying the download link wins — `PandaPanel\Http\Middleware\ShareFlashToast` never overwrites an explicit toast. For a **queued** export nothing explicit is flashed, so the success message is what the user sees, and the default reads `Your export is ready.` before it is. Override `->successMessage('Preparing your export.')` on an action whose exporter queues.
- **A bulk selection is capped at 500 keys** by the action form endpoint's `'records' => ['nullable', 'array', 'max:500']` rule. Larger runs belong to `ExportAction::make()` over a filtered list.
- **The exporter must have no duplicate column names.** `ExportRun` throws `PanelSchemaException::duplicateExportColumns()` — the picker keys its selection by name, so two columns with one name cannot be ticked apart.
- **Sorting is the exporter's job, not the table's.** The table state constrains *which* records; `Exporter::query()` decides the order the file is written in. Without a `reorder()`, the file's order follows whatever the list was sorted by.
- **Nothing deletes an export file.** It stays on the disk under the owner's directory until something removes it — see [Storage and cleanup](storage-cleanup.md).
- **A relation column with no eager load is one query per row.** `with()` belongs in `Exporter::query()`.

## See also

- [Exporter classes](exporters.md) — columns, query, file name, thresholds
- [Columns and mapping](columns-mapping.md) — `ExportColumn` in full
- [Queued exports](queued-exports.md)
- [Storage and cleanup](storage-cleanup.md)
- [Import and export notifications](notifications.md)
- [ImportAction](import-action.md)
- [Import and export actions](../actions/import-export.md)
- [Action basics](../actions/overview.md), [Action forms](../actions/forms.md), [Bulk actions](../actions/bulk-actions.md)
- [Table filters](../tables/filters.md) — what `tableState` carries
