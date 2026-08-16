# Broadcasting

Broadcasting is how a notification reaches a browser that is already open. A job that finishes ten minutes after the request that started it has no response to ride back on, so the panel pushes the message over a websocket instead — the same toast a flash message produces, and the same bell a stored notification increments. This page covers the events, the channel, the toggles, and the client subscription. For getting a broadcaster running, see [Reverb and Echo setup](reverb-echo.md).

## A minimal working example

```php
<?php

use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::dispatch($user, 'Your export is ready.', 'success');
```

Every panel that user has open shows a green toast. Nothing was stored, and a user who was away sees nothing.

## The two events

Both live on the same channel and broadcast under the same name, because to the frontend they are the same thing arriving: a message to show and, when it was persisted, a bell to increment. Two names would mean two subscriptions and two chances for them to disagree.

| Event | Raised by | Carries |
| --- | --- | --- |
| `PandaPanel\Notifications\PanelNotificationSent` | `Notification::send()` | the whole notification payload |
| `PandaPanel\Broadcasting\PanelNotification` | you, directly | a message, a type, and an optional link |

### `PanelNotificationSent`

```php
namespace PandaPanel\Notifications;

final class PanelNotificationSent implements ShouldBroadcast
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array;   // [new PrivateChannel(PanelNotification::channelFor($user))]
    public function broadcastAs(): string;  // 'panel.notification'
    public function broadcastWith(): array;
}
```

You rarely construct it. `Notification::send()` does:

```php
if ($this->broadcast) {
    event(new PanelNotificationSent($user, $this->toArray()));
}
```

`broadcastWith()` is the notification payload with `message` added and `persistent` restated as a strict boolean — `toArray()` already carries `persistent`:

```php
[
    ...$this->payload,
    'message' => $this->payload['title'] ?? '',   // the toast reads this
    'persistent' => true,                          // the bell reads this
]
```

`message` is sent alongside `title` rather than making either side guess, and `persistent` is present so the frontend knows whether the bell has a new row to fetch without asking.

### `PanelNotification`

```php
namespace PandaPanel\Broadcasting;

final class PanelNotification implements ShouldBroadcast
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly string $message,
        public readonly string $type = 'info',      // 'success'|'info'|'warning'|'error'
        public readonly ?string $url = null,
        public readonly ?string $urlLabel = null,
    ) {}

    public function broadcastOn(): array;
    public static function channelFor(Authenticatable $user): string;
    public function broadcastAs(): string;   // 'panel.notification'
    public function broadcastWith(): array;  // {type, message, url, urlLabel}
}
```

```php
use PandaPanel\Broadcasting\PanelNotification;

// Dispatchable, so all four of these work.
PanelNotification::dispatch($user, 'Heads up');
event(new PanelNotification($user, 'Heads up'));
PanelNotification::dispatch($user, 'Import finished', 'warning');
PanelNotification::dispatch($user, 'Your export is ready.', 'success', $url, 'Download');
```

This is the only event that carries `url` and `urlLabel`, which is what the client turns into a button on the toast. A finished export is a file, and a toast that only says so is a toast that makes somebody go looking for it.

## The channel

```php
public static function channelFor(Authenticatable $user): string
{
    return 'App.Models.User.'.$user->getAuthIdentifier();
}
```

Laravel's default channel name, unchanged, so a notification broadcast by anything else in the application arrives on the same one. It is built here rather than written out in Vue so the two cannot drift.

Both events wrap it in a `PrivateChannel`, so the name on the wire is `private-App.Models.User.7`. Subscribing to it requires the channel authorization callback in `routes/channels.php` — see [Channel authorization](channel-authorization.md).

## Turning it on and off

```php
use PandaPanel\Core\Panel;

$panel->broadcasting(false);
```

| Method | Signature | Default |
| --- | --- | --- |
| `broadcasting` | `broadcasting(bool $broadcasting = true): self` | `true` |
| `hasBroadcasting` | `hasBroadcasting(): bool` | `true` |
| `getBroadcastChannel` | `getBroadcastChannel(?Authenticatable $user): ?string` | `null` when off or no user |

```php
use PandaPanel\Core\Panel;

$panel = Panel::make('quiet')->broadcasting(false);

$panel->hasBroadcasting();                  // false
$panel->getBroadcastChannel($user);         // null
```

Nothing connects until a page actually subscribes, and a page only subscribes when the server sent a channel — so a panel that turns this off costs no connection at all, rather than hiding one that was opened anyway.

## Two questions, both of which must answer yes

A panel saying it *wants* realtime notifications is not the same as the application *having* something to deliver them with. `SharePanelData` asks both:

