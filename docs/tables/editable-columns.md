# Editable Columns

An editable column draws a control in the cell and writes the record when it changes. You reach for one when a single attribute is toggled or retyped often enough that opening the edit form for it is the wrong shape — a published flag, a status, a display name, a sort weight.

An editable cell is a write endpoint wearing a table's clothes, so it is held to every rule a form is, and one more besides.

## A minimal editable table

```php
use App\Panels\Admin\Resources\Orders\OrderResource;
use PandaPanel\Tables\Columns\SelectColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\TextInputColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\TableSchema;

public static function table(TableSchema $table): TableSchema
{
    return $table->columns([
        TextColumn::make('reference')->toggleable(false),

        TextInputColumn::make('customer_name')->maxLength(255)->rules(['required']),

        SelectColumn::make('status')->options([
            'open' => 'Open',
            'shipped' => 'Shipped',
            'done' => 'Done',
        ]),

        ToggleColumn::make('is_priority')->label('Priority'),
    ]);
}
```

No extra wiring is needed. The frontend posts to the panel's cell endpoint, the server re-derives everything from the schema, and the page re-renders with the row as it now is. Nothing is applied optimistically, because an optimistic update would have to guess the validation, the authorization, and the per-record disabled state.

## The four rules a write is held to

1. **Declared.** Only a column that is an `EditableColumn` can be written, and only the attribute it names. A request naming any other column is addressing something that does not exist.
2. **Validated.** `validationRules()` is the server's, exactly as a form field's is. The control rendered in the cell only decides what is easy to type.
3. **Authorized per record.** `Resource::canEdit($record)` is asked for the row being written, not once for the table.
4. **Disabled is re-asked.** `disabledUsing()` is evaluated on the way out to render the control *and* again on the way in before anything is written. A disabled control is not a permission.

## The types

| Class | `type()` | Cell shape | Implied rules |
| --- | --- | --- | --- |
| `ToggleColumn` | `toggle` | `{value: bool, disabled: bool}` | `boolean` |
| `CheckboxColumn` | `checkbox` | `{value: bool, disabled: bool}` | `boolean` |
| `TextInputColumn` | `text_input` | `{value: string, disabled: bool}` | `string` or `numeric`, plus `max:` |
| `SelectColumn` | `select` | `{value, label, disabled: bool}` | `Rule::in(...)` over the declared options |

All four extend `PandaPanel\Tables\Columns\EditableColumn`, which extends `Column` — so alignment, width, tooltips, freezing, and visibility all work as they do everywhere else. Their serialized definition carries `editable: true`, which is what tells the renderer to draw a control rather than a value.

### `ToggleColumn` and `CheckboxColumn`

```php
use PandaPanel\Tables\Columns\CheckboxColumn;
use PandaPanel\Tables\Columns\ToggleColumn;

ToggleColumn::make('is_active')->label('Active');
CheckboxColumn::make('is_featured');
```

The same write, drawn two ways. Both align `center`, both validate `boolean`, and both cast the submitted value with `filter_var($value, FILTER_VALIDATE_BOOLEAN)` before writing. Two classes rather than a flag, because the control is the whole difference and a `type` the frontend switches on is how every other column works.

### `TextInputColumn`

```php
use PandaPanel\Tables\Columns\TextInputColumn;

TextInputColumn::make('name')->maxLength(255)->rules(['required']);
TextInputColumn::make('weight')->numeric()->rules(['min:0']);
```

| Method | Signature | Effect |
| --- | --- | --- |
| `numeric()` | `numeric(): self` | renders `type="number"` and validates `numeric` instead of `string` |
| `maxLength()` | `maxLength(int $length): self` | renders the attribute and adds `max:{length}` |

### `SelectColumn`

```php
use PandaPanel\Tables\Columns\SelectColumn;

SelectColumn::make('status')->options(['open' => 'Open', 'done' => 'Done']);
```

`options(array $options): self` is the whitelist: the implied rule is `Rule::in()` over its keys. Unlike a relation-backed form select there is no bounded page to worry about — a table's inline select ships every option it has, so keep the list short. Declaring no options means no implied rule at all.

The cell's `label` is the option's label when the stored value matches one, and the raw value otherwise, so a row holding a legacy value still reads.

## What every editable column can do

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\TextInputColumn;

TextInputColumn::make('display_name')
    ->writeTo('name')
    ->rules(['required', 'min:2'])
    ->disabledUsing(static fn (Model $record): bool => $record->is_locked)
    ->mutateUsing(static fn (mixed $value, Model $record): string => trim((string) $value));
