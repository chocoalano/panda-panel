# Environment Variables

The package defines no environment variables of its own. Every value in `config/panda-panel.php`
is a literal — there is not one `env()` call in the file, or anywhere in `src/`. What a panel does
depend on is a handful of ordinary Laravel configuration values, most of which a default skeleton
binds to environment variables. This page is the list, and what each one changes about a panel.

## A minimal working example

A panel needs nothing beyond a working Laravel application:

```bash
APP_NAME="Acme"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:…
DB_CONNECTION=…
```

```bash
php artisan migrate
php artisan panel:cache
```

Everything below is about a specific panel *feature* — realtime notifications, queued exports,
emailed two-factor codes — and each is optional.

## Nothing here is env-bound

```php
// config/panda-panel.php — the whole file's shape

'panels' => [],
'register_routes' => true,
'register_web_middleware' => true,
'register_guest_redirect' => true,
'home_redirect' => ['enabled' => true, 'paths' => ['dashboard']],
'load_migrations' => true,
'integrations' => [...],
'frontend' => ['panel_path' => 'js/panel', 'pages_path' => 'js/pages/Panels'],
```

Deliberately so. Five of these are read once during boot to decide whether the framework registers
routes, middleware, a redirect and migrations, and a registration switch that could differ between
a web request and a queue worker on the same deploy would be a source of bugs nobody can
reproduce. If you want one of them to vary by environment, bind it yourself in the config file —
that is the one place `env()` is read before `config:cache` freezes it.

```php
// config/panda-panel.php

'register_guest_redirect' => env('PANEL_GUEST_REDIRECT', true),
```

Cast it. `env()` returns strings, and every one of these keys is compared with `=== true`, so
`PANEL_GUEST_REDIRECT=true` in `.env` would *disable* the feature rather than enable it. Laravel's
`env()` already converts the literals `true` and `false`, but a value that arrives from a shell or
a container orchestrator may not go through it.

## What a panel reads from Laravel's own config

| Feature | Config key | Env var in a default skeleton | Unset or wrong |
| --- | --- | --- | --- |
| Panel brand name | `app.name` | `APP_NAME` | The brand falls back to whatever `config('app.name')` is. |
| Stale-manifest warning | `app.debug`, `app.env` | `APP_DEBUG`, `APP_ENV` | Only logged when debug is on or the environment is `local`/`testing`. Silent in production by design. |
| Realtime toasts and bell | `broadcasting.default`, `broadcasting.connections.*.driver` | `BROADCAST_CONNECTION` | The `broadcasting` prop is `{enabled: false, channel: null}` and nothing subscribes. |
| Queued exports, imports, integration deliveries, two-factor mail | `queue.default` | `QUEUE_CONNECTION` | With `sync` everything runs inline. With a real connection and no worker, nothing arrives and nothing errors. |
| Emailed two-factor codes, send and guess limits | `cache.default` | `CACHE_STORE` | A code lives in the cache for ten minutes; a cache flush invalidates outstanding codes and resets rate limits. |
| The two-factor code mail | `mail.default` | `MAIL_MAILER` | The code is issued and cached, and the mail never arrives. |
| Export and import files | `filesystems.disks.local` | — | `Exporter::disk()` and `Importer::disk()` return `'local'`, a disk *name*, not `FILESYSTEM_DISK`. Override the method to use another. |
| Panel sessions | `session.*` | `SESSION_DRIVER`, `SESSION_DOMAIN` | A panel is behind `web`; without a session there is no login and every form is a 419. |

### Broadcasting

`PandaPanel\Support\BroadcastSupport::isConfigured()` decides whether the `broadcasting` prop says
`enabled` — the prop itself is shared on every request either way — and it asks three questions:

```php
BroadcastSupport::isConfigured();   // bool
```

1. `broadcasting.default` names a connection.
2. That connection exists and has a driver.
3. The driver is not `null` or `log` — both are real drivers, and neither is something a browser
   can subscribe to.

A panel that wants realtime (`broadcasting()` is on by default) but whose application has no
broadcaster gets `enabled: false` rather than a channel name. That check exists because the
failure it replaces was ugly and far from its cause: the server sent a channel, the client called
`echo()`, `@laravel/echo-vue` threw "Echo has not been configured" from inside `onMounted`, and
the aborted mount took the layout down with it.

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

Credentials are deliberately not validated. Whether a key is correct is a question only the
broadcaster can answer, and a panel that refused to connect because it disliked the look of a key
would be a worse failure than the one this check replaces. See
[Reverb and Echo](../notifications/reverb-echo.md).

### Queues

