# Panel Search Configuration

A resource decides *what* is searchable; the panel decides whether there is a palette at all, how many hits one search may return, how long the palette waits before asking, and which keys open it. All four live on one method: `Panel::globalSearch()`.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->auth()
            ->discoverResources(app_path('Panels/Admin/Resources'))
            ->globalSearch(
                enabled: true,
                limit: 50,
                debounce: 300,
                keyBindings: ['mod+k'],
            );
    }
}
```

Those are the defaults, spelled out. A panel that never calls `globalSearch()` behaves exactly like this one.

## The method

```php
namespace PandaPanel\Core;

/**
 * @param  list<string>  $keyBindings
 */
public function globalSearch(
    bool $enabled = true,
    int $limit = 50,
    int $debounce = 300,
    array $keyBindings = ['mod+k'],
): self;
```

| Argument | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$enabled` | `bool` | `true` | whether this panel has a palette |
| `$limit` | `int` | `50` | hits across the whole search, not per resource |
| `$debounce` | `int` | `300` | milliseconds the palette waits after the last keypress |
| `$keyBindings` | `list<string>` | `['mod+k']` | shortcuts that open the palette |

Every argument has a default, so any subset can be named:

```php
$panel->globalSearch(limit: 20);              // keep the rest
$panel->globalSearch(false);                  // off entirely
$panel->globalSearch(debounce: 500, keyBindings: ['mod+k', 'mod+shift+f']);
```

Note that this is a setter, not an accumulator: calling it twice replaces all four values with the second call's arguments, defaults included. `->globalSearch(limit: 20)` followed by `->globalSearch(debounce: 500)` leaves the limit back at 50.

## The readers

```php
public function hasGlobalSearch(): bool;              // default true
public function getGlobalSearchLimit(): int;          // default 50
public function getGlobalSearchDebounce(): int;       // default 300
public function getGlobalSearchKeyBindings(): array;  // list<string>, default ['mod+k']
```

```php
use PandaPanel\Core\PanelManager;

$panel = app(PanelManager::class)->get('admin');

$panel->hasGlobalSearch();             // true
$panel->getGlobalSearchLimit();        // 50
$panel->getGlobalSearchKeyBindings();  // ['mod+k']
```

They are what `PandaPanel\Search\GlobalSearch` and `PandaPanel\Http\Middleware\SharePanelData` read; nothing else consults the private properties.

## Enabled

`hasGlobalSearch()` is only half the answer. What reaches the frontend is:

```php
$enabled = $panel->hasGlobalSearch() && $searchable;
```

where `$searchable` is true when at least one resource registered in the panel returns true from `isGloballySearchable()`. A palette that could only ever answer nothing is worse than no palette, so a panel with search on and nothing searchable shows no palette at all — not even the header button.

```php
$panel->globalSearch(false);
```

Turning it off has two effects. The shared prop `search.enabled` is false and `search.url` is null, so the palette renders nothing; and `GlobalSearch::for()` returns `[]` for that panel even when called directly. The route still exists — it is registered for every panel — but it answers `{"groups": []}`.

## Limit

The panel's limit is a budget for one search, spent in resource order:

```php
$remaining = $panel->getGlobalSearchLimit();

foreach ($resources as $resource) {
    if ($remaining <= 0) {
        break;
    }

    $results = $this->search($resource, $term, min($resource::globalSearchLimit(), $remaining));

    if ($results === []) {
        continue;
    }

    $remaining -= count($results);
}
```

Worked through, with `limit: 6` and three resources each allowing 5:

| Order | Resource | Asked for | Returned | Remaining |
| --- | --- | --- | --- | --- |
| 1 | Users (`sort` 0) | `min(5, 6)` = 5 | 5 | 1 |
| 2 | Orders (`sort` 10) | `min(5, 1)` = 1 | 1 | 0 |
| 3 | Posts (`sort` 20) | — | not reached | 0 |

Two lessons follow. A generous per-resource limit early in the sort order starves everything after it, and a resource that contributes nothing costs nothing — no hits means no deduction and no `break`.

Sizing it is a judgement about the dialog, which scrolls at about `max-h-96`: 50 hits is more than a user will read, and the point of the cap is to bound the query, not to fill the list. Lower it on a panel with many resources so every group gets a turn.

## Debounce

Milliseconds between the last keypress and the request. The palette also refuses to ask for fewer than two characters, so a short word costs one request rather than several.

