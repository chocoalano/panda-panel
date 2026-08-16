# `panel:clear`

Removes the cached panel manifest, so discovery runs again on the next request.
Reach for it the moment a class you just generated does not appear in a panel,
and as part of any rollback.

```bash
php artisan panel:clear
```

```text
INFO  Panel manifest cleared.
```

## Signature

```text
panel:clear
```

No arguments and no options.

```php
// PandaPanel\Console\Commands\ClearPanelsCommand
public function handle(PandaPanel\Cache\PanelManifest $manifest): int
{
    $manifest->clear();

    $this->components->info('Panel manifest cleared.');

    return self::SUCCESS;
}
```

It deletes `bootstrap/cache/panels.php` and forgets the in-memory copy held by
that `PanelManifest` instance.

## Idempotent

A missing manifest is success, not an error:

```bash
php artisan panel:clear
php artisan panel:clear    # still INFO, still exit 0
```

That matters because the command is registered as an `optimize` hook, and
`optimize:clear` on a fresh checkout — where nothing was ever cached — must not
fail.

## Part of `optimize:clear`

```bash
php artisan optimize:clear    # config, routes, views, events — and panel:clear
```

Registered under the key `panels`, alongside `panel:cache` on the way in.

## The symptom it fixes

A cached manifest is a list of class names, and discovery then never runs. A
resource, page or widget added afterwards is simply not in the panel:

| What you see | What is happening |
| --- | --- |
| A generated resource has no sidebar entry | Its class is not in the manifest |
| Its URL 404s | No route was registered for a class the panel does not know about |
| A new widget never appears on the dashboard | Same |
| Nothing at all is logged | There is no error to log — the panel is behaving exactly as configured |

In `local` or `testing`, or with debug on, boot warns about this before you have
to guess:

```text
[panel] The cached panel manifest is out of date: the classes under the
discovery paths have changed since `php artisan panel:cache` last ran. Until
you run `php artisan panel:clear`, anything added since then is invisible — no
route, no navigation entry, and no error to say so.
```

Production gets no warning, deliberately: there the manifest is expected to be
current, and a console message on a live panel helps nobody.

## Clearing it from code

```php
use PandaPanel\Cache\PanelManifest;

app(PanelManifest::class)->clear();   // bool: whether a file was deleted
```

`clear()` returns whether the file existed. Tests that register panels of their
own usually do not need it — a panel absent from the manifest falls back to
discovery for itself alone.

## Exit code

Always `0`.

## Gotchas

- **It clears the panel manifest and nothing else.** Config, routes, views and
  events have their own caches; `optimize:clear` covers all of them.
- **Under Octane it does not reach running workers.** Each worker holds its own
  loaded copy until it is recycled, so a deploy has to restart them.
- **Clearing on every request is not a strategy.** If a production panel needs
  clearing to show new classes, the deploy is caching before it finishes copying
  code — fix the order rather than the symptom.
- **A cleared manifest costs a filesystem scan per panel per request.** That is
  the correct trade in development and the wrong one in production.

## See also

- [panel:cache](panel-cache.md)
- [Caching](../concepts/caching.md)
- [Discovery](../concepts/discovery.md)
- [Panel cache in production](../deployment/panel-cache.md), [Rollbacks](../deployment/rollbacks.md)
- [Octane](../deployment/octane.md)
- [Panel routes 404](../troubleshooting/panel-routes-404.md)
- [make:panel-resource](make-panel-resource.md), [make:panel-page](make-panel-page.md), [make:panel-widget](make-panel-widget.md)