```php
if ($panel === null || ! $panel->hasBroadcasting() || ! BroadcastSupport::isConfigured()) {
    return ['enabled' => false, 'channel' => null];
}
```

`PandaPanel\Support\BroadcastSupport::isConfigured(): bool` answers the second. Three things have to hold:

| Check | Fails when |
| --- | --- |
| A default connection is named | `broadcasting.default` is missing or empty — a fresh Laravel with no `config/broadcasting.php` |
| That connection exists and has a driver | `broadcasting.connections.{default}.driver` is missing — a typo, not a broadcaster |
| The driver reaches a browser | the driver is `null` or `log` — real drivers, and neither is something Echo can subscribe to |

```php
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();   // bool
```

Credentials are deliberately not checked: only the broadcaster can answer that, and a panel that refused to connect because it disliked the look of a key would be a worse failure than the one this replaces.

That failure is worth naming, because it is what this prevents: the server sent a channel, the client called `echo()` on it, `@laravel/echo-vue` threw "Echo has not been configured" from inside `onMounted`, and the aborted mount produced a dozen `Slot "default" invoked outside of the render function` warnings as Inertia swapped a layout that had never finished mounting. Nothing in that sequence names a broadcaster.

## The shared prop

```php
'broadcasting' => ['enabled' => true, 'channel' => 'App.Models.User.7'],
```

| Field | Type | Value when off |
| --- | --- | --- |
| `enabled` | `bool` | `false` |
| `channel` | `string\|null` | `null` |

Null rather than an empty string, so the frontend has nothing to subscribe to rather than a channel it would be refused.

It is on the shared props, not on `Panel::toSharedArray()`. The answer depends on who is asking, so it belongs beside the request rather than in the panel definition the client caches.

```ts
import { usePanel } from '@/panel/composables/usePanel';

const { broadcasting } = usePanel();

broadcasting.value.enabled;   // boolean
broadcasting.value.channel;   // string | null
```

## The client subscription

`resources/js/panel/composables/usePanelBroadcasting.ts`, called once in `PanelLayout.vue`:

```ts
import { usePanelBroadcasting } from '@/panel/composables/usePanelBroadcasting';

usePanelBroadcasting();
```

```ts
const EVENT = '.panel.notification';   // the dot marks a custom broadcastAs name

client.private(channel).listen(EVENT, (payload: unknown) => { /* … */ });
```

What it does, in order:

1. Returns immediately when `broadcasting.channel` is `null`. That is what makes a disabled panel cost nothing.
2. Narrows the payload. A payload arriving over a websocket has crossed the same boundary an HTTP response does, and is validated the same way rather than trusted: `message` and `type` must be strings, and `type` must be one of `success`, `info`, `warning`, `error`. Anything else is ignored.
3. Raises `window` event `panel:notification` when `payload.persistent === true` — before the toast, so the bell's count is right even if the toast is dismissed instantly.
4. Shows the toast through `vue-sonner`, with an action button when the payload carried a `url`.
5. Leaves the channel on unmount, but only if the subscription actually happened.

If `configureEcho()` was never called in the browser, `echo()` throws. The composable catches it, warns once in development, and the panel carries on without realtime notifications. The feature dies; the screen does not.

## Queueing

Both events implement `ShouldBroadcast`, not `ShouldBroadcastNow`. Laravel pushes a `BroadcastEvent` job onto the default queue connection, so **a worker has to be running** for a toast to arrive. With `QUEUE_CONNECTION=sync` it happens inline, which is why it appears to work in local development without one. See [Queued notifications](queues.md).

## Gotchas

- **`dispatch()` on a `ShouldBroadcast` event is not delivery.** It is a queued job. A missing worker looks exactly like a missing broadcaster from the browser.
- **The event name needs its leading dot.** `broadcastAs()` returns `panel.notification`; Echo requires `.panel.notification` to skip its namespace prefixing. The composable already does this — it matters only if you subscribe yourself.
- **A guest gets no channel.** `getBroadcastChannel(null)` is `null`, and `SharePanelData` never reaches it anyway.
- **The channel is the user's, not the panel's.** Two panels open in two tabs subscribe to the same channel and both show the toast. That is intended: the notification is for the person, not the screen.
- **`channelFor()` uses `getAuthIdentifier()`.** A custom user model with a non-integer key still works, provided the callback in `routes/channels.php` compares the same way.

## See also

- [Reverb and Echo setup](reverb-echo.md) — making a broadcaster exist
- [Channel authorization](channel-authorization.md) — the rule that makes a private channel safe
- [Toast notifications](toast.md) — what a broadcast turns into
- [Notification center](notification-center.md) — the bell that `panel:notification` refreshes
- [Queued notifications](queues.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Testing notifications](testing.md)
