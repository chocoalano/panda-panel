# Monitoring

What a running panel tells you, where it tells you, and what it deliberately
keeps quiet about. The package ships no metrics endpoint, no health route and no
audit log — it writes three log lines, dispatches two events, and keeps one
self-pruning history table. Reach for this page when wiring a panel into an
existing monitoring setup, or when working out why a failure produced no signal.

## A minimal working example

A health check that covers the two things a deploy can leave broken:

```php
use Illuminate\Support\Facades\Route;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\PanelManager;

Route::get('/health/panel', function () {
    return [
        'manifest' => app(PanelManifest::class)->exists(),
        'panels' => array_map(
            static fn ($panel) => $panel->getId(),
            app(PanelManager::class)->all(),
        ),
        'resources' => count(app(PanelManager::class)->resources('admin')->all()),
    ];
});
```

```json
{"manifest":true,"panels":["admin","app"],"resources":1}
```

`"manifest": false` in production means the deploy did not run `panel:cache`.
A `resources` count that dropped means it ran against the wrong tree.

## What the framework logs

Three lines, and only three. Everything else it has to say, it says to the user
on screen.

### The stale manifest warning — development only

```text
[panel] The cached panel manifest is out of date: the classes under the
discovery paths have changed since `php artisan panel:cache` last ran. Until
you run `php artisan panel:clear`, anything added since then is invisible — no
route, no navigation entry, and no error to say so.
```

`Log::warning`, from `PanelManifest::warnIfStale()`, once per boot. It does
nothing unless a manifest exists **and** the environment is `local`, `testing`,
or has debug mode on:

```php
if (! app()->hasDebugModeEnabled() && ! app()->environment('local', 'testing')) {
    return;
}
```

In production the manifest is the authority and nothing should touch the
filesystem, so there is no warning at all. A production deploy that skips
`panel:cache` is silent. That is why the check belongs in the deploy script and
in a health endpoint rather than in a log alert.

### The missing policy notice — development only

```text
[panel] UserResource is not in the navigation because User has no policy, so
viewAny() is denied by default. Create one with `php artisan make:policy
UserPolicy --model=User`, or say so on the resource by overriding canViewAny().
Panel::strictAuthorization() turns this into an exception everywhere the panel
asks, which is worth having in development.
```

`Log::debug`, from `PandaPanel\Support\MissingPolicyNotice`, once per model per
process. Also gated to development, because a resource deliberately hidden from
every user is a legitimate arrangement and a log line per deploy about it is
noise in the one place noise is expensive.

```php
use PandaPanel\Support\MissingPolicyNotice;

MissingPolicyNotice::expectedPolicy(App\Models\User::class);   // 'App\Policies\UserPolicy'
MissingPolicyNotice::forget();                                 // tests only
```

Turn the strict version on in development to make it an exception instead:

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->strictAuthorization();
```

### The integration failure warning — every environment

```php
Log::warning('Panel integration failed.', [
    'integration' => $integration->id,
    'resource' => $integration->resource,
    'trigger' => $integration->trigger->value,
    'delivery' => $deliveryId,
    'exception' => $exception->getMessage(),
]);
```

The only line the package writes in production. It fires when an outbound
integration request throws — DNS, connection refused, TLS — rather than when it
returns a non-2xx status, which is recorded but is not an exception.

The exception is caught rather than allowed to bubble, because this runs inside
the request that is saving a record and a DNS failure is not a reason for that
record not to exist.

Alert on it by context key:

```php
// A log channel or a Sentry filter keyed on the message
'Panel integration failed.'
```

## Delivery history

One row per attempt, in `panel_integration_deliveries`.

```php
use PandaPanel\Integrations\PanelIntegrationDelivery;

