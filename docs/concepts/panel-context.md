# Panel Context

`PandaPanel\Support\PanelContext` holds the state that belongs to one request:
which panel it is in, and anything scoped alongside it. It is a container
singleton bound with `scoped()`, so it lives exactly as long as the request or
the test case. You reach for it — usually through the `panel()` helper —
whenever backend code needs to know which panel it is running inside.

## Reading the current panel

```php
$panel = panel();          // the panel for this request, or null
$admin = panel('admin');   // an explicit panel; throws if unknown

$panel?->getId();          // 'admin'
```

That is the whole common case. `panel()` is a global function autoloaded from
`src/Support/helpers.php`, guarded by `function_exists()` so an application
that defines its own is untouched.

```php
/**
 * @throws PandaPanel\Exceptions\PanelRegistrationException
 */
function panel(?string $id = null): ?Panel
```

| Call | Returns | Throws |
| --- | --- | --- |
| `panel()` | The resolved panel, or `null` outside one | never |
| `panel('admin')` | That panel | `PanelRegistrationException::unknownPanel()` |

The asymmetry is deliberate. "Which panel am I in" has a legitimate answer of
`null`, and every consumer must tolerate it. "Give me the panel called `nope`"
does not — an unknown id is a developer error, not a state to degrade through.

## When it is populated

`PandaPanel\Http\Middleware\ResolvePanel` sets it, inside the panel's route
group, after `auth` and before any controller:

```php
$this->manager->setCurrentPanel($panel);

abort_unless($panel->isAccessibleTo($request->user()), 403);

$panel->boot();
```

`PandaPanel\Http\Middleware\ResetPanelContext` clears it at the start of every
`web` request. That pairing is what makes "no current panel outside a panel"
true rather than accidentally true:

```php
$this->actingAs($user)->get('/admin');
panel()?->getId();          // 'admin'

$this->actingAs($user)->get('/dashboard');
panel();                    // null
```

Without the reset, a non-panel route would keep whatever the previous request
left behind. In a classic PHP request the container is rebuilt each time and
the leak is invisible; under Octane, or inside a test that issues several
requests, it is not.

## The API

```php
use PandaPanel\Support\PanelContext;

$context = app(PanelContext::class);
```

| Method | Signature | Notes |
| --- | --- | --- |
| `setPanel` | `setPanel(?Panel $panel): void` | Called by `ResolvePanel`. |
| `panel` | `panel(): ?Panel` | |
| `hasPanel` | `hasPanel(): bool` | |
| `set` | `set(string $key, mixed $value): void` | The generic bag. |
| `get` | `get(string $key, mixed $default = null): mixed` | |
| `forget` | `forget(): void` | Clears the panel *and* the bag. |

```php
$context->setPanel(app(PanelManager::class)->get('admin'));

$context->hasPanel();       // true
$context->panel()?->getId();// 'admin'

$context->set('report.range', 'quarter');
$context->get('report.range');          // 'quarter'
$context->get('report.currency', 'USD');// 'USD'

$context->forget();
$context->hasPanel();       // false
```

Nothing here is static. That is the single design rule the class exists to
enforce: static panel state leaks between requests under Octane and between
test cases in one process, and both failures look like a test that passes
alone and fails in a suite.

`PandaPanel\Core\PanelManager` delegates to it rather than holding state
itself:

```php
$manager->currentPanel();       // $context->panel()
$manager->hasCurrentPanel();    // $context->hasPanel()
$manager->setCurrentPanel($p);  // $context->setPanel($p)
```

## What else lives in the bag

Two framework features store their per-request value here rather than in a
static, for the same reason the panel does. Both expose their own accessor;
neither expects you to read the key directly.

### The parent record

`PandaPanel\Support\ParentRecord` stores under `panel.parent-record`.

```php
use PandaPanel\Support\ParentRecord;

ParentRecord::current();                     // ?Model
ParentRecord::require(PostResource::class);  // Model, or PanelRegistrationException
ParentRecord::routeParameter();              // 'parentRecord'
```

`ResolveParentRecord` binds it from the route; `Resource::query()` reads it.
Nothing else should — a nested resource that reached for the parent anywhere
but its query would have a second place where the scope could be forgotten.

`require()` throws rather than returning null because a nested resource with
no parent bound is a route registered wrongly, and answering an unscoped query
would show every child of every parent.

### The tenant

`PandaPanel\Tenancy\Tenancy` stores under `panel.tenant`.

```php
use PandaPanel\Tenancy\Tenancy;

Tenancy::current();   // ?Model
Tenancy::require();   // Model, or PanelRegistrationException
Tenancy::bind($team); // ResolveTenant and tests only
```

Same rule: a tenant-scoped resource with no tenant bound is a route registered
without `ResolveTenant`, and degrading through it would show every tenant's
records to whoever asked.

## Using it in your own code

The bag is there so a module can attach request scope without changing
`PanelContext`'s shape. Wrap it in a named accessor rather than sprinkling
string keys:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Support;

use PandaPanel\Support\PanelContext;

final class ReportRange
{
    private const KEY = 'app.report-range';

    public static function bind(string $range): void
    {
        app(PanelContext::class)->set(self::KEY, $range);
    }

    public static function current(): string
    {
        $range = app(PanelContext::class)->get(self::KEY);

        return is_string($range) ? $range : 'month';
    }
}
```

Bind it from middleware inside the panel group, or from a boot callback:

```php
$panel->bootUsing(static function (): void {
    ReportRange::bind(request()->string('range', 'month')->toString());
});
```

Boot callbacks run in `ResolvePanel`, after the access check, so they can
assume a panel is set and a user is allowed in.

## Notes

- `panel()` inside `SharePanelData` is null on every non-panel page, including
  the starter kit's own. Props are shared for every `web` request; only the
  panel ones are conditional.
- Values in the bag are `mixed`. Readers narrow (`$v instanceof Model`,
  `is_string($v)`) rather than casting — a value that is not what was expected
  should degrade to "nothing bound", not throw somewhere unrelated.
- `forget()` clears both the panel and the bag. There is no per-key forget; a
  request either has context or it does not.
- The context is bound with `scoped()`, not `singleton()`. Under Octane the
  container flushes scoped bindings between requests, so the instance itself
  is fresh — `ResetPanelContext` covers the case where it is not.
- A test that needs a panel without issuing a request can set one directly:
  `app(PanelContext::class)->setPanel(panel('admin'))`. Remember it stays set
  for the rest of that test.

## See also

- [Panels](panels.md)
- [Panel Providers](panel-providers.md)
- [Request Lifecycle](request-lifecycle.md)
- [Authorization](authorization.md)
- [Tenancy Concepts](../tenancy/concepts.md)
- [Nested Resources](../resources/nested-resources.md)
