# Migration Loading

The package ships four migrations and runs them from the package by default. One config key turns
that off, and one publish tag hands them to the application instead. Reach for this page when you
want the schema in your own `database/migrations`, or when you need to know exactly what
`php artisan migrate` will do to an application that already has some of these tables.

## A minimal working example

```php
// config/panda-panel.php

'load_migrations' => true,
```

```bash
php artisan migrate
```

That is the default, and it is why a fresh install works without a publish step: the notification
centre reads the `notifications` table on every panel request, and an install that had to remember
to publish a migration would 500 on its very first page.

To own them instead:

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

```php
'load_migrations' => false,
```

In that order, and both halves — see [Publishing](#publishing) below.

## What ships

Four files, in `database/migrations` inside the package:

| File | Creates | Rollback |
| --- | --- | --- |
| `2026_08_14_130919_create_notifications_table.php` | Laravel's `notifications` table | only when this package made it |
| `2026_08_14_143501_add_email_two_factor_to_users_table.php` | `users.two_factor_email_confirmed_at` | drops the column |
| `2026_08_15_120000_create_panel_integrations_table.php` | `panel_integrations` | drops the table |
| `2026_08_15_140000_add_history_and_signing_to_panel_integrations.php` | `panel_integrations.secret`, `panel_integration_deliveries` | drops both |

Every `up()` checks before it touches anything, so an application that already has the table or
the column is untouched.

### `notifications`

Laravel's own schema, not one of the panel's. The notification centre reads through `Notifiable`,
so `markAsRead()`, `unreadNotifications` and the rest work without a line of code, and a
notification sent by anything else in the application shows up in the panel's bell too.

```php
Schema::create('notifications', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();

    $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
});
```

`read_at` is indexed beside the owner because the only two questions ever asked of this table are
"what does this user have" and "how many has this user not read".

`up()` returns early when `Schema::hasTable('notifications')` is true — most applications already
have it, and a package migration that assumed otherwise would fail the first `migrate` after
install.

### `users.two_factor_email_confirmed_at`

```php
$after = Schema::hasColumn('users', 'two_factor_confirmed_at')
    ? 'two_factor_confirmed_at'
    : null;

Schema::table('users', function (Blueprint $table) use ($after): void {
    $column = $table->timestamp('two_factor_email_confirmed_at')->nullable();

    if ($after !== null) {
        $column->after($after);
    }
});
```

A timestamp rather than a boolean, for the same reason `email_verified_at` is one: "when did they
turn this on" is worth being able to answer, and "is it on" is `!== null`. Positioned after
Fortify's own column when the application has it, and appended when it does not — this ships in a
package and cannot assume it runs second.

The codes themselves are not in the database. They live in the cache, keyed by user and stored
hashed, because a one-time code that expires in ten minutes has no business outliving a cache
flush. See [Email Code Challenge](../authentication/email-code-challenge.md).

The whole migration is a no-op when there is no `users` table, or when the column is already
there.

### `panel_integrations` and `panel_integration_deliveries`

Only used by a resource that opted into integrations. They are created unconditionally — unlike
`notifications`, nothing else owns a table by these names — so their `down()` may drop them.

The second migration is separate rather than an edit to the first, because the first may already
have run somewhere: a migration that changes shape after it has been applied is a migration that
runs differently depending on when you installed.

The two config keys that bound the delivery history are in
[config/panda-panel.php](panda-panel.md#integrations).

## Rollback, and why `notifications` is special

`up()` skips a `notifications` table it finds, so `down()` is looking at a table this package may
never have made. `dropIfExists()` in that position deletes an application's notifications on a
rollback that was only ever meant to undo this package.

There is no place to write "I made this" that survives a deploy without the package owning a table
of its own, so ownership is inferred from two signals, and the answer is no unless both agree.

```php
namespace PandaPanel\Support;

final class PackageSchema
{
    /**
     * @param  string  $migration  the dropping migration's own recorded name
     * @param  list<string>  $columns  the exact column list up() creates
     */
    public static function dropIfOwned(string $table, string $migration, array $columns): void;

    /**
     * @param  list<string>  $columns
     */
    public static function isOwned(string $table, string $migration, array $columns): bool;
}
```

| Signal | Meaning |
| --- | --- |
| No *other* ran migration is named `*_create_<table>_table` | Nobody else claims the table. Our own name is excluded, because a published copy of this migration is still this migration. |
| `Schema::getColumnListing()` matches the exact column list `up()` creates | An extra column is somebody having built on the table, which is enough to leave it alone. |

The first check fails safe: a migration repository that cannot be read at all — a connection that
has gone away mid-rollback — answers "claimed by somebody else", because an unanswerable ownership
question is not a licence to drop. A database with no `migrations` table is the one case that is
not an error: nothing ran, so nothing claims the table, and the column list is then the only
signal left.

```php
use PandaPanel\Support\PackageSchema;

PackageSchema::isOwned('notifications', '2026_08_14_130919_create_notifications_table', [
    'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at',
]);
```

The migration passes `basename(__FILE__, '.php')` rather than a literal, so a copy published into
`database/migrations` keeps the filename and still recognises itself.

The `users` column has no such ambiguity — nothing else has a reason to name a column
`two_factor_email_confirmed_at` — so its `down()` drops it outright, behind a `hasTable()` guard for
the case where the application's own `create_users_table` was rolled back first.

## Publishing

```bash
php artisan vendor:publish --tag=panda-panel-migrations
```

Copies all four into `database_path('migrations')`. `php artisan panel:install` offers the same
thing interactively, with the hint that matters: they already run from the package, so publish
only to own them — and then turn `load_migrations` off.

```php
'load_migrations' => false,
```

Skipping the second half is not a disaster, but it is untidy for a reason worth knowing. The
migrator keys migration files by name and merges the application's own `database/migrations` path
last, so a published copy with the same filename shadows the package's and the schema is still
applied once. Rename or restructure the published file and you have two different migration names
creating the same table — at which point the second fails, or silently does nothing thanks to the
guards. One source of truth is the point of the key.

## Turning it off entirely

```php
'load_migrations' => false,
```

Compared with `=== true`, so anything other than boolean `true` skips
`loadMigrationsFrom()`. Nothing else changes: the panel still boots, still routes, still renders.

What breaks is what those tables are for.

| Missing | Effect |
| --- | --- |
| `notifications` | The bell reads zero. `SharePanelData` catches the `QueryException` and answers `0` rather than 500ing every panel page. |
| `users.two_factor_email_confirmed_at` | The emailed-code factor cannot be enabled. |
| `panel_integrations` | The integrations screen has nothing to read, for the resources that enabled it. |

The first is deliberate: until `migrate` has run, an empty bell is the honest answer and the panel
still works.

## Gotchas

- **Ordering is by filename, across every registered path.** The package's are dated
  `2026_08_14` and later, so they run after an application's own `0001_01_01_*` skeleton — which
  is what lets the `users` column find `two_factor_confirmed_at` to sit after.
- **A rollback that finds a `notifications` table it did not make leaves it standing.** That is
  the correct outcome and it means `migrate:rollback` is not always symmetric with `migrate`.
- **`migrate:fresh` drops everything regardless.** `PackageSchema` protects a rollback, not a
  `db:wipe`.
- **Publishing does not disable loading.** They are two separate decisions and both are yours.
- **In a package test suite, load them explicitly.** Testbench does not read your application's
  config for this:

  ```php
  protected function defineDatabaseMigrations(): void
  {
      $this->loadMigrationsFrom(__DIR__.'/../vendor/chocoalano/panel/database/migrations');
  }
  ```

## See also

- [config/panda-panel.php](panda-panel.md)
- [Publish Tags](../cli/publish-tags.md)
- [Running panel:install](../getting-started/installer.md)
- [User Model](../authentication/user-model.md)
- [Email Code Challenge](../authentication/email-code-challenge.md)
- [Database Notifications](../notifications/database.md)
- [Notification Centre](../notifications/notification-center.md)
- [Production Checklist](../deployment/production-checklist.md)
- [Test Setup](../testing/setup.md)
