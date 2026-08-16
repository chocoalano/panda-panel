# Flash Toast Bridge

Laravel code already writes `redirect()->with('success', 'Saved.')`. The panel frontend already listens on one toast channel, `flash.toast`. The flash bridge maps one onto the other, so an application has exactly one toast mechanism rather than two competing ones. You never call it — it is middleware on the `web` group — but you need to know its rules to predict which message wins.

## A minimal working example

```php
<?php

use Illuminate\Http\RedirectResponse;

Route::post('/orders/{order}/approve', function (Order $order): RedirectResponse {
    $order->approve();

    return back()->with('success', 'Order approved.');
})->middleware('web');
```

The next page renders with a green toast. Nothing imported anything from the panel.

## The middleware

`PandaPanel\Http\Middleware\ShareFlashToast` runs on every `web` request:

```php
public function handle(Request $request, Closure $next): Response
```

It reads four conventional session keys and writes the first non-empty one into Inertia's flash bag as `toast`:

```php
private const TYPES = ['error', 'warning', 'success', 'info'];
```

The order is severity, not alphabet. A request that somehow flashes both a success and an error surfaces the error:

```php
return redirect('/')
    ->with('success', 'Saved.')
    ->with('error', 'Went wrong.');

// The page renders: ['type' => 'error', 'message' => 'Went wrong.']
```

Only the first match is written — the loop breaks — so one request produces at most one toast.

Three conditions stop it doing anything:

| Condition | Behaviour |
| --- | --- |
| The request has no session | passes straight through |
| `Inertia::getFlashed($request)['toast']` is already set | leaves it alone |
| No `error`/`warning`/`success`/`info` key holds a non-empty string | writes nothing |

## Flashing a toast explicitly

An explicit toast always wins over the conventional keys, which is how you send one with a link:

```php
use Inertia\Inertia;

Inertia::flash('toast', [
    'type' => 'success',
    'message' => 'Your export of 1,204 records is ready.',
    'url' => route('panel.admin.export-file', ['file' => $file, 'exporter' => $exporter]),
    'urlLabel' => 'Download',
]);

return back();
```

| Key | Type | Required |
| --- | --- | --- |
| `type` | `'success'\|'error'\|'warning'\|'info'` | yes |
| `message` | `string` | yes |
| `url` | `string\|null` | no |
| `urlLabel` | `string\|null` | no — falls back to `Open` |

The frontend ignores a flashed URL unless it is relative or uses `http`, `https`, `mailto`, or
`tel`. That guard is shared with notification links, table cell links, search result URLs and action
links, so a stale or hostile flash value cannot become a `javascript:` navigation.

That shape is `FlashToast` in `frontend/host/types/ui.ts`, the one host-seam type the panel imports:

```ts
export interface FlashToast {
    type: 'success' | 'error' | 'warning' | 'info';
    message: string;
    url?: string | null;
    urlLabel?: string | null;
}
```

The conventional keys can only produce `type` and `message`. `url` and `urlLabel` require the explicit call, because a session string has nowhere to put them.

## Where the panel itself uses it

Two places, and both are worth copying:

**Every action.** `PanelActionController` answers a successful action with `back()->with('success', $action->getSuccessMessage())`, so an action's `successMessage()` becomes a toast through this bridge and nothing else.

**A synchronous export.** `ExportAction` persists the notification (so the file is findable later) but flashes the toast instead of broadcasting it, because the response carrying it is right there:

```php
Notification::make('export-ready')
    ->title($exporter::completedMessage($result['records']))
    ->success()
    ->icon('download')
    ->persistent()
    ->broadcast(false)          // the flash below is the toast
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

`ImportAction` does the same, and flashes an `info` toast — "Your import has started. You will be notified when it finishes." — for the run it hands to a queue.

## The frontend half

`resources/js/lib/flashToast.ts` registers a single Inertia listener:

```ts
import { initializeFlashToast } from '@/lib/flashToast';

initializeFlashToast();
```

```ts
import { safeUrl } from '@/lib/utils';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const data = (event as CustomEvent).detail?.flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        const url = safeUrl(data.url);

        toast[data.type](data.message, {
            action: url
                ? { label: data.urlLabel ?? 'Open', onClick: () => { window.location.href = url; } }
                : undefined,
        });
    });
}
```

Call it once, in the application's `resources/js/app.ts`. It listens on Inertia's `flash` event, so it covers every page in the application, not only panel pages. The toasts themselves are rendered by the `<Toaster />` that the panel shells mount.

## Registration and opting out

The middleware is registered by `PandaPanel\PandaPanelServiceProvider` onto the whole `web` group, alongside `ResetPanelContext`, `RedirectPanelHome` and `SharePanelData`:

```php
private const WEB_MIDDLEWARE = [
    ResetPanelContext::class,
    RedirectPanelHome::class,
    ShareFlashToast::class,
    SharePanelData::class,
];
```

It belongs to the group rather than to a panel route group because it has to run for redirects back *out* of a panel — the request that produced the message is not the request that renders it.

Turn the automatic registration off in `config/panda-panel.php` when you would rather place it yourself:

```php
'register_web_middleware' => false,
```

Then add it in `bootstrap/app.php`:

```php
use PandaPanel\Http\Middleware\ShareFlashToast;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [ShareFlashToast::class]);
})
```

## Notes

- **Only strings are read.** `->with('success', ['a', 'b'])` writes nothing: the value must be a non-empty string, and anything else is ignored rather than coerced.
- **`with()` on a non-redirect response does nothing useful here.** The bridge reads the session, so the message has to survive to the *next* request — which is what `redirect()->with()` and `back()->with()` do.
- **The flash bag is not props.** Inertia puts flash data beside `props` on the page object. In a test, read `$response->viewData('page')['flash']['toast']` rather than using the Inertia prop assertions.
- **Broadcast toasts do not pass through here.** A notification sent from a queued job arrives over the websocket and is handled by `usePanelBroadcasting`, not by this listener. The two produce the same toast on purpose.

## See also

- [Toast notifications](toast.md) — the transient channel this feeds
- [Broadcasting](broadcasting.md) — the other way a toast arrives
- [Actions overview](../actions/overview.md) — `successMessage()`, which becomes a flash
- [Import and export actions](../actions/import-export.md)
- [Middleware configuration](../configuration/middleware.md)
- [Testing notifications](testing.md)