`PandaPanel\Jobs\RunPanelExport`, `RunPanelImport` and `SendPanelIntegration` implement
`ShouldQueue`, as does the `TwoFactorCode` notification. Broadcast events implement
`ShouldBroadcast` rather than `ShouldBroadcastNow`, so they are queued too.

With `QUEUE_CONNECTION=sync` all of it runs inline, which is why realtime notifications appear to
work locally without a worker and stop the moment the queue is real. See
[Queued Notifications](../notifications/queues.md) and
[Queued Exports](../import-export/queued-exports.md).

Panel URLs built inside those jobs are relative (`route(..., absolute: false)`), so a worker whose
`APP_URL` is wrong does not produce broken panel links.

## The frontend's environment

The published components read exactly two things from Vite's environment:

| Read | Where | Purpose |
| --- | --- | --- |
| `import.meta.env.DEV` | the icon, form and widget registries | development-only console warnings when a component name is not in the build-time allowlist |
| `import.meta.env.VITE_APP_NAME` | declared in `resources/js/types/global.d.ts` | the application's own name, as a starter kit uses it |

Everything a panel screen renders arrives as Inertia props from the server, so there is no
`VITE_PANEL_*` anything. Echo's configuration lives in the application's own `resources/js/app.ts`,
which the package does not publish:

```ts
import { configureEcho } from '@laravel/echo-vue';

configureEcho({ broadcaster: 'reverb' });
```

`configureEcho()` only configures. Nothing connects until a component subscribes, which is what
makes `$panel->broadcasting(false)` cost no connection rather than opening one and ignoring it.

## Reading environment values in a panel

Read `config()`, not `env()`:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->path((string) config('panels.admin_path', 'admin'))
        ->strictAuthorization(app()->environment('local', 'testing'));
}
```

Once `php artisan config:cache` has run, `env()` outside a config file returns `null`. A panel
whose path came from `env()` would mount at an empty path in production, with no error to say so.
The same rule applies to a resource, a page, a widget, and anything else that runs after boot.

Config files are the exception, and `config/panda-panel.php` is a config file:

```php
// config/panda-panel.php

'panels' => array_values(array_filter([
    App\Panels\Admin\AdminPanelProvider::class,
    env('APP_ENV') === 'local' ? App\Panels\Debug\DebugPanelProvider::class : null,
])),
```

## Environment-sensitive behaviour

Three things in the package behave differently depending on the environment, and none of them is
configurable:

| Behaviour | Rule |
| --- | --- |
| Stale panel-manifest warning | Logged only when `app()->hasDebugModeEnabled()` or the environment is `local` or `testing`. In production the manifest is the authority and nothing touches the filesystem. |
| Registry warnings in the browser console | `import.meta.env.DEV` only — a production build says nothing. |
| Missing-policy notice | `Log::debug()` under the same rule, once per model per process. A resource deliberately hidden from every user is legitimate, and a log line per deploy about it is noise where noise is expensive. See [Authorization](../concepts/authorization.md). |

## Gotchas

- **`env()` in `config/panda-panel.php` needs a cast.** Every switch is compared with `=== true`,
  and a string is not `true`.
- **`config:cache` captures the package defaults.** The command boots a fresh application before
  serializing, so `mergeConfigFrom()` has already run and an unpublished config file is cached
  with its defaults intact.
- **A queue worker is a separate process with a separate environment.** An export that works in
  the request and fails in the job is usually a worker booted before an `.env` change, or one
  whose `local` disk resolves to a different root than the web process's.
- **`CACHE_STORE=array` in a web process means emailed codes never verify**, because the store is
  rebuilt per request. It is fine in tests, where the whole exchange happens in one process.
- **`SESSION_DOMAIN` is a decision, not a default, for a panel on a subdomain.** A panel served at
  `admin.example.test` with a session cookie scoped to `example.test` signs users out on the way
  in. See [Tenant URLs](../tenancy/urls.md).
- **There is no `PANEL_*` variable to look for.** If you are searching an application's `.env` for
  panel configuration, it is in `config/panda-panel.php` and in the panel providers.

## See also

- [config/panda-panel.php](panda-panel.md)
- [Panel Config](panel-config.md)
- [Service Provider Behavior](service-provider.md)
- [Config Cache](../deployment/config-cache.md)
- [Broadcasting Setup](../deployment/broadcasting.md)
- [Queues](../deployment/queues.md)
- [Reverb and Echo](../notifications/reverb-echo.md)
- [Queued Notifications](../notifications/queues.md)
- [Email Code Challenge](../authentication/email-code-challenge.md)
- [Production Checklist](../deployment/production-checklist.md)
