# Reverb and Echo Setup

The panel knows how to push a notification and how to subscribe to one. It does not ship a broadcaster or an Echo client — those belong to the application, because an application may already have Pusher, Ably, or a Reverb server it runs itself. This page is the wiring: what has to exist on the server, what has to exist in the browser, and how to tell which half is missing.

## A minimal working example

Reverb, end to end, in a Laravel application that already has the panel installed:

```bash
php artisan install:broadcasting --reverb
npm install --save-dev @laravel/echo-vue pusher-js
php artisan reverb:start
```

Then configure Echo for the panel's client, in `resources/js/app.ts`, before the Inertia app is created:

```ts
import { configureEcho } from '@laravel/echo-vue';

configureEcho({ broadcaster: 'reverb' });
```

```bash
npm run dev
```

Open the panel and send yourself something from `php artisan tinker`:

```php
PandaPanel\Broadcasting\PanelNotification::dispatch(
    App\Models\User::first(),
    'Broadcasting works.',
    'success',
);
```

## What each piece is for

| Piece | Lives in | Needed because |
| --- | --- | --- |
| A broadcaster (Reverb, Pusher, Ably) | `config/broadcasting.php` | `BroadcastSupport::isConfigured()` refuses to send a channel without one |
| `routes/channels.php` | the application | a private channel is refused without an authorization callback |
| `@laravel/echo-vue` | `package.json` | the panel's composable imports `echo()` from it |
| `configureEcho()` | `resources/js/app.ts` | `echo()` throws until it has been called |
| `pusher-js` | `package.json` | `@laravel/echo-vue` imports it directly — it is the transport for the `reverb` and `pusher` broadcasters, and the bundle will not build without it |
| A queue worker | `php artisan queue:work` | `ShouldBroadcast` events are queued jobs |

## The server half

`php artisan install:broadcasting` is Laravel's own command. It creates `routes/channels.php`, registers it in `bootstrap/app.php`, publishes `config/broadcasting.php`, and offers to install Reverb and the Node dependencies.

What the panel needs from the result is narrow: a default connection whose driver reaches a browser.

```php
// config/broadcasting.php — the shipped fallback is 'null';
// `install:broadcasting --reverb` names reverb by writing BROADCAST_CONNECTION to .env
'default' => env('BROADCAST_CONNECTION', 'null'),
```

```php
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();   // false for null/empty default, an undefined
                                    // connection, or a 'null'/'log' driver
```

`.env` for Reverb, in the shape `install:broadcasting` writes it:

```bash
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=…
REVERB_APP_KEY=…
REVERB_APP_SECRET=…
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

The `VITE_` half is what `configureEcho()` reads. The server half is what `reverb:start` reads.

Then the authorization callback, which is the rule the whole thing rests on:

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(
    'App.Models.User.{id}',
    static fn ($user, int|string $id): bool => hash_equals((string) $user->getAuthIdentifier(), (string) $id),
);
```

That name is not the panel's invention — it is Laravel's default, and `PanelNotification::channelFor()` builds it so that a notification broadcast by anything else in the application arrives on the same channel. See [Channel authorization](channel-authorization.md).

## The browser half

The panel imports `echo()` from `@laravel/echo-vue`:

```ts
import { echo } from '@laravel/echo-vue';

client.private(channel).listen('.panel.notification', (payload) => { /* … */ });
```

`@laravel/echo-vue@^2.4.0` is in the package's own `package.json` dependencies, so `php artisan panel:install` already lists it among the npm packages an application needs. `pusher-js` is an optional peer dependency of Echo and is what the `reverb` and `pusher` broadcasters use for transport, so it has to be installed too.

`configureEcho()` must run before any panel page mounts. Put it at the top of `resources/js/app.ts`:

```ts
import { configureEcho } from '@laravel/echo-vue';
import { createInertiaApp } from '@inertiajs/vue3';

configureEcho({ broadcaster: 'reverb' });

createInertiaApp({ /* … */ });
```

With `broadcaster: 'reverb'` the rest of the options default to the `VITE_REVERB_*` variables, which is why the snippet is one line. Pass them explicitly when your setup differs:

```ts
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

Pusher instead of Reverb is the same call with a different broadcaster:

```ts
configureEcho({ broadcaster: 'pusher' });   // reads VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER
```

## The `window.Echo` trap

`install:broadcasting` writes `resources/js/echo.js`, which does this:

```js
window.Echo = new Echo({ broadcaster: 'reverb', /* … */ });
```

The panel does not read `window.Echo`. `echo()` from `@laravel/echo-vue` resolves a module-level client that only `configureEcho()` creates, and it throws when that call never happened — regardless of what is on `window`. An application can keep both, but `configureEcho()` is the one the panel needs.

## Telling which half is missing

The panel is deliberately quiet about all of this, and each failure has one symptom:

| Symptom | Cause |
| --- | --- |
| `broadcasting.enabled` is `false` in the Inertia props | no broadcaster configured, or the panel called `->broadcasting(false)` |
| `broadcasting.enabled` is `true`, console warns "[panel] Realtime notifications are off…" in dev | `configureEcho()` was never called |
| A 403 from `/broadcasting/auth` in the network tab | `routes/channels.php` is missing the callback, or refuses this user |
| Nothing at all, and no errors | no queue worker: the `BroadcastEvent` job is sitting in the queue |

The dev-only warning is worth quoting, because it names the fix:

```text
[panel] Realtime notifications are off: this panel has broadcasting enabled and a
broadcaster configured, but Echo was never set up in the browser. Call
configureEcho({ broadcaster: … }) in resources/js/app.ts, or turn it off with
->broadcasting(false) on the panel.
```

It is printed once, in development only, and then the panel carries on without realtime notifications. The feature dies; the screen does not.

Check the server's answer from a test or tinker:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\BroadcastSupport;

BroadcastSupport::isConfigured();                                  // has this app a broadcaster
app(PanelManager::class)->get('admin')->hasBroadcasting();         // does this panel want one
app(PanelManager::class)->get('admin')->getBroadcastChannel($user); // what it will subscribe to
```

## Running without a broadcaster

Entirely supported, and it is the default state of a fresh install. `SharePanelData` sends `channel: null`, `usePanelBroadcasting` returns before touching Echo, and no connection is attempted. Notifications still work; they are not realtime:

- `->persistent()` notifications still land in the bell, whose count arrives with every panel request.
- Flash toasts still work, because they ride the response rather than a socket.

Turn the intent off explicitly on a panel that will never have one, so the shared prop says so rather than depending on config:

```php
$panel->broadcasting(false);
```

## Production notes

- Run Reverb under a process supervisor, and a queue worker alongside it. A broadcast is a queued job; without a worker the socket is connected and silent.
- `REVERB_HOST`/`VITE_REVERB_HOST` are different addresses in most deployments: the first is where PHP reaches the server, the second is what the browser dials.
- The `VITE_` variables are compiled into the bundle. Changing one means rebuilding, not restarting.
- Reverb over TLS behind a proxy needs `VITE_REVERB_SCHEME=https` and the proxy configured to upgrade websockets.

## See also

- [Broadcasting](broadcasting.md) — the events and the channel
- [Channel authorization](channel-authorization.md) — `routes/channels.php` in detail
- [Queued notifications](queues.md) — why a worker is part of the setup
- [Toast notifications](toast.md)
- [Frontend requirements](../getting-started/frontend-requirements.md) — the npm packages the panel expects
- [Broadcast failures](../troubleshooting/broadcasting.md)
- [Broadcasting server](../deployment/broadcasting.md)
