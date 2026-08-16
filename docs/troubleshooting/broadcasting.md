# Broadcast failures

Realtime panel notifications need four things to line up: a broadcaster in PHP, a channel
authorization callback, Echo in the browser, and a queue worker. Each one fails differently and
none of them fails loudly. This page is how to tell which half is missing.

## Start here

Ask the server what it thinks, from tinker:

```php
use App\Models\User;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();                                   // has this app a broadcaster
app(PanelManager::class)->get('admin')->hasBroadcasting();          // does this panel want one
app(PanelManager::class)->get('admin')->getBroadcastChannel($user); // what it will subscribe to
```

Then send yourself one and watch:

```php
use App\Models\User;
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::dispatch(User::query()->first(), 'Broadcasting works.', 'success');
```

```bash
php artisan queue:work      # a ShouldBroadcast event is a queued job
php artisan reverb:start    # development needs the websocket server running
```

## The symptom table

| Symptom | Cause |
| --- | --- |
| `broadcasting.enabled` is `false` in the Inertia props | no usable broadcaster, or the panel called `->broadcasting(false)` |
| `broadcasting.enabled` is `true`, dev console warns `[panel] Realtime notifications are off…` | `configureEcho()` was never called in the browser |
| `Echo has not been configured`, thrown from `onMounted`, then a cascade of `Slot "default" invoked outside of the render function` | an older version's failure mode; upgrade, or see [below](#the-mount-cascade) |
| 403 from `/broadcasting/auth` in the network tab | `routes/channels.php` has no callback for `App.Models.User.{id}`, or it refuses this user |
| Nothing happens at all, no errors | no queue worker: the broadcast job is sitting in the queue |
| The toast arrives twice | the response already carried one — use `->broadcast(false)` on the notification |

## 1. Does the application have a broadcaster

`PandaPanel\Support\BroadcastSupport` answers the question the panel was never asking:
`Panel::broadcasting()` says whether a panel *wants* realtime notifications; this says whether the
application has anything to deliver them with.

```php
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();   // bool
```

| Method | Signature | Returns |
| --- | --- | --- |
| `isConfigured` | `static isConfigured(): bool` | whether a browser could attach to this application's broadcaster |

Three things must hold, and it answers `false` unless all three do:

1. **`broadcasting.default` names a connection.** A fresh Laravel with no `config/broadcasting.php`
   has none.
2. **That connection exists and has a driver.** A named connection that was never defined is a typo,
   not a broadcaster.
3. **The driver reaches a browser.** `null` and `log` are excluded: both are real drivers, and
   neither is something Echo can subscribe to.

```php
use Illuminate\Support\Facades\Config;

Config::set('broadcasting.default', 'reverb');
Config::set('broadcasting.connections.reverb.driver', 'reverb');

BroadcastSupport::isConfigured();   // true
```

What it deliberately does **not** check is whether the credentials are correct. Only the broadcaster
can answer that, and a panel that refused to connect because it disliked the look of a key would be
a worse failure than the one this replaces.

```dotenv
BROADCAST_CONNECTION=reverb
```

**Behaviour to be aware of:** an application that broadcasts from PHP but has
`BROADCAST_CONNECTION=null` or `log` gets no panel channel. That was already a connection no browser
could subscribe to; what changes is that the panel says so instead of failing at mount.

## 2. Does the panel want one

```php
$panel->broadcasting(false);
```

| Member | Signature | Default |
| --- | --- | --- |
| `broadcasting` | `Panel::broadcasting(bool $broadcasting = true): self` | on |
| `hasBroadcasting` | `Panel::hasBroadcasting(): bool` | `true` |
| `getBroadcastChannel` | `Panel::getBroadcastChannel(?Authenticatable $user): ?string` | the private channel name, or `null` |

`getBroadcastChannel()` answers `null` when broadcasting is off **or** nobody is signed in, so the
frontend has nothing to subscribe to rather than a channel it would be refused.

Nothing connects until a component subscribes, which is what makes `broadcasting(false)` cost no
connection rather than opening one and ignoring it.

## 3. What the frontend is told

`SharePanelData` shares one prop, computed per request:

```php
'broadcasting' => ['enabled' => bool, 'channel' => string|null]
```

Both questions have to answer yes — the panel wants it, and `BroadcastSupport::isConfigured()` says
the application can deliver it. Assert it in a test:

```php
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;

it('tells the frontend which channel to listen on', function (): void {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.driver', 'reverb');

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', true)
            ->where('broadcasting.channel', 'App.Models.User.'.$this->admin->getKey()));
});
```

The channel is shipped per request rather than in the panel definition, because it depends on who is
asking — `Panel::toSharedArray()` has no `broadcasting` key at all, and a test asserts that.

## 4. The event

```php
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::dispatch($user, 'Export finished', 'success');

PanelNotification::dispatch(
    $user,
    'Your export is ready',
    'success',
    url: route('panel.admin.export-file', ['file' => $file, 'exporter' => $exporter]),
    urlLabel: 'Download',
);
```

```php
public function __construct(
    public readonly Authenticatable $user,
    public readonly string $message,
    public readonly string $type = 'info',
    public readonly ?string $url = null,
    public readonly ?string $urlLabel = null,
) {}
```

