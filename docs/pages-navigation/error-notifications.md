# Error Notifications

When a request from inside a panel fails, the panel shows a toast instead of Inertia's error overlay. The copy belongs to the panel and is keyed by HTTP status, so a 403 reads as a sentence the user can act on rather than as a modal containing an exception page.

Six statuses have defaults. A panel replaces the copy for one of them, adds one for a status the framework says nothing about, or silences a status entirely.

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
            ->errorNotification(403, 'Not your area', 'Ask an administrator for access.');
    }
}
```

A refused action inside the Admin panel now raises a toast reading **Not your area** with that description, and no overlay. Every other status keeps its default.

## The defaults

```php
private const DEFAULT_ERROR_NOTIFICATIONS = [
    403 => ['title' => 'Not allowed', 'body' => 'You do not have permission to do that.'],
    404 => ['title' => 'Not found', 'body' => 'That record no longer exists.'],
    419 => ['title' => 'Session expired', 'body' => 'Refresh the page and try again.'],
    429 => ['title' => 'Too many requests', 'body' => 'Wait a moment and try again.'],
    500 => ['title' => 'Something went wrong', 'body' => 'The request could not be completed.'],
    503 => ['title' => 'Temporarily unavailable', 'body' => 'The application is down for maintenance.'],
];
```

| Status | Title | Body |
| --- | --- | --- |
| 403 | Not allowed | You do not have permission to do that. |
| 404 | Not found | That record no longer exists. |
| 419 | Session expired | Refresh the page and try again. |
| 429 | Too many requests | Wait a moment and try again. |
| 500 | Something went wrong | The request could not be completed. |
| 503 | Temporarily unavailable | The application is down for maintenance. |

```php
$notifications = panel('admin')->getErrorNotifications();

array_keys($notifications);       // [403, 404, 419, 429, 500, 503]
$notifications[403]['title'];     // 'Not allowed'
```

## The methods

```php
public function errorNotification(int $status, string $title, ?string $body = null): self;
public function hideErrorNotification(int $status): self;

/** @return array<int, array{title: string, body: string|null}|null> */
public function getErrorNotifications(): array;
```

### `errorNotification()`

Replaces — or adds — the notification for one status. `getErrorNotifications()` merges the panel's own entries over the framework defaults with `array_replace()`, so a panel customizes one status without restating the rest.

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('custom')
    ->errorNotification(403, 'Nope', 'Ask an administrator.');

$panel->getErrorNotifications()[403];            // ['title' => 'Nope', 'body' => 'Ask an administrator.']
$panel->getErrorNotifications()[404]['title'];   // 'Not found' — untouched
```

`$body` is optional:

```php
$panel->errorNotification(422, 'Check the form');
// ['title' => 'Check the form', 'body' => null]
```

A status with no default can be added the same way — 402, 423, 451, anything the application raises:

```php
$panel->errorNotification(402, 'Payment required', 'Your plan does not include this.');
```

### `hideErrorNotification()`

Records the status as `null`, which is a third outcome and not the same as leaving it out: no toast **and** no overlay. For a status the application handles itself.

```php
$panel = Panel::make('quiet')->hideErrorNotification(404);

$panel->getErrorNotifications()[404];                  // null
$panel->toSharedArray()['errorNotifications'][404];    // null
```

## The three outcomes

The whole mechanism is a lookup with three answers:

| Entry for the status | Toast | Inertia's overlay |
| --- | --- | --- |
| An array | shown | suppressed |
| `null` — set with `hideErrorNotification()` | none | suppressed |
| Absent — never configured | none | left alone |

The third row is the important one. A status the panel has nothing to say about is better shown raw than swallowed: an unexpected 502 during development should produce Inertia's overlay, not silence.

## What crosses the wire

The map is part of the panel's shared props, keyed by status:

```php
'errorNotifications' => [
    403 => ['title' => 'Not allowed', 'body' => 'You do not have permission to do that.'],
    404 => null,
    // …
],
```

```ts
export interface PanelErrorNotification {
    title: string;
    body: string | null;
}

export interface PanelDefinition {
    // …
    errorNotifications: Record<string, PanelErrorNotification | null>;
}
```

PHP integer keys become JSON string keys, which is why the composable looks them up with `String(status)`. Nothing executable is in there — the framework's own test walks the serialized map asserting no value is a `Closure`.

## The frontend

`resources/js/panel/composables/useErrorNotifications.ts` registers one Inertia listener:

```ts
stop = router.on('httpException', (event) => {
    const status = event.detail.response.status;
    const notification = notificationFor(status);

    if (notification === undefined) {
        return;                       // no entry: leave Inertia alone
    }

    if (notification !== null) {
        toast.error(notification.title, {
            description: notification.body ?? undefined,
        });
    }

    return false;                     // cancels Inertia's own handling
});
```

Returning `false` from the handler is what cancels the overlay. The listener is torn down on unmount.

It is called once, in `PanelLayout.vue`:

```ts
// Registered on the shell rather than per page, so one listener covers every
// panel route and is torn down when the panel is left.
useErrorNotifications();
```

So every page inside the panel is covered, and nothing outside the panel is. A page that renders its own layout instead of `PanelLayout` gets no interception.

Toasts are rendered by `vue-sonner` through the `<Toaster />` in the panel shell — the same channel flash messages use. See [Toast notifications](../notifications/toast.md).

## Choosing what to say

The copy is user-facing, and each default is written for what actually happened:

- **403** is the panel's most common failure, because every action, page and widget authorizes independently. Say who to ask, not what was denied.
- **419** is a session or CSRF expiry, which a reload fixes. Say so.
- **429** comes from throttling middleware — panel login throttling, or an application rate limit on an action.
- **500** and **503** are the two the user cannot act on; keep them short.

A panel with its own vocabulary should override rather than accept the generic wording:

```php
$panel
    ->errorNotification(403, 'Restricted', 'This record belongs to another branch.')
    ->errorNotification(404, 'Gone', 'Somebody deleted it while you were looking.')
    ->hideErrorNotification(419);   // the application redirects to login itself
```

## Gotchas

- **This is about failed requests, not about validation.** A 422 from a form is handled by the form, which renders field errors; adding a 422 entry would raise a toast on every failed validation.
- **Only statuses in the map are intercepted.** Everything else keeps Inertia's default behaviour, which in production is whatever your error pages render.
- **`hideErrorNotification()` hides more than the toast.** It also suppresses the overlay, so a hidden status shows the user nothing at all. Use it only where the application already handles the case.
- **The listener lives on the panel layout.** A custom page that opts out of `PanelLayout` opts out of this too.
- **Keys are integers in PHP and strings in JSON.** Read them with `String(status)` in any code of your own.
- **Panel-scoped, not global.** Two panels can word the same status differently, and nothing leaks between them because the map travels with the panel's shared props.

## See also

- [Prefetching](prefetching.md), [Full page URLs](urls.md)
- [Page authorization](authorization.md)
- [Toast notifications](../notifications/toast.md), [Flash bridge](../notifications/flash-bridge.md)
- [Panel API reference](../panels/api.md)
- [Authorization](../concepts/authorization.md)
- [Troubleshooting](../troubleshooting/packagist.md)
