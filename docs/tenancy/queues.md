# Queues and Tenant Context

A queued job runs outside the request that dispatched it, and the tenant
binding does not travel with it. `PandaPanel\Tenancy\Tenancy` stores the tenant
in `PandaPanel\Support\PanelContext`, which is a `scoped()` container binding —
and Laravel's queue worker calls `forgetScopedInstances()` between jobs, so
every job starts with nothing bound. This page is what to do about that.

## Entering a tenant in a job

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use PandaPanel\Tenancy\Tenancy;

final class RebuildWorkspaceIndex implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $workspaceKey) {}

    public function handle(): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceKey);

        Tenancy::for($workspace, function (): void {
            // Everything in here reads through the bound tenant.
            DocumentResource::query()->each(/* ... */);
        });
    }
}
```

```php
RebuildWorkspaceIndex::dispatch($workspace->getKey());
```

Carry the **key**, not the model. A serialized model reloads on the far side
through whatever connection is current, which in a database-per-tenant
arrangement is not the one it was serialized from.

## `Tenancy::for()`

```php
/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
public static function for(Model $tenant, callable $callback): mixed
```

Binds, runs, and restores the previous binding in a `finally`:

```php
public static function for(Model $tenant, callable $callback): mixed
{
    $previous = self::current();

    self::bind($tenant);

    try {
        return $callback();
    } finally {
        $context = app(PanelContext::class);

        if ($previous === null) {
            $context->set(self::KEY, null);
        } else {
            $context->set(self::KEY, $previous);
        }
    }
}
```

Restoring in a `finally` is the whole point. A callback that throws must not
leave the rest of the process scoped to somebody else's tenant — which in a
long-running worker is not one request but every job after it.

```php
Tenancy::bind($acme);

try {
    Tenancy::for($beta, fn () => throw new RuntimeException('nope'));
} catch (RuntimeException) {
}

Tenancy::current()?->getKey();   // still Acme
```

Nested calls are safe, and starting from nothing leaves nothing bound.

## The panel matters too

A job that reads a resource needs the panel as well as the tenant. `panel()` is
how a resource finds its per-panel configuration, its URLs and — through
`hasTenancy()` — whether to scope at all:

```php
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;

public function handle(PanelManager $manager): void
{
    $manager->setCurrentPanel($manager->get('app'));

    Tenancy::for($workspace, static fn () => DocumentResource::query()->count());
}
```

Without the panel, `applyTenantScope()` returns early and the query runs
**unscoped** — the failure this whole mechanism exists to prevent, and one that
looks like a working job. Set both, always, in that order.

## The framework's own jobs

Three jobs ship with the package:

| Job | Carries | Sets the panel | Binds a tenant |
| --- | --- | --- | --- |
| `PandaPanel\Jobs\RunPanelExport` | exporter, resource, columns, format, owner, table state, keys, panel id | yes | **no** |
| `PandaPanel\Jobs\RunPanelImport` | importer, path, mapping, owner, panel id | yes | **no** |
| `PandaPanel\Jobs\SendPanelIntegration` | integration id, payload, timeout, delivery id | no | no |

```php
public function handle(PanelManager $manager): void
{
    $panel = $manager->get($this->panelId);

    // A resource's scope, its table, and its URLs are all read through the
    // current panel. Without this the job would be running outside any panel
    // and `Resource::query()` would answer for none.
    $manager->setCurrentPanel($panel);

    // ...
}
```

They were written before tenancy and carry no tenant id. What that means
depends on your arrangement:

**Database per tenant.** `stancl/tenancy`'s `QueueTenancyBootstrapper` restores
the *connection* around a job dispatched inside a tenant context, and nothing
in these jobs is scoped by the panel, so a queued export produces the right
file. Without that bootstrapper the export would run against the central
database and quietly produce the wrong one.

**Single database, with a scoped resource.** The job sets the panel, the panel
has tenancy, the resource names a relationship, nothing is bound — so
`Tenancy::require()` throws `PanelRegistrationException` and the job fails:

> This panel is tenant-scoped, but no tenant is bound to this request…

A loud failure rather than a file containing every tenant's rows, which is the
correct trade. Three ways out, in order of preference:

1. **Do not queue that export or import.** A negative `queueAfter()` never
   queues, whatever the row count:

   ```php
   final class DocumentExporter extends Exporter
   {
       public static function queueAfter(): int
       {
           return -1;   // always run in the request
       }
   }
   ```

   `Exporter::queueAfter()` defaults to `2000` and `Importer::queueAfter()` to
   `500`; both queue when the count exceeds the number, and `0` always queues.

2. **Dispatch your own job** that carries the tenant key and wraps the work in
   `Tenancy::for()`, using `ExportRun`/`ImportRun` directly.

3. **Restore the binding from a queue hook** you own, if your infrastructure
   already carries a tenant id on every job.

## Console commands and schedulers

Same rule, no request involved:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use PandaPanel\Core\PanelManager;
use PandaPanel\Tenancy\Tenancy;

final class ReportPerWorkspace extends Command
{
    protected $signature = 'workspaces:report';

    public function handle(PanelManager $manager): int
    {
        $manager->setCurrentPanel($manager->get('app'));

        Workspace::query()->each(function (Workspace $workspace): void {
            Tenancy::for($workspace, function () use ($workspace): void {
                $this->line($workspace->name.': '.DocumentResource::query()->count());
            });
        });

        return self::SUCCESS;
    }
}
```

