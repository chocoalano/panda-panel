# Exceptions Reference

Four exception classes, each named after the kind of mistake it reports. Reach for this page when a panel throws and you want to know what the framework was actually objecting to — every message names the class, the value that is wrong, and what to do about it.

| Class | Extends | Reports |
| --- | --- | --- |
| `PandaPanel\Exceptions\PanelRegistrationException` | `RuntimeException` | Panel, resource, page, plugin, or route registration that is ambiguous |
| `PandaPanel\Exceptions\PanelSchemaException` | `InvalidArgumentException` | A table, form, action, or exporter that cannot mean what it says |
| `PandaPanel\Exceptions\PanelAuthorizationException` | `RuntimeException` | Under `strictAuthorization()`, an ability no policy can answer |
| `PandaPanel\Exceptions\Halt` | `RuntimeException` | A page lifecycle that stopped on purpose |

## Catching one

```php
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Facades\PandaPanel;

try {
    $panel = PandaPanel::get('reports');
} catch (PanelRegistrationException $e) {
    report($e);

    abort(404);
}
```

In practice you rarely catch these. The first two are developer errors that must fail loudly during boot and during `panel:cache` rather than degrade at runtime; the third is a development aid; the fourth is caught by the page that threw it.

## `PanelRegistrationException`

Registration ambiguity. Every one of these is a mistake that would otherwise surface as a route silently shadowing another, or a link pointing at a route that was never registered.

### Panels

```php
public static function missingPanelId(): self;
public static function duplicatePanelId(string $id): self;
public static function duplicatePanelPath(string $path, ?string $domain, string $existingId): self;
public static function unknownPanel(string $id): self;
public static function noCurrentPanel(): self;
```

| Raised by | When |
| --- | --- |
| `Panel::getId()` | `Panel::make()` was called with no id and `->id()` was never called |
| `PanelRegistry::register()` | a second panel claims an id, or a path and domain pair |
| `PanelRegistry::get()` | `panel('typo')` or `PandaPanel::get('typo')` |
| `Resource::resolvePanel()`, `Page::resolvePanel()`, `ResourcePage::panel()`, `PanelDashboardController` | a URL or a page was asked for outside a panel request |

`noCurrentPanel()` is the one you will meet in a console command or a queued job:

```text
There is no current panel for this request. Resolve one through panel
middleware or pass an explicit panel.
```

The fix is either an explicit panel — `PostResource::url('edit', $post, panel: 'admin')` — or binding one:

```php
app(PanelManager::class)->setCurrentPanel(PandaPanel::get('admin'));
```

### Resources, pages, widgets

```php
public static function duplicateResourceSlug(string $slug, string $existing, string $incoming): self;
public static function duplicatePageSlug(string $slug, string $existing, string $incoming): self;
public static function slugCollidesWithResource(string $slug, string $page, string $resource): self;
public static function duplicateWidgetId(string $id, string $existing, string $incoming): self;
public static function resourceNotInPanel(string $resource, string $panelId): self;
public static function collidingRoutePath(string $path, string $existing, string $incoming): self;
```

`resourceNotInPanel()` is what makes panel isolation real: asking a resource for a URL in a panel that does not register it fails loudly rather than producing a link to a route that does not exist.

`collidingRoutePath()` catches two resources claiming one path *shape*. Parameter names are erased before comparing, because the router does not distinguish `{record}` from `{parentRecord}` either:

```text
The path [projects/{}/tasks] is registered by both [App\...\TaskResource] and
[App\...\ProjectTasksPage]. Only the first would ever match. Give one of them a
different slug or route path.
```

### Relations and nesting

```php
public static function unknownRelation(string $model, string $relation, string $manager): self;
public static function duplicateRelationKey(string $key, string $resource): self;
public static function unregisteredParentResource(string $resource, string $parent): self;
public static function noParentRecord(string $resource): self;
```

`noParentRecord()` means a nested resource was reached without `ResolveParentRecord` having bound one — normally a route registered by hand, or `Resource::url()` called for a nested resource outside its own pages without a `parent:` argument.

### Tenancy

```php
public static function noCurrentTenant(): self;
public static function unknownTenantRelationship(string $resource, string $model, string $relation): self;
public static function tenantRelationshipIsNotARelation(string $resource, string $model, string $relation): self;
```

`noCurrentTenant()` is raised by `Tenancy::require()` rather than answered with an unscoped query, which would show every tenant's records to whoever asked:

