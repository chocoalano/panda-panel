# Action Forms

An action can carry a form of its own. You reach for one whenever running the operation needs input the record does not already have — a rejection reason, a date to reschedule to, which columns to export. The form is a full `PandaPanel\Forms\FormSchema`, so every field type, layout, and validation rule available on a resource form is available here.

The schema is built per record and fetched when the dialog opens, never serialized into the page. A table of twenty records would otherwise ship twenty copies of a form to open at most one.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Tables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

final class OrdersTable
{
    public static function configure(TableSchema $table): TableSchema
    {
        return $table
            ->columns([TextColumn::make('reference')->searchable()])
            ->recordActions([
                Action::make('reject')
                    ->label('Reject')
                    ->modalHeading('Reject this order')
                    ->modalSubmitLabel('Reject it')
                    ->successMessage('Order rejected.')
                    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000),
                    ]))
                    ->action(static function (Order $record, array $data): void {
                        $record->reject($data['reason']);
                    }),
            ]);
    }
}
```

Pressing Reject opens a dialog, fetches the form, validates the reason, and calls the handler with `['reason' => '…']`.

## The API

```php
Action::schema(Closure $callback): static      // fn (?Model $record): FormSchema
Action::hasForm(): bool
Action::resolveSchema(?Model $record): ?FormSchema
Action::form(Closure $callback): static        // fn (?Model $record): string — an external form URL
```

| Method | What it does | `type()` becomes |
| --- | --- | --- |
| `schema()` | the action carries a form the panel's action-form endpoint describes | `form` |
| `form()` | the action's dialog fetches a form from a URL you supply | `form` |

`schema()` is what you want in nearly every case. `form()` exists for the relation actions, which name an owner and an operation the action-form endpoint knows nothing about — see [Relation actions](relation-actions.md).

`Action::toArray()` reports `hasForm: true` and `formUrl: null` for a `schema()` action, and the frontend builds the fetch URL from the panel's endpoint plus the names of what the action is about. The schema itself never appears in the row payload.

## The schema is built per record

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

Action::make('rename')->schema(
    static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        TextInput::make('name')->default($record?->getAttribute('name'))->required(),
    ]),
);
```

`$record` is the record the dialog was opened for, and `null` in the scopes that have none:

| Scope | `$record` |
| --- | --- |
| `record` | the row |
| `infolist` | the record the view page is about |
| `table` | `null` |
| `bulk` | `null` — there is no one record a selection is about |

## What the handler receives

```php
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

// Record scope.
->action(static function (Order $record, array $data): void {
    $record->reject($data['reason']);
});

// Table scope.
->tableAction(static function (array $data): void {
    Order::query()->where('status', 'draft')->update(['note' => $data['note']]);
});

// Bulk scope.
->bulkAction(static function (Collection $records, array $data): void {
    $records->each(static fn (Order $order) => $order->reject($data['reason']));
});
```

`$data` has been validated with `FormSchema::validationRules($record)` and then run through `FormSchema::dehydrate()`, so it holds exactly what the schema declared and nothing else. An extra key in the request body is discarded the same way it is on a resource form:

```php
$this->post('/admin/actions/form', [
    'resource' => 'form-fixtures',
    'action' => 'rename',
    'scope' => 'table',
    'name' => 'Renamed',
    'is_admin' => true,        // never declared by the schema
]);

// The handler saw ['name' => 'Renamed'].
```

A handler declared with one parameter never sees the second, which is why declaring a form is additive: every action written before the form existed runs exactly as it did.

## Wizards

A schema holding a `Wizard` is a stepped dialog and needs nothing else said.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Step;
use PandaPanel\Forms\Layouts\Wizard;

Action::make('onboard')
    ->modalWidth(PandaPanel\Actions\Enums\ModalWidth::TwoExtraLarge)
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Wizard::make([
            Step::make('Account')->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->email()->required(),
            ]),
            Step::make('Role')->schema([
                Select::make('role')->options(['member' => 'Member', 'admin' => 'Admin'])->required(),
            ]),
        ]),
    ]))
    ->tableAction(static function (array $data): void {
        App\Models\User::query()->create($data);
    });
```

## File uploads

A `FileUpload` field works, and the dialog is told where to send the file:

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;

Action::make('attach')
    ->schema(static fn (): FormSchema => FormSchema::make()->schema([
        FileUpload::make('document')->disk('local')->directory('order-documents')->required(),
    ]))
    ->action(static function (Order $record, array $data): void {
        $record->documents()->create(['path' => $data['document']]);
    });
```

`PandaPanel\Support\FormEndpoints::uploadForAction()` builds that URL, carrying the resource, the action name, the scope, and the record. The upload is authorized **as the action**, not as the resource: an action a user may not run must not be a way to put a file on a disk. `ImportAction` is built on exactly this.

