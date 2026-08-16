# Broadcasting Server

Realtime panel notifications need three separate things running: a broadcaster
the browser can connect to, a queue worker to hand it the event, and an Echo
client configured in the bundle. This page is the operations half — what to run,
what to configure, and how to tell which of the three is missing. Reach for it
when putting Reverb behind a proxy, or when a toast works locally and never
arrives in production.

## A minimal working example

```bash
# .env, on the server
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis

REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=panel.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Three processes, all under a supervisor:

```bash
php artisan reverb:start --host=127.0.0.1 --port=8080
php artisan queue:work
php-fpm
```

Send yourself something to prove the chain:

```php
use App\Models\User;
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::dispatch(User::first(), 'Broadcasting works.', 'success');
```

## The chain, link by link

| Link | Runs where | Missing it looks like |
| --- | --- | --- |
| A broadcaster with a driver a browser can reach | `config/broadcasting.php` | `broadcasting.enabled` is `false` in the Inertia props; nothing connects |
| `routes/channels.php` with the user callback | the application | a 403 from `/broadcasting/auth` |
| A queue worker | a supervisor | socket connected, nothing ever arrives |
| `configureEcho()` in the bundle | `resources/js/app.ts`, compiled | a development-only console warning; production is silent |

Every one of them fails quietly on its own. That is deliberate — a panel whose
websocket is down still renders, still persists notifications, and still shows
flash toasts — but it means diagnosis is a checklist rather than a stack trace.

## The server-side gate

The panel refuses to hand the frontend a channel unless the application can
actually deliver on it:

```php
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();   // bool
```

| Method | Signature | Returns `false` when |
| --- | --- | --- |
| `isConfigured` | `static isConfigured(): bool` | `broadcasting.default` is absent or empty; the named connection has no `driver`; the driver is `null` or `log` |

`null` is off by definition. `log` writes to the application's log — a real
driver, but not something Echo can subscribe to. Credentials are deliberately
**not** checked: only the broadcaster can answer whether a key is right, and a
panel that refused to connect because it disliked the look of a key would be a
worse failure than the one this replaces.

That failure is worth naming, because it is why this gate exists. The server sent
a channel name, the client called `echo()` on it, `@laravel/echo-vue` threw "Echo
has not been configured" from inside `onMounted`, and the aborted mount produced
a dozen `Slot "default" invoked outside of the render function` warnings as
Inertia swapped a layout that had never finished mounting. Nothing in that
sequence names a broadcaster.

The two questions are combined in `SharePanelData`:

```php
if ($panel === null || ! $panel->hasBroadcasting() || ! BroadcastSupport::isConfigured()) {
    return ['enabled' => false, 'channel' => null];
}

return [
    'enabled' => true,
    'channel' => $panel->getBroadcastChannel($request->user()),
];
```

## Per-panel intent

| Method | Signature | Default |
| --- | --- | --- |
| `broadcasting` | `broadcasting(bool $broadcasting = true): self` | on |
| `hasBroadcasting` | `hasBroadcasting(): bool` | |
| `getBroadcastChannel` | `getBroadcastChannel(?Authenticatable $user): ?string` | `null` when broadcasting is off or nobody is signed in |

```php
use PandaPanel\Core\Panel;

Panel::make('reports')
    ->path('reports')
    ->broadcasting(false);      // this panel has nothing to receive
```

Turn it off explicitly on a panel served where no broadcaster is reachable. It
costs nothing to leave on — nothing connects until a page subscribes — but the
shared prop then says so from configuration rather than from an inference about
the environment.

## The channel

```php
use PandaPanel\Broadcasting\PanelNotification;

PanelNotification::channelFor($user);   // 'App.Models.User.7'
```

Built on the server so the two sides cannot drift, and it is Laravel's own
default name rather than the panel's invention — a notification broadcast by
anything else in the application arrives on the same channel.

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => hash_equals((string) $user->getAuthIdentifier(), (string) $id),
);
```

Without that callback every subscription is refused with a 403 from
`/broadcasting/auth`, and the panel shows nothing at all. It is a private
channel, so the refusal is the correct default — a missing `channels.php` is a
deployment mistake, not a security hole.

## The two events

Both are `ShouldBroadcast` and both broadcast as `panel.notification`, so the
frontend has one subscription and one listener.

```php
use PandaPanel\Broadcasting\PanelNotification;

new PanelNotification(
    user: $user,
    message: 'Import finished.',
    type: 'info',            // 'success'|'info'|'warning'|'error'
    url: '/admin/imports/…', // something to open, for a job whose result is a file
    urlLabel: 'Download',
);
```

