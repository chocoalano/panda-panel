<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PandaPanel\Support\PackageSchema;

/*
|--------------------------------------------------------------------------
| Rolling this package back must not take the application's data with it
|--------------------------------------------------------------------------
|
| `notifications` is Laravel's table, not the panel's. The `up()` skips it
| when it is already there, which means `down()` is looking at a table this
| package may never have made — and `dropIfExists()` in that position deletes
| an application's notifications on a rollback that was only meant to undo an
| install.
|
| Everything here is about the one question that decides it: did we make this?
|
*/

const NOTIFICATIONS_MIGRATION = '2026_08_14_130919_create_notifications_table';

/**
 * @var list<string>
 */
const NOTIFICATION_COLUMNS = [
    'id',
    'type',
    'notifiable_type',
    'notifiable_id',
    'data',
    'read_at',
    'created_at',
    'updated_at',
];

/**
 * The package's own migration, loaded from the file the application would run.
 */
function notificationsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 3).'/database/migrations/'.NOTIFICATIONS_MIGRATION.'.php';

    return $migration;
}

/**
 * Records a migration as having run, the way the migrator would.
 */
function recordRanMigration(string $name): void
{
    DB::table('migrations')->insert(['migration' => $name, 'batch' => 99]);
}

it('drops the notifications table it created', function (): void {
    expect(Schema::hasTable('notifications'))->toBeTrue();

    // Nothing else claims the table and the columns are exactly ours, which
    // is the only combination that means "this is mine to drop".
    notificationsMigration()->down();

    expect(Schema::hasTable('notifications'))->toBeFalse();
});

it('leaves a notifications table another migration claims', function (): void {
    // What Laravel's own skeleton, or `make:notifications-table`, leaves
    // behind: a ran migration saying out loud that the table is the
    // application's.
    recordRanMigration('0001_01_01_000002_create_notifications_table');

    notificationsMigration()->down();

    expect(Schema::hasTable('notifications'))->toBeTrue();
});

it('leaves a notifications table the application has built on', function (): void {
    // No competing migration — the table could have been made by hand, or by
    // a package that does not name its migrations conventionally. An extra
    // column is enough to say somebody else has been here.
    Schema::table('notifications', function (Blueprint $table): void {
        $table->string('channel')->nullable();
    });

    notificationsMigration()->down();

    expect(Schema::hasTable('notifications'))->toBeTrue();
});

it('recognises its own published copy rather than treating it as a rival', function (): void {
    // `vendor:publish --tag=panda-panel-migrations` copies the file under the
    // same name. Seeing itself in the repository must not read as another
    // migration having claimed the table.
    recordRanMigration(NOTIFICATIONS_MIGRATION);

    expect(PackageSchema::isOwned('notifications', NOTIFICATIONS_MIGRATION, NOTIFICATION_COLUMNS))
        ->toBeTrue();
});

it('leaves a table it cannot find at all', function (): void {
    Schema::drop('notifications');

    // Rolling back twice, or rolling back after the application dropped the
    // table itself, is not an error.
    notificationsMigration()->down();

    expect(Schema::hasTable('notifications'))->toBeFalse();
});

/*
| The `users` column is a different case: nothing else has a reason to name a
| column `two_factor_email_confirmed_at`, so a rollback that removes it is
| removing what it added. What it must never do is touch the table around it.
*/

it('drops only its own column from the users table', function (): void {
    $before = Schema::getColumnListing('users');

    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 3).'/database/migrations/2026_08_14_143501_add_email_two_factor_to_users_table.php';

    $migration->down();

    $after = Schema::getColumnListing('users');

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(array_values(array_diff($before, $after)))->toBe(['two_factor_email_confirmed_at']);
});