```php
$panel->globalSearch(debounce: 500);   // a heavy search, or a busy database
$panel->globalSearch(debounce: 150);   // a small dataset, snappier feel
```

Requests do not queue: a new keystroke aborts the in-flight fetch through an `AbortController`, so a slow early answer can never overwrite a fast later one. The debounce is about how many queries the database is asked to run, not about correctness.

## Key bindings

```php
$panel->globalSearch(keyBindings: ['mod+k', 'mod+shift+f']);
```

A binding is a `+`-separated string. The **last** segment is the key, compared case-insensitively against the browser's `KeyboardEvent.key`; every earlier segment is a modifier:

| Modifier | Matches |
| --- | --- |
| `mod` | `metaKey` or `ctrlKey` — the platform's command key |
| `shift` | `shiftKey` |
| `alt` | `altKey` |

Anything else — `ctrl`, `cmd`, `meta`, `option` — is not understood and makes the binding **never** match. Use `mod`.

```php
// Works.
$panel->globalSearch(keyBindings: ['mod+k']);
$panel->globalSearch(keyBindings: ['mod+shift+p']);
$panel->globalSearch(keyBindings: ['mod+k', 'mod+/']);

// Never fires: 'ctrl' is not a recognised modifier name.
$panel->globalSearch(keyBindings: ['ctrl+k']);

// Empty list: the header button still opens the palette.
$panel->globalSearch(keyBindings: []);
```

A binding checks only the modifiers it names. `mod+k` therefore also matches `⌘⇧K`; add `shift` explicitly if you want the two to be different shortcuts.

## Different panels, different palettes

Settings are per panel, like everything else on `Panel`:

```php
// Admin: many resources, a small budget so every group is represented.
$panel->globalSearch(limit: 20, debounce: 250);

// A customer-facing app panel with one searchable resource.
$panel->globalSearch(limit: 5, keyBindings: ['mod+k', 'mod+p']);

// An operations panel where search would be noise.
$panel->globalSearch(false);
```

## What the frontend receives

`SharePanelData` ships this on every panel request:

```php
[
    'enabled' => $enabled,
    'url' => $enabled ? route($panel->routeName('search'), absolute: false) : null,
    'debounce' => $panel->getGlobalSearchDebounce(),
    'keyBindings' => $panel->getGlobalSearchKeyBindings(),
]
```

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { search } = usePanel();

search.value.enabled;      // boolean
search.value.url;          // '/admin/search' | null
search.value.debounce;     // 300
search.value.keyBindings;  // ['mod+k']
```

The URL is a relative path built from the route name `panel.{panelId}.search`, never a string the frontend assembles. `debounce` and `keyBindings` are sent even when `enabled` is false; only `url` is nulled, because there is nothing to ask.

Assert it in a test:

```php
use Inertia\Testing\AssertableInertia;

$this->get('/admin')
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->where('search.enabled', true)
        ->where('search.url', '/admin/search')
        ->where('search.debounce', 300)
        ->where('search.keyBindings', ['mod+k']));
```

## Gotchas

- **`globalSearch()` replaces all four values.** Two calls are not additive; state everything in one call.
- **`enabled: true` does not guarantee a palette.** No searchable resource means no palette, and that is the intended behaviour rather than a misconfiguration.
- **A modifier-less binding fires while typing.** The listener is on `window` and does not inspect the event target, so `keyBindings: ['/']` opens the palette from inside a form field. Keep a modifier in every binding.
- **`ctrl+k` never matches.** Only `mod`, `shift` and `alt` are recognised; write `mod+k`.
- **The `search` route exists even when the palette is off,** and answers an empty result. Nothing leaks — `GlobalSearch::for()` checks `hasGlobalSearch()` first — but do not read the route's existence as "search is on".
- **There is no rate limit.** The route inherits the panel's middleware and nothing else; add `throttle:60,1` to the panel's stack if the endpoint needs one.
- **`panel:cache` caches which classes a panel owns, not its settings.** Changing `globalSearch()` takes effect on the next request; adding a *new* searchable resource to a cached application does not, until `php artisan panel:cache` runs again (or `php artisan panel:clear` removes the manifest).

## See also

- [Global search overview](overview.md)
- [Searchable resources](searchable-resources.md)
- [Search security](security.md)
- [Search result URLs](result-urls.md)
- [Defining panels](../panels/defining-panels.md)
- [Panel API reference](../panels/api.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Panel caching](../panels/cache.md)