```

| Method | Signature | Notes |
| --- | --- | --- |
| `rules()` | `rules(array $rules): static` | added after the type's implied rules |
| `disabledUsing()` | `disabledUsing(Closure $callback): static` | `fn (Model $record): bool` |
| `mutateUsing()` | `mutateUsing(Closure $callback): static` | `fn (mixed $value, Model $record): mixed`, runs after the type cast |
| `updateUsing()` | `updateUsing(Closure $callback): static` | `fn (mixed $value, Model $record): void`, replaces the write |
| `writeTo()` | `writeTo(string $attribute): static` | when the write lands on a different attribute |

Read-side: `getWriteAttribute(): string`, `isDisabledFor(Model $record): bool`, `validationRules(): array`, `write(Model $record, mixed $value): void`.

### `rules()`

```php
TextInputColumn::make('sku')->maxLength(32)->rules(['required', 'alpha_dash']);
// validationRules() === ['string', 'max:32', 'required', 'alpha_dash']
```

The implied rules come first, then yours. They are ordinary Laravel rules, validated against the key `value`, so an error surfaces as `errors.value`.

### `disabledUsing()`

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

ToggleColumn::make('is_admin')
    ->disabledUsing(static function (Model $record): bool {
        $actor = auth()->user();

        return ! ($actor instanceof User && $actor->is_admin)
            || $actor->is($record);
    });
```

A cell can be read-only for some rows and not others. The closure is evaluated per record when the row is serialized, and again on the endpoint before the write. The example above is the reason both matter: a policy that permits self-edit would otherwise be a way to grant yourself administrator rights.

### `writeTo()`

```php
TextInputColumn::make('display_name')->writeTo('name');
```

The column reads `display_name` and writes `name`. Use it when the displayed attribute is an accessor and the stored one is not.

### `mutateUsing()` and `updateUsing()`

```php
use Illuminate\Database\Eloquent\Model;

// Transform on the way in.
TextInputColumn::make('slug')->mutateUsing(
    static fn (mixed $value): string => str($value)->slug()->toString(),
);

// Replace the write entirely.
SelectColumn::make('status')
    ->options(['open' => 'Open', 'done' => 'Done'])
    ->updateUsing(static function (mixed $value, Model $record): void {
        $record->transitionTo($value);
    });
```

`mutateUsing()` runs after the type's own cast and before the record is touched. `updateUsing()` takes the write over completely — nothing is assigned and nothing is saved unless the closure does it. Reach for it when the value is not a bare column: a state machine, a service call, a related record.

Without `updateUsing()`, the write is `$record->forceFill([$attribute => $value])->save()`. `forceFill` deliberately bypasses `$fillable`, because the whitelist here is the schema, not the model.

## The endpoint

```
POST {panel}/actions/cell
```

Route name `panel.{panel_id}.actions.cell`. One endpoint per panel, not per resource. The payload:

| Key | Required | Meaning |
| --- | --- | --- |
| `resource` | yes | resource slug, resolved against this panel's registry |
| `record` | yes | the record key; must be a string or an int |
| `column` | yes | the column name |
| `value` | — | the new value, validated against the column's rules |
| `parent` | for a nested resource | the parent record's key |

The checks run in this order, and each failure is distinct:

| Failure | Status |
| --- | --- |
| unknown resource slug | 404 |
| unknown column | 404 |
| known column that is not an `EditableColumn` | 400 |
| record key that is not a scalar | 422 |
| record outside the resource query | 404 |
| `Resource::canEdit()` refuses | 403 |
| `disabledUsing()` answers true for that record | 403 |
| validation fails | redirect back with `errors.value` |

The write runs through `DatabaseTransaction::run()` with the panel's setting, and the response is a redirect back with `success` flashed as `Saved.`

## Testing

```php
use PandaPanel\Tables\TableSchema;

it('writes a cell', function (): void {
    $this->post('/admin/actions/cell', [
        'resource' => 'orders',
        'record' => $order->getKey(),
        'column' => 'status',
        'value' => 'done',
    ])->assertRedirect();

    expect($order->fresh()->status)->toBe('done');
});

it('carries the disabled state per record', function (): void {
    $schema = OrderResource::table(TableSchema::make());

    expect($schema->toRow($locked)['cells']['status']['disabled'])->toBeTrue();
});
```

## Gotchas

- **The row's `disabled` flag is per record, not per column.** Two rows of the same table can disagree, which is the whole point of `disabledUsing()`.
- **`boolean` does not accept `'yes'`.** The implied rule is Laravel's, so `true`, `false`, `1`, `0`, `'1'`, `'0'` are the accepted forms.
- **`writeTo()` does not change what the cell reads.** The column still resolves its value from its own name.
- **`updateUsing()` skips `save()` entirely.** If the closure does not persist anything, nothing is persisted.
- **Editable columns are not a substitute for the edit form.** They write one attribute with no lifecycle hooks and no form state; a record needing several fields changed together belongs on an edit page or in an action's form.
- **The endpoint carries no parent segment.** A nested resource must send `parent`, which the table does automatically from the `resource.parentKey` prop.

## See also

- [Columns](columns.md)
- [TableSchema basics](overview.md)
- [Record actions](record-actions.md)
- [Bulk actions](bulk-actions.md)
- [Resource authorization](../resources/authorization.md)
- [Form validation](../forms/validation.md)
- [Table API reference](api.md)