```text
This panel is tenant-scoped, but no tenant is bound to this request. Routes
registered by the panel resolve one through ResolveTenant; a route registered
by hand has to include that middleware, and console or queue work has to enter
a tenant with Tenancy::for().
```

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::for($team, function (): void {
    // Resource::query() is scoped here.
});
```

`tenantRelationshipIsNotARelation()` catches a `$tenantRelationship` naming a scope or an accessor. The method exists, so the first check passes, and then `whereHas` fails inside Eloquent with an error about `getRelated()` on null that names neither the resource nor the property that pointed at it.

### Plugins

```php
public static function duplicatePlugin(string $id, string $panelId): self;
public static function incompatiblePlugin(
    string $name,
    string $id,
    string $panelId,
    string $constraint,
    string $installed,
): self;
```

## `PanelSchemaException`

A schema that cannot mean what it says. These were all, until they were caught, mistakes that produced no error at all: two columns with the same name serialized as two and then fought over one key in the row map; two form fields collapsed into one validation rule and quietly left the other unvalidated; two actions gave the endpoint a choice it resolved by taking the first.

```php
public static function missingModel(string $resource): self;
public static function emptyName(string $what): self;
public static function unusableActionName(string $name): self;
public static function inertAction(string $name): self;
public static function duplicateColumns(array $names): self;
public static function duplicateFields(array $names): self;
public static function duplicateActions(string $set, array $names): self;
public static function duplicateFilters(array $names): self;
public static function duplicateExportColumns(array $names): self;
public static function unknownDefaultSort(string $column, array $available): self;
public static function unusableColumnSpan(string $context, string $value): self;
public static function unknownBreakpoints(string $context, array $unknown, array $known): self;
```

### `missingModel()`

The single most common thing to leave out of a new resource. PHP's own message — "Typed static property `PandaPanel\Resources\Resource::$model` must not be accessed before initialization" — names this base class rather than the one that forgot, and does not say what to add.

```text
[App\Panels\Admin\Resources\Posts\PostResource] does not declare a model. Add
one to the resource:

    protected static string $model = YourModel::class;

