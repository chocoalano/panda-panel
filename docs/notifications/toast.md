# Toast Notifications

A toast is the transient half of a panel notification: a short message that appears over whatever page is on screen and is gone a few seconds later. It is right for "Saved." and wrong for a job that finished ten minutes after the request that started it — if nobody was looking, a toast never happened. Reach for one when the notification answers something the user just did; reach for [database notifications](database.md) when it has to survive a closed tab.

## A minimal working example

```php
<?php

use PandaPanel\Notifications\Notification;

Notification::make('saved')
    ->title('Saved.')
    ->success()
    ->send($request->user());
```

That broadcasts and stores nothing. The user's open panels show a green toast; a user who was away sees nothing, which is exactly what "Saved." deserves.

## The three destinations

`Notification` is one object with three possible destinations, and they compose:

| Destination | Turned on by | What happens |
| --- | --- | --- |
| Toast | on by default | `PanelNotificationSent` is dispatched on the user's private channel |
| Database | `->persistent()` | a row is written through Laravel's `notifications` table |
| Both | `->persistent()` alone | persisted first, then broadcast |

```php
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('1,204 records')
    ->success()
    ->persistent()                 // also store it, so it can be read later
    ->actions([
        NotificationAction::make('download')
            ->label('Download')
            ->url('/admin/exports/users.csv'),
    ])
    ->send($user);
```

Notification action URLs are sanitized before they are stored or broadcast. Relative URLs and
`http`, `https`, `mailto`, and `tel` are kept; unsafe schemes are dropped, and the Vue notification
components run the same guard before opening a link.

`->persistent()` is off by default. Most notifications answer something the user just did, and a bell that fills up with "Saved." is a bell nobody reads.

`->broadcast(false)` turns the toast off for a case where the response already carries one, so the same message is not shown twice. `ExportAction` does exactly this: it persists the notification and flashes the toast on the response that is already going back.

## Every method on `Notification`

`PandaPanel\Notifications\Notification` is final, and every setter returns `self`.

| Method | Signature | Default |
| --- | --- | --- |
| `make` | `static make(?string $name = null): self` | a UUID when no name is given |
| `title` | `title(string $title): self` | `Str::headline($name)` |
| `body` | `body(string $body): self` | `null` |
| `icon` | `icon(string $icon): self` | the colour's own icon |
| `color` | `color(NotificationColor $color): self` | `NotificationColor::Info` |
| `success` | `success(): self` | — |
| `info` | `info(): self` | — |
| `warning` | `warning(): self` | — |
| `danger` | `danger(): self` | — |
| `actions` | `actions(array $actions): self` — `array<array-key, NotificationAction>` | `[]` |
| `persistent` | `persistent(bool $persistent = true): self` | `false` |
| `broadcast` | `broadcast(bool $broadcast = true): self` | `true` |
| `getName` | `getName(): string` | — |
| `isPersistent` | `isPersistent(): bool` | — |
| `isBroadcast` | `isBroadcast(): bool` | — |
| `send` | `send(Authenticatable $user): self` | — |
| `toArray` | `toArray(): array<string, mixed>` | — |

```php
use Illuminate\Support\Str;
use PandaPanel\Notifications\Enums\NotificationColor;
use PandaPanel\Notifications\Notification;

// The name is an identifier, not copy. Leaving it out gives you a UUID,
// which is fine for a notification nothing else refers to.
$notification = Notification::make();

// The title falls back to the headline of the name, so a well-named
// notification often needs no title at all.
Notification::make('export-ready')->toArray()['title'];   // 'Export Ready'

// The four shorthands are the same call.
Notification::make('a')->color(NotificationColor::Warning);
Notification::make('a')->warning();
```

`send()` is the only method that does anything. It persists first and broadcasts second, so a user who clicks a toast's action and lands on the notification centre finds the row already there.

## Colours

`PandaPanel\Notifications\Enums\NotificationColor` is a closed set of four. Each maps to a literal set of frontend classes, so an interpolated colour would compile to nothing.

| Case | Value | `icon()` | `toastType()` |
| --- | --- | --- | --- |
| `NotificationColor::Info` | `info` | `info` | `info` |
| `NotificationColor::Success` | `success` | `check` | `success` |
| `NotificationColor::Warning` | `warning` | `triangle-alert` | `warning` |
| `NotificationColor::Danger` | `danger` | `circle-alert` | `error` |

```php
use PandaPanel\Notifications\Enums\NotificationColor;

NotificationColor::Danger->icon();        // 'circle-alert'
NotificationColor::Danger->toastType();   // 'error'
```

`toastType()` exists because the toast channel is the same one flash messages use, and it speaks `success|info|warning|error` rather than the panel's colour names. The mapping happens on the server so the frontend never has to decide what a colour means.