| Class | `broadcastAs()` | Payload |
| --- | --- | --- |
| `PandaPanel\Broadcasting\PanelNotification` | `panel.notification` | `type`, `message`, `url`, `urlLabel` |
| `PandaPanel\Notifications\PanelNotificationSent` | `panel.notification` | the notification's payload, plus `message` and `persistent` |

Neither is `ShouldBroadcastNow`. Dispatching one pushes a queued job, which is
the single most important operational fact on this page: **no worker, no
realtime notification.**

## Running Reverb in production

```ini
[program:reverb]
command=php /var/www/current/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/current
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/reverb.log
```

Behind nginx, terminating TLS and upgrading the connection:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 60s;
}
```

Three things about the environment are worth stating separately, because each one
produces a different silent failure:

- **`REVERB_HOST` and `VITE_REVERB_HOST` are different addresses.** The first is
  where PHP reaches the server — `127.0.0.1` in the layout above. The second is
  what the browser dials, which is the public hostname.
- **`VITE_*` variables are compiled into the bundle.** Changing one means
  `npm run build`, not a restart. A deploy that changes `VITE_REVERB_HOST` and
  does not rebuild ships a bundle still dialling the old host.
- **TLS behind a proxy needs `VITE_REVERB_SCHEME=https` and `VITE_REVERB_PORT=443`.**
  The browser connects to the proxy, not to Reverb.

Pusher or Ably instead of Reverb removes the first process and changes nothing
else about the panel: the gate, the channel and the events are the same.

## What to restart on a deploy

| Process | Command |
| --- | --- |
| Queue workers | `php artisan queue:restart` |
| Reverb | restart under its supervisor |
| Octane | `php artisan octane:reload` |

Reverb holds no application code, but it does hold its configuration from
`.env` — so a change to `REVERB_*` is a restart, and a change to
`BROADCAST_CONNECTION` is a `config:cache` rebuild as well.

Restarting Reverb drops every open connection. Clients reconnect, and a
notification broadcast during the gap is delivered to nobody — it is a websocket,
not a queue. Anything a user must not miss should be `->persistent()`, which
writes a row the bell reads on the next navigation regardless.

## Diagnosing a silent chain

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();                                   // has this app a broadcaster
app(PanelManager::class)->get('admin')->hasBroadcasting();          // does this panel want one
app(PanelManager::class)->get('admin')->getBroadcastChannel($user); // what it will subscribe to
```

| Symptom | Cause |
| --- | --- |
| `broadcasting.enabled` is `false` in the props | no broadcaster configured, or the panel called `->broadcasting(false)` |
| `enabled` is `true`, dev console warns `[panel] Realtime notifications are off…` | `configureEcho()` was never called in `resources/js/app.ts` |
| 403 from `/broadcasting/auth` | `routes/channels.php` is missing the callback, or refuses this user |
| Nothing at all, no errors, bell count still correct on navigation | no queue worker — the `BroadcastEvent` job is sitting in the queue |

The last row is the common one in production, and the bell count is the tell: the
database half is not queued, so a persistent notification is stored even when the
broadcast never leaves.

## Running without a broadcaster

Entirely supported, and it is the default state of a fresh install. The panel
sends `channel: null`, the composable returns before touching Echo, and no
connection is attempted. Notifications still work; they are not realtime:

- `->persistent()` notifications land in the bell, whose count arrives with every
  panel request.
- Flash toasts still work, because they ride the response rather than a socket.

## Gotchas

- **`BROADCAST_CONNECTION=log` looks configured and is not.** It is one of two
  drivers `BroadcastSupport` treats as unreachable, and the panel will not send a
  channel for it.
- **A cached config freezes `BROADCAST_CONNECTION`.** Change it, then
  `php artisan config:cache`.
- **`VITE_*` changes need a rebuild.** They are inlined at build time.
- **A restarted Reverb loses in-flight notifications.** Persist anything that
  matters.
- **Scaling Reverb horizontally needs its own scaling configuration.** The panel
  makes no assumption about how many nodes there are; it only names a channel.
- **The channel is per user, not per panel.** A user with three panels open gets
  every notification in all three, which is intended — the bell is the account's,
  not the screen's.

## See also

- [Production checklist](production-checklist.md), [Queues](queues.md), [Monitoring](monitoring.md)
- [Reverb and Echo setup](../notifications/reverb-echo.md) — the client half, in detail
- [Broadcasting](../notifications/broadcasting.md), [Channel authorization](../notifications/channel-authorization.md)
- [Queued notifications](../notifications/queues.md), [Toast notifications](../notifications/toast.md)
- [Broadcast failures](../troubleshooting/broadcasting.md)
- [Frontend build](frontend-build.md) — why `VITE_*` is a build input