Everything the resource does — its query, its pages, its policy checks —
starts from it.
```

### `unusableActionName()`

An action name travels to the endpoint as an identifier, so it may contain letters, numbers, dashes, dots, and underscores and nothing else. A space or a slash renders as a button that fails only when pressed. The message suggests a corrected name.

```php
Action::make('approve order');
// The action name [approve order] cannot be used. ... try [approve-order].
```

### `inertAction()`

```php
Action::make('approve');   // no url, no action, no form, no modal
```

```text
The action [approve] does nothing. Give it ->url() to make it a link,
->action() to make it a callback, ->form() to make it open a form, or ->modal()
to make it open a modal. As it stands it renders a button that responds to
being pressed by doing nothing at all.
```

Checked where a set of actions is declared rather than per row, so it is refused once at definition time instead of being drawn for every record.

### The duplicate family

| Method | Raised by | Why it matters |
| --- | --- | --- |
| `duplicateColumns()` | `TableSchema::columns()` | one name keys the cell, the visibility, the search term, and the sort |
| `duplicateFields()` | `FormSchema::validationRules()`, `toArray()` | only one rule and one value survive; the other field is submitted and discarded |
| `duplicateActions()` | every action setter on `TableSchema` | the endpoint resolves by name and would always run the first |
| `duplicateFilters()` | `TableSchema::filters()` | filter state is keyed by name in the query string |
| `duplicateExportColumns()` | `ExportRun::write()` | the picker keys its selection by name, so ticking one ticks both |

`duplicateFields()` is checked *after* relation prefixes are applied, because a `Relationship` group namespaces its children: `profile.bio` and `bio` are two names.

The fix is usually a rename, or `dehydrateTo()` when two inputs really do write the same column.

### `unknownDefaultSort()`

```php
$table->columns([TextColumn::make('title')])->defaultSort('created_at');
```

```text
defaultSort() names [created_at], which is not a column of this table. It has:
title. The table would fall back to its natural order and nothing would say
why.
```

### `unusableColumnSpan()` and `unknownBreakpoints()`

Both come from `PandaPanel\Widgets\Support\ColumnSpan`, which resolves a widget's place in the dashboard grid. A span that is neither a number nor `"full"` would otherwise be read as `1` — a quarter of the width that was asked for, with nothing to say why. A breakpoint key the grid does not have is a line of configuration that does nothing, so it is named rather than ignored.

### `emptyName()`

Raised by the `Column`, `Field`, `Action`, `ExportColumn`, and `ImportColumn` constructors. The article is chosen so the message is not itself a typo — "An action", not "A action".

## `PanelAuthorizationException`

```php
public static function missingPolicy(string $model, string $ability): self;
public static function missingPolicyMethod(string $policy, string $model, string $ability): self;
```

Raised only under `Panel::strictAuthorization()`, from `PandaPanel\Support\PolicyGate`.

A missing policy and a policy that refuses look identical from the outside: both are a 403. That is correct in production and unhelpful while building, where a forgotten policy reads as a working authorization rule. Strict mode separates the two — at the cost of turning a 403 into a 500, which is why it is off by default.

```php
$panel->strictAuthorization();   // development only
```

```text
No policy is registered for [App\Models\Post], so the ability [viewAny] can
only ever be denied. Register one, or turn off strictAuthorization() for this
panel.
```

```text
The policy [App\Policies\PostPolicy] for [App\Models\Post] does not define
[restoreAny], so that ability can only ever be denied. Add the method, or turn
off strictAuthorization() for this panel.
```

The check lives in `PolicyGate` rather than in `Resource::authorize()` because relation managers ask abilities a resource has no method for, and two copies of the rule would be two places to keep it true.

## `Halt`

```php
final class Halt extends RuntimeException
{
    public static function make(): self;
}
```

Thrown by `$this->halt()` from any lifecycle hook on a resource page. The page catches it and returns the user where they came from, having written nothing.

```php
protected function beforeCreate(): void
{
    if (Order::whereDate('created_at', today())->count() >= 100) {
        session()->flash('warning', 'The daily order limit has been reached.');

        $this->halt();
    }
}
```

Deliberately **not** an HTTP exception: a halt is a decision the page made, not an error, and it must not surface as a 500 or leak a stack trace. A hook that wants to explain itself flashes a notification before halting.

Where it is caught:

| Page | Halting during | Result |
| --- | --- | --- |
| `CreateRecord::render()` | `beforeFill` / `mutateFormDataBeforeFill` | redirect to the resource index |
| `CreateRecord::handle()` | any create hook | `back()` |
| `EditRecord::render()` | any fill hook | redirect to the resource index |
| `EditRecord::handle()` | any save hook | `back()` |

`halt()` is not a rollback. It stops the lifecycle *before* anything is written; a hook that needs to undo a write should throw instead, and the transaction the persist step and the `after*` hooks share will roll it back.

## Exceptions from outside the package

Two Laravel exceptions surface often enough to be worth naming.

| Exception | Raised by | Becomes |
| --- | --- | --- |
| `Illuminate\Database\Eloquent\ModelNotFoundException` | `Resource::resolveRecord()`, `resolveSingularRecord()` | a 404 |
| `Symfony\Component\HttpKernel\Exception\HttpException` | `Action::executeBulk()`, `DeleteBulkAction` | a 403 when one selected record is refused |

`Action::executeBulk()` authorizes every record before touching any, so a selection containing one forbidden record changes nothing rather than half-applying:

```text
You may not delete every selected record.
```

## Error notifications

An HTTP status that reaches the frontend is turned into a notification by the panel rather than an error page. The defaults:

| Status | Title | Body |
| --- | --- | --- |
| 403 | Not allowed | You do not have permission to do that. |
| 404 | Not found | That record no longer exists. |
| 419 | Session expired | Refresh the page and try again. |
| 429 | Too many requests | Wait a moment and try again. |
| 500 | Something went wrong | The request could not be completed. |
| 503 | Temporarily unavailable | The application is down for maintenance. |

```php
$panel
    ->errorNotification(403, 'Not your team', 'Ask an owner for access.')
    ->hideErrorNotification(419);
```

A status absent from the list keeps Inertia's own error handling. `hideErrorNotification()` silences one entirely — no notification and no overlay — for a status the application handles itself.

## Notes

- **These fail at boot, not at request time.** `PanelRegistrationException` is raised while providers register and while `panel:cache` runs, which is where a mistake is actually visible.
- **A provider class that no longer resolves is skipped rather than throwing.** Fatalling during boot would happen before any route existed to show the error; `panel:cache` reports the same list instead.
- **`PanelSchemaException` extends `InvalidArgumentException`.** It is an argument mistake, not a runtime condition, and static analysis treats it accordingly.
- **`Halt` should never be caught by application code.** The pages that can act on it already do.
- **Strict authorization changes a 403 into a 500.** Turn it on in development, not in production.

## See also

- [Core API reference](core.md)
- [Resources reference](resources.md)
- [Tables reference](tables.md)
- [Forms reference](forms.md)
- [Actions reference](actions.md)
- [Plugins reference](plugins.md)
- [Authorization](../concepts/authorization.md)
- [Resource authorization](../resources/authorization.md)
- [Lifecycle hooks](../resources/lifecycle-hooks.md)
- [Error notifications](../pages-navigation/error-notifications.md)
- [Troubleshooting](../troubleshooting/packagist.md)