PanelIntegrationDelivery::enabled();          // static enabled(): bool
PanelIntegrationDelivery::prune(42);          // static prune(int $integrationId): void
PanelIntegrationDelivery::BODY_LIMIT;         // 2000
```

| Column | Holds |
| --- | --- |
| `integration_id` | which integration |
| `trigger` | the `Trigger` enum case that fired it |
| `method`, `url` | the request that was made |
| `delivery_id` | assigned when the write happened, so all three attempts of one delivery share it |
| `status` | the HTTP status, or `null` when the request threw |
| `duration_ms` | how long it took |
| `error` | the exception message, or a truncated non-2xx body |
| `request_body`, `response_body` | truncated to `BODY_LIMIT` |
| `attempted_at` | when |

**Headers are never stored.** They hold the API keys these requests carry, and a
log of them would be a credential store nobody meant to create.

The table prunes itself after every recorded delivery, twice over and without
anything scheduled:

| Bound | Config key | Default |
| --- | --- | --- |
| Hard cap per integration | `integrations.history.keep_per_integration` | `50` |
| Retention window | `integrations.history.retention_days` | `30` |
| Off entirely | `integrations.history.enabled` | `true` |

```php
// config/panda-panel.php
'integrations' => [
    'history' => [
        'enabled' => true,
        'keep_per_integration' => 50,
        'retention_days' => 30,     // 0 keeps the cap and nothing else
    ],
],
```

The cap is what makes the guarantee true: the table is bounded at
cap × integrations however much traffic there is, in an application with no
scheduler at all. `retention_days` is the second bound, for integrations that
fire twice a year and would otherwise keep rows from three years ago.

## Events you can listen to

Two, both `ShouldBroadcast` and both `Dispatchable`, so a listener sees them
whether or not a broadcaster exists.

```php
use Illuminate\Support\Facades\Event;
use PandaPanel\Notifications\PanelNotificationSent;

Event::listen(PanelNotificationSent::class, function (PanelNotificationSent $event): void {
    // $event->user     Authenticatable
    // $event->payload  array<string, mixed>
});
```

| Event | Constructor | Broadcast as |
| --- | --- | --- |
| `PandaPanel\Notifications\PanelNotificationSent` | `(Authenticatable $user, array $payload)` | `panel.notification` |
| `PandaPanel\Broadcasting\PanelNotification` | `(Authenticatable $user, string $message, string $type = 'info', ?string $url = null, ?string $urlLabel = null)` | `panel.notification` |

Only the *broadcast* is queued. `Notification::send()` dispatches with `event()`,
so an ordinary listener runs synchronously, inside whatever request or job sent
the notification; being `ShouldBroadcast` rather than `ShouldBroadcastNow`
queues the websocket delivery alone. A listener registered for metrics therefore
runs whether or not a worker exists. Everything on them is public and readonly.

There are no lifecycle events for panel boot, resource registration or
navigation. Nothing is dispatched per request.

## Queue and job signals

The panel's jobs are ordinary Laravel jobs and are visible to whatever already
watches them:

```bash
php artisan queue:failed
php artisan queue:monitor default:100
```

| Job | `$tries` | On final failure |
| --- | --- | --- |
| `PandaPanel\Jobs\RunPanelExport` | `3` | notifies the owner, persistently, with the exception's message |
| `PandaPanel\Jobs\RunPanelImport` | `1` | deletes the upload, then notifies with the exception's message |
| `PandaPanel\Jobs\SendPanelIntegration` | `3` | nothing user-facing; the attempt is in the delivery history |

A user-facing failure notification is not a monitoring signal — it goes to one
person's bell. Watch `failed_jobs` for the operational view. See
[Queues](queues.md).

## What the user sees when something breaks

HTTP failures inside a panel are turned into a notification rather than a blank
screen. The defaults:

| Status | Title | Body |
| --- | --- | --- |
| `403` | Not allowed | You do not have permission to do that. |
| `404` | Not found | That record no longer exists. |
| `419` | Session expired | Refresh the page and try again. |
| `429` | Too many requests | Wait a moment and try again. |
| `500` | Something went wrong | The request could not be completed. |
| `503` | Temporarily unavailable | The application is down for maintenance. |

```php
use PandaPanel\Core\Panel;

