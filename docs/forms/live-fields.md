# Live Fields

A field marked `live()` asks the server to rebuild the schema after it changes. You reach for it when one field depends on another in a way the declarative conditions cannot express — a select whose options come from the value of another select, a total computed from three inputs, a section that only exists once a type has been chosen. It is off by default, because a round trip per keystroke is the wrong default.

## A minimal example

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    $country = request()->input('state.country');

    return $schema->schema([
        Select::make('country')
            ->options(['id' => 'Indonesia', 'sg' => 'Singapore'])
            ->live(),

        Select::make('region')->options(match ($country) {
            'id' => ['jkt' => 'Jakarta', 'bdg' => 'Bandung'],
            'sg' => ['central' => 'Central', 'east' => 'East'],
            default => [],
        }),

        TextInput::make('note')->live(onBlur: true, debounce: 1000),
    ]);
}
```

Changing `country` posts the current values to the panel's `form-state` endpoint, `form()` runs again with those values available on the request, and the new schema replaces the old one on screen. The values the user has already typed are kept.

## `live()`

```php
public function live(bool $onBlur = false, ?int $debounce = null): static
```

| Argument | Default | Effect |
| --- | --- | --- |
| `$onBlur` | `false` | Waits until focus leaves the control instead of debouncing |
| `$debounce` | `500` (ms) | How long after the last change the request is made |

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;

Select::make('kind')->live();                              // 500ms debounce
TextInput::make('slug')->live(debounce: 1000);             // slower
TextInput::make('vat_number')->live(onBlur: true);         // only once they leave it
```

`Field::isLive(): bool` reports the flag. The serialized field carries `live` as `['onBlur' => bool, 'debounce' => int]`, or `null` when the field is not live — which is how the frontend knows to do nothing.

```php
Select::make('kind')->live(onBlur: true, debounce: 250)->toArray(null, 'create')['live'];
// ['onBlur' => true, 'debounce' => 250]
```

## `afterStateUpdated()`

```php
public function afterStateUpdated(Closure $callback): static
// Closure(mixed $new, mixed $old, ?Model $record): void
```

Runs on the server when a live field's value changes, before the schema is rebuilt. For side effects and for deciding what other fields should become — it returns nothing, because a hook that both mutated and returned would leave two places to change a value.

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\Select;

Select::make('plan')
    ->options(['free' => 'Free', 'pro' => 'Pro'])
    ->live()
    ->afterStateUpdated(static function (mixed $new, mixed $old, ?Model $record): void {
        logger()->info('plan changed', ['from' => $old, 'to' => $new]);
    });
```

It runs only for a field that declared itself `live()`, whatever the request claims changed. `Field::handleStateUpdated(mixed $state, mixed $previous, ?Model $record = null): void` invokes it directly, which is how a test drives it:

```php
$field = TextInput::make('a')->live()->afterStateUpdated($hook);

$field->handleStateUpdated('new', 'old');
```

## The endpoint

Route name `panel.{panel_id}.form-state`, registered per panel, handled by `PandaPanel\Http\Controllers\PanelFormStateController`.

| Part | Where it comes from | Values |
| --- | --- | --- |
| `resource` | query string | the resource slug in this panel; 422 if missing, 404 if unknown |
| `page` | query string | `edit`, or `create` for anything else |
| `record` | query string | required when `page=edit`; 422 if missing, 404 if not found |
| `state` | request body | the values typed so far, keyed by field name |
| `changed` | request body | the field that changed |
| `previous` | request body | its previous value, passed to the hook |

The URL is built on the server by `PandaPanel\Support\FormEndpoints::formState()` and sent to the page as `formStateUrl`. Everything that says *what this form is* travels in the URL; the browser contributes only the values and which field changed, so a keystroke can never change which form is being asked about.

The response is JSON:

```json
{ "form": { "columns": 2, "schema": [ { "component": "field", "name": "region", "…": "…" } ] } }
```

which is `FormSchema::toArrayWithState($record, $state)` — the schema rebuilt, with the submitted values applied over the field values.

### What the endpoint will not do

- **It validates nothing.** No rules are run and no errors are returned.
- **It writes nothing.** Asking what a form looks like is not a submit.
- **It authorizes.** Describing a create form requires `canCreate()`; describing an edit form requires `canEdit($record)`. Both are asked before the schema is built, because building it runs the schema's own closures.
- **It narrows the state.** Only keys the schema declares are read out of `state`; anything else is discarded before any hook sees it.

That separation is what makes it safe to call on every keystroke of a live field: the worst a crafted request can do is ask what a form looks like, which it could see by opening the page.

## What the browser does

`resources/js/panel/forms/FormRenderer.vue` owns the timing.

- A change to a live field starts a timer of `debounce` milliseconds, replacing any timer already running for that field.
- With `onBlur`, no timer is started; the request is made when focus leaves the control. Every control carries the field name as its DOM `id`, so one `focusout` listener on the form catches all of them.
- Only one request is in flight. A new one aborts the previous, because an earlier answer arriving second would put the form back to how it looked two keystrokes ago.
- On success the schema is replaced. Fields the rebuilt schema introduced are given their serialized value; fields the user has already typed into keep theirs.
- On any failure the form is left exactly as it was. Rebuilding is an enrichment, and losing it must not cost the user what they have entered.
- A response whose shape does not match is treated as a failure rather than asserted into place.

## Reading the submitted values in `form()`

The endpoint re-runs `Resource::form()`, so anything that closure reads from the request is available. The values arrive under `state`:

```php
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    /** @var array<string, mixed> $state */
    $state = (array) request()->input('state', []);

    return $schema->schema([
        Select::make('category')->relationship('category', 'name')->live(),

        Select::make('subcategory')->options(
            Subcategory::query()
                ->where('category_id', $state['category'] ?? null)
                ->pluck('name', 'id')
                ->all(),
        ),
    ]);
}
```

On the first render there is no `state`, so the dependent field starts with whatever its fallback produces. That is the same code path a live rebuild takes, which is what keeps the two from disagreeing.

## Where live fields work

`formStateUrl` is provided by the resource create and edit pages, and by nothing else. A `live()` field inside an [action dialog](../actions/forms.md), a [relation form](../relations/relation-forms.md), or a [widget filter](../widgets/filters.md) has no endpoint to ask, so it behaves as an ordinary field — no request, no rebuild, no `afterStateUpdated()`.

## Notes

- **Prefer a condition when one will do.** `visibleWhen()` and `hiddenWhen()` re-evaluate in the browser and cost no request at all. `live()` is for what they cannot express. See [Field visibility](visibility.md).
- **A rebuild replaces the schema, not the values.** A field the rebuild removed keeps its value in the working set until the form is submitted, where it is discarded for having no field.
- **`afterStateUpdated()` never runs on submit.** It belongs to this endpoint alone.
- **`previous` comes from the browser.** It is the value the field held before the change that triggered this request, and it is passed to the hook as-is — treat it as untrusted input like any other.
- **A live field on a wizard step still rebuilds the whole form.** The endpoint answers with the entire schema, steps included.
- **Cost is per field, not per form.** A form with one live select makes one request when that select changes and none otherwise.

## See also

- [Field visibility](visibility.md)
- [Field state lifecycle](state-lifecycle.md)
- [Hydration and dehydration](hydration.md)
- [Options endpoints](options-endpoints.md)
- [FormSchema basics](overview.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Request lifecycle](../concepts/request-lifecycle.md)