## The two endpoints

```text
GET  {panel}/actions/form      route name: panel.{panelId}.actions.form
POST {panel}/actions/form      route name: panel.{panelId}.actions.submit
```

Two requests, for the reason a table's row actions are buttons rather than twenty embedded forms.

### Describing the dialog

```text
GET /admin/actions/form?resource=orders&action=reject&scope=record&record=42
```

| Query key | Required | Meaning |
| --- | --- | --- |
| `resource` | yes | resource slug, resolved against this panel's registry |
| `action` | yes | the action's name |
| `scope` | yes | one of `record`, `table`, `bulk`, `infolist` |
| `record` | no | the record key, for the scopes that have one |
| `parent` | for a nested resource | the parent record's key |

The response:

```json
{
  "title": "Reject this order",
  "submitLabel": "Reject it",
  "form": { "columns": 1, "schema": [] },
  "submitUrl": "/admin/actions/form",
  "uploadUrl": "/admin/uploads?resource=orders&action=reject&scope=record&record=42",
  "context": { "resource": "orders", "action": "reject", "scope": "record" },
  "modal": { "width": "md" }
}
```

`title` is the modal heading or the action's label; `submitLabel` is the modal's submit label or the action's label. `submitUrl` is built by the server, so the browser never assembles a panel URL.

### Submitting it

```text
POST /admin/actions/form
```

The body carries the same context plus the form's own values:

```json
{
  "resource": "orders",
  "action": "reject",
  "scope": "record",
  "record": 42,
  "reason": "Out of stock"
}
```

A bulk submit sends `records` instead of `record` (an array, `max:500`). The controller then:

1. resolves the panel, the resource, and — for a nested resource — the parent;
2. resolves the record through `Resource::findRecord()`, or 404;
3. resolves the action in the scope's own schema, or 404;
4. asks `isAuthorizedFor($record)`, or 403;
5. asks `hasForm()`, or 400;
6. validates with the schema's rules and dehydrates;
7. runs `executeBulk()`, `executeWithoutRecord()`, or `execute()` depending on the scope.

Both requests authorize, because opening a dialog and performing an operation are two separate permissions in time.

## Which whitelist a scope names

```php
private const SCOPES = ['record', 'table', 'bulk', 'infolist'];
```

| Scope | Resolved by |
| --- | --- |
| `record` | `TableSchema::getRecordAction()` |
| `table` | `TableSchema::getTableAction()` |
| `bulk` | `TableSchema::getBulkAction()` |
| `infolist` | `InfolistSchema::getAction()` |

A scope outside the list is a 422 before anything else happens. Each scope is a separate whitelist on purpose — an action shown on a view page cannot be run through the table's endpoint.

## Validation failures

A failed validation is an ordinary Laravel `ValidationException`: the response redirects back with the errors, and the dialog renders them against the fields that produced them. Nothing runs.

```php
it('validates the submitted data against the action\'s own schema', function (): void {
    $this->post('/admin/actions/form', [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
        // `name` is required by the action's schema.
    ])->assertSessionHasErrors('name');
});
```

`ImportAction` uses this deliberately: a file whose headings do not cover a required column throws `ValidationException::withMessages(['file' => …])` before a single row is read.

## Gotchas

- **The dialog has no options endpoint and no live-field endpoint.** The frontend passes the form an upload URL and nothing else, so a `Select` that fetches options as you type falls back to whatever options the schema serialized, and a field marked `live()` never asks the server to rebuild the schema. Declare the options up front, or use a full page for a form that needs to react.
- **`$record` is `null` on a table or bulk form.** A schema closure that dereferences it fails when the dialog is opened rather than when the line is written.
- **The schema is rebuilt on submit.** `resolveSchema()` is called again by the submit request, so a closure that depends on request state must produce the same rules both times.
- **`schema()` and `form()` both make the action a `form`.** `form()` wins in the payload: `formUrl` is sent and the frontend prefers it over the panel's endpoint.
- **An action with a form ignores its confirmation dialog's submit button.** The frontend renders the form's own submit instead, and registered modal actions are not drawn at all — the footer they live in is only there when the dialog has no form.
- **Data reaching the handler is dehydrated, not raw.** A field with `dehydrated(false)` is absent, and a field that transforms its state gives you the transformed value.

## See also

- [Action basics](overview.md)
- [Action modals](modals.md)
- [Bulk actions](bulk-actions.md)
- [Action authorization](authorization.md)
- [Import and export actions](import-export.md)
- [Relation actions](relation-actions.md)
- [FormSchema basics](../forms/overview.md)
- [Validation](../forms/validation.md)
- [File uploads](../forms/file-uploads.md)
- [Live fields](../forms/live-fields.md)
- [Option endpoints](../forms/options-endpoints.md)