| Member | Signature | Value |
| --- | --- | --- |
| `channelFor` | `static channelFor(Authenticatable $user): string` | `'App.Models.User.'.$user->getAuthIdentifier()` |
| `broadcastOn` | `broadcastOn(): array` | one `PrivateChannel` for that name |
| `broadcastAs` | `broadcastAs(): string` | `'panel.notification'` |
| `broadcastWith` | `broadcastWith(): array` | `['type' => …, 'message' => …, 'url' => …, 'urlLabel' => …]` |

`$type` is one of `success`, `info`, `warning`, `error`. Anything else is dropped by the client
guard rather than rendered.

`channelFor()` builds the name on the server so the two sides cannot drift. It is Laravel's own
default channel name, which is what makes a notification broadcast by anything else in the
application arrive on the same channel.

## 5. Channel authorization

A private channel is refused without a callback. This one is not the panel's invention:

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => (int) $user->id === (int) $id,
);
```

Without it the browser gets a 403 from `/broadcasting/auth`, the socket connects, and nothing ever
arrives. `php artisan install:broadcasting` writes the file and registers it in `bootstrap/app.php`.

## 6. Echo in the browser

The panel imports `echo()` from `@laravel/echo-vue`, which throws until `configureEcho()` has been
called:

```ts
// resources/js/app.ts
import { configureEcho } from '@laravel/echo-vue';

configureEcho({ broadcaster: 'reverb' });
```

The panel does **not** read `window.Echo`. `install:broadcasting` writes `resources/js/echo.js`,
which sets `window.Echo`; that is a different client, and `echo()` resolves a module-level one that
only `configureEcho()` creates.

### The mount cascade

`usePanelBroadcasting()` wraps every call to `echo()`. When Echo was never configured it warns once,
in development only, and the panel carries on:

```text
[panel] Realtime notifications are off: this panel has broadcasting enabled and a broadcaster
configured, but Echo was never set up in the browser. Call configureEcho({ broadcaster: … })
in resources/js/app.ts, or turn it off with ->broadcasting(false) on the panel.
```

That wrapper exists because the unguarded call was ugly and far from its cause: `echo()` threw from
inside `onMounted` on the panel shell, the aborted mount produced a dozen
`Slot "default" invoked outside of the render function` warnings as Inertia swapped a layout that
had never finished mounting, and nothing in that sequence named a broadcaster.

The composable subscribes to `.panel.notification` — the leading dot marks a custom event name —
and validates the payload before showing anything, because a payload arriving over a websocket has
crossed the same boundary an HTTP response does. A `type` outside the four is dropped; a missing
`message` is dropped.

## 7. The queue

`PanelNotification` implements `ShouldBroadcast`, so dispatching it queues a job. With no worker the
socket is connected and silent:

```bash
php artisan queue:work
```

This is the failure with no symptom at all — no console message, no network request, nothing in the
log — so check it before anything else when the wiring looks right.

## Sending through the notification API instead

`PandaPanel\Notifications\Notification` composes the toast with the database bell:

```php
use PandaPanel\Notifications\Notification;
use PandaPanel\Notifications\NotificationAction;

Notification::make('export-ready')
    ->title('Your export is ready')
    ->body('1,204 records')
    ->success()
    ->persistent()             // also write a row, so it can be read later
    ->broadcast(false)         // the response already carries a toast
    ->actions([
        NotificationAction::make('download')->label('Download')->url($url),
    ])
    ->send($user);
```

`->broadcast(false)` is the fix for a message that appears twice: the export action already flashes
a toast on the response it returns, so pushing the same text over a websocket would show it twice.

## Running without a broadcaster

Entirely supported, and the default state of a fresh install. `SharePanelData` sends
`channel: null`, `usePanelBroadcasting` returns before touching Echo, and no connection is
attempted. Notifications still work; they are not realtime:

- `->persistent()` notifications land in the bell, whose unread count arrives with every panel
  request.
- Flash toasts still work, because they ride the response rather than a socket.

Say so explicitly on a panel that will never have one, so the shared prop reflects intent rather
than config:

```php
$panel->broadcasting(false);
```

## Notes

- **Development needs `php artisan reverb:start`.** Without it the browser retries in the background
  and the panel works exactly as before, minus the live notifications.
- **`VITE_*` variables are compiled into the bundle.** Changing one means rebuilding, not
  restarting.
- **`REVERB_HOST` and `VITE_REVERB_HOST` are usually different addresses**: the first is where PHP
  reaches the server, the second is what the browser dials.
- **`pusher-js` is a separate install.** `@laravel/echo-vue` imports it as the transport for the
  `reverb` and `pusher` broadcasters, and the bundle will not build without it.
- **A guest gets no channel.** `getBroadcastChannel(null)` is `null`, so a login screen opens no
  socket.
- **Outside a panel there is no broadcasting prop worth reading**: `enabled` is `false` and
  `channel` is `null` on every non-panel request, including the starter kit's own pages.
- **A persistent notification raises `panel:notification` on `window`** before the toast, so the
  bell's count is right even if the toast is dismissed instantly.

## See also

- [Reverb and Echo setup](../notifications/reverb-echo.md)
- [Broadcasting](../notifications/broadcasting.md), [channel authorization](../notifications/channel-authorization.md)
- [Toast notifications](../notifications/toast.md), [database notifications](../notifications/database.md)
- [Queued notifications](../notifications/queues.md), [testing notifications](../notifications/testing.md)
- [Broadcasting in production](../deployment/broadcasting.md), [queues](../deployment/queues.md)
- [Common install problems](../getting-started/common-install-problems.md)
- [Host modules](host-modules.md) — the npm packages the panel expects