$panel->getErrorNotifications();   // array<int, array{title: string, body: string|null}|null>
```

A panel's own entries are merged over the framework defaults, so overriding one
status does not restate the rest. These are messages, not logs: the exception
itself goes wherever the application's exception handler sends it.

## Building health checks

Three questions worth answering from a probe, all of them cheap:

```php
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\PanelManager;
use PandaPanel\Support\BroadcastSupport;

app(PanelManifest::class)->exists();                     // did this release cache its panels
BroadcastSupport::isConfigured();                        // can anything reach a browser
app(PanelManager::class)->get('admin')->getPath();       // is the panel registered at all
```

```php
use Illuminate\Support\Facades\Route;

Route::has('panel.admin.dashboard');   // did the route cache include this panel
```

Guard the endpoint. A health route that lists panel ids and resource counts is a
map of the application, and it should not be public.

## What is deliberately absent

| Not provided | Why |
| --- | --- |
| A metrics endpoint | the panel has no runtime numbers of its own worth exposing; queue depth, response time and error rate belong to the application's existing tooling |
| An audit log of panel actions | who changed what is a domain question, and a generic implementation would be wrong for most schemas. Resource lifecycle hooks are the seam |
| A production warning about a stale manifest | it would cost a `stat` per PHP file on every boot, in the one environment where that cost is not worth paying |
| Anything in `php artisan about` | the package registers nothing there; `panel:plugins` is the report |
| Header capture in delivery history | it would be a credential store |

## The report a bug report should contain

```bash
php artisan panel:plugins
php artisan panel:plugins --panel=admin
```

```text
+-------+---------+-----------+------------------+---------+----------+
| Panel | ID      | Name      | Package          | Version | Requires |
+-------+---------+-----------+------------------+---------+----------+
| admin | audit   | Audit Log | acme/panel-audit | 1.4.1   | ^0.1     |
+-------+---------+-----------+------------------+---------+----------+
```

A panel with four plugins has four sources of resources, pages, widgets and
routes, and when one of them misbehaves the first two questions are always
"which plugin" and "which version" — neither of which is answerable from a stack
trace naming this framework. Versions come from Composer's own installed-packages
data rather than from anything a plugin author remembered to bump.

## Gotchas

- **Production is quiet on purpose.** Two of the three log lines are gated to
  development. A missing resource in production produces no signal at all — the
  health check is the signal.
- **`APP_DEBUG=true` in production turns the fingerprint check on**, and it costs
  a `stat` per PHP file under every discovery path per boot.
- **The integration warning fires on exceptions, not on 4xx/5xx responses.** A
  webhook returning 500 every time is recorded in the history table and logs
  nothing.
- **Delivery history is bounded by count first.** Raising `retention_days` does
  not keep more rows if the cap is already trimming them.
- **Only `PanelNotificationSent`'s broadcast is queued.** A listener attached to
  it for metrics runs synchronously wherever the notification was sent, with or
  without a worker. It is the websocket delivery that waits on one.
- **The unread-notification count swallows a `QueryException` and returns `0`.**
  A panel whose `notifications` table is missing renders rather than 500s, which
  means a missing migration shows up as a bell that is always empty.

## See also

- [Production checklist](production-checklist.md), [Queues](queues.md), [Broadcasting server](broadcasting.md)
- [Panel cache](panel-cache.md), [Rollbacks](rollbacks.md)
- [Caching](../concepts/caching.md), [Authorization](../concepts/authorization.md)
- [Configuration reference](../configuration/panda-panel.md) — the `integrations` block, including history bounds
- [`panel:plugins`](../cli/panel-plugins.md)
- [Notifications](../notifications/toast.md), [Database notifications](../notifications/database.md)