## What actually crosses the wire

`toArray()` is the shape both channels carry — one shape rather than two, so a notification looks the same whether it arrived over a websocket or was read out of a table an hour later:

```php
[
    'name' => 'export-ready',
    'title' => 'Your export is ready',
    'body' => '1,204 records',
    'color' => 'success',
    'icon' => 'download',
    'actions' => [ /* each NotificationAction::toArray() */ ],
    'type' => 'success',        // the toast channel, resolved from the colour
    'persistent' => true,       // so the bell knows to refetch rather than guess
]
```

The broadcast adds `message` on top of that and restates `persistent` — `toArray()` already carries it — in `PanelNotificationSent::broadcastWith()`:

```php
[
    ...$payload,
    'message' => $payload['title'] ?? '',   // what the toast reads
    'persistent' => true,                   // whether the bell has a row to fetch
]
```

The toast reads `message`; the bell reads `title` and `body`. Both are sent rather than making either side guess.

## A toast with nothing behind it

For a message that has no reason to be a notification at all — no colour semantics to carry, no row to persist — dispatch the broadcast event directly:

```php
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::dispatch(
    $user,
    'Your export is ready.',
    'success',
    '/admin/exports/users.csv',   // optional: something to open
    'Download',                   // optional: the link's label
);
```

| Constructor argument | Type | Default |
| --- | --- | --- |
| `$user` | `Illuminate\Contracts\Auth\Authenticatable` | — |
| `$message` | `string` | — |
| `$type` | `'success'\|'info'\|'warning'\|'error'` | `'info'` |
| `$url` | `string\|null` | `null` |
| `$urlLabel` | `string\|null` | `null` |

This is the only way to put a **link on a broadcast toast** — a toast that rides a response can carry one too, by flashing it (see below). `PanelNotification::broadcastWith()` carries `url` and `urlLabel`, which is what the client turns into the toast's action button. See [Broadcasting](broadcasting.md) for the event itself.

## How it is rendered

`resources/js/panel/composables/usePanelBroadcasting.ts` is called once, in `PanelLayout.vue`, so one subscription covers every panel route:

```ts
import { usePanelBroadcasting } from '@/panel/composables/usePanelBroadcasting';

usePanelBroadcasting();
```

It listens for `.panel.notification` on the private channel the server sent, narrows the payload, and calls `vue-sonner`:

```ts
import { safeUrl } from '@/lib/utils';

toast[notification.type](notification.message, {
    action: safeUrl(notification.url) === null
        ? undefined
        : {
              label: notification.urlLabel ?? 'Open',
              onClick: () => { window.location.href = safeUrl(notification.url) as string; },
          },
});
```

A link rather than a navigation: the toast may arrive while the user is in the middle of something else, and moving them somewhere they did not ask to go would be worse than the file waiting.

The `<Toaster />` that renders them lives in the panel shells — `SidebarPanelLayout.vue`, `HeaderPanelLayout.vue` and `PanelAuthLayout.vue`. A page that renders its own layout instead of one of those gets no toasts.

## Gotchas

- **A toast shows the title, not the body.** `message` is `payload['title']`, so `->body()` is only ever seen in the bell. If the sentence matters, put it in the title.
- **A `Notification`'s actions never appear on the toast.** The client reads `url` and `urlLabel`, which `Notification::toArray()` does not produce — its `actions` array is for the notification centre. For a clickable toast, dispatch `PanelNotification` with a `$url`, or flash a toast with `url` and `urlLabel` (see [Flash toast bridge](flash-bridge.md)).
- **Nothing is delivered without a broadcaster.** The channel is only shared with the frontend when the panel has broadcasting on *and* `BroadcastSupport::isConfigured()` is true. Until then `send()` still dispatches the event and nothing arrives in a browser. See [Reverb and Echo setup](reverb-echo.md).
- **`icon()` takes a registry name, not a Lucide class.** A name that is not a key in `resources/js/panel/icons/registry.ts` renders no icon. Run `php artisan panel:icons` after introducing one.
- **`send()` needs a user, not a notifiable.** It takes `Authenticatable`. The database half is skipped for a model without a `notify()` method rather than throwing, so a toast still works on a user model that is not `Notifiable`.

## See also

- [Flash toast bridge](flash-bridge.md) — turning `redirect()->with('success', …)` into the same toast
- [Database notifications](database.md) — `->persistent()` and the `notifications` table
- [Notification actions](actions.md) — the buttons a stored notification carries
- [Notification center](notification-center.md) — the bell and its endpoints
- [Broadcasting](broadcasting.md) — the events, channels and payloads
- [Error notifications](../pages-navigation/error-notifications.md) — what a panel says when a request fails
- [Testing notifications](testing.md)