`each()` outside the loop's `Tenancy::for()`, deliberately: the list of tenants
is a central question, and asking it from inside a tenant would cross the
boundary the design exists to keep.

## Notifications

Panel notifications are ordinary Laravel notifications, so they follow Laravel's
rules. Two things are worth knowing:

- `PandaPanel\Notifications\TwoFactorCode` implements `ShouldQueue`. In a
  database-per-tenant arrangement the emailed code is written to the cache,
  which is why `CacheTenancyBootstrapper` is not optional — see
  [Database Per Tenant](database-per-tenant.md).
- A persistent notification is a row in `notifications`. That table lives in the
  tenant database in a database-per-tenant arrangement, so the job that writes
  it must run on the tenant's connection.

## Octane

The same reasoning covers Octane, and it is already handled. `PanelContext` is
bound with `scoped()`, so the container flushes it between requests, and
`PandaPanel\Http\Middleware\ResetPanelContext` runs at the start of every `web`
request as a second line. Nothing tenant-related is held in a static — which is
exactly why `Tenancy` keeps the tenant in the context rather than in one.

## Notes

- **A job that does not read a resource does not need any of this.** Binding a
  tenant matters for `Resource::query()` and for your own code that calls
  `Tenancy::current()`; a job that writes a file from data it was given needs
  neither.
- **`Tenancy::bind()` without a matching restore is for `ResolveTenant` and
  tests.** In a worker it leaks into the next job on that process. Use `for()`.
- **The queue connection is not the tenant boundary.** Whether jobs live in a
  central `jobs` table or per tenant is a separate decision — central is simpler
  and means one worker; per tenant means one tenant cannot fill another's queue.
- **`Tenancy::for()` returns the callback's value**, so it composes:
  `$count = Tenancy::for($workspace, fn (): int => DocumentResource::query()->count());`
- **Failing loudly is the design.** Every alternative — skipping the scope,
  falling back to "no tenant" — produces a job that succeeds with the wrong
  rows.

## See also

- [Tenancy Concepts](concepts.md)
- [Resource Tenant Scoping](resource-scoping.md)
- [Database Per Tenant](database-per-tenant.md)
- [Single Database Tenancy](single-database.md)
- [Using with stancl/tenancy](stancl-tenancy.md)
- [Tenancy Security Checklist](security-checklist.md)
- [Panel Context](../concepts/panel-context.md)
- [Queued Exports](../import-export/queued-exports.md)
- [Queued Imports](../import-export/queued-imports.md)
- [Queues in Production](../deployment/queues.md)
- [Octane](../deployment/octane.md)
