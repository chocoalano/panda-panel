<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Jobs\RunPanelExport;
use PandaPanel\Jobs\RunPanelImport;

/*
|--------------------------------------------------------------------------
| What a queued import or export does when it fails
|--------------------------------------------------------------------------
|
| Both jobs used to have only a `handle()`. A run that threw therefore did two
| things silently: it left the uploaded file on the disk — a copy of somebody's
| customer data that nothing would ever delete — and it left the user watching
| a notification bell that would never ring, because the only code that sends
| one sits past the line that threw.
|
| The two jobs are also deliberately not configured the same way, and the
| difference is not a preference: an export only reads rows and writes a file,
| so it can be retried; an import writes rows, and a half-finished one cannot
| be replayed without writing some of them twice.
|
*/

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_admin' => true]);
});

it('retries an export, because writing a file changes nothing', function (): void {
    $job = new RunPanelExport(
        UserExporter::class,
        UserResource::class,
        ['name'],
        SpreadsheetFormat::Csv,
        $this->user->getKey(),
        [],
        null,
        'admin',
    );

    // A failed attempt leaves a half-written file that the next attempt
    // replaces, so the usual causes — a dropped connection, a briefly
    // unreachable disk — are worth another go.
    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

it('never retries an import, because writing rows cannot be replayed', function (): void {
    $job = new RunPanelImport(
        UserImporter::class,
        'imports/whatever.csv',
        ['name' => 0],
        $this->user->getKey(),
        'admin',
    );

    // A run that failed halfway has already written some rows and there is no
    // general way to know which. Retrying would turn one bad import into two.
    expect($job->tries)->toBe(1);
});

it('deletes the uploaded file when an import fails', function (): void {
    Storage::fake(UserImporter::disk());

    Storage::disk(UserImporter::disk())->put('imports/people.csv', "name\nAda\n");

    $job = new RunPanelImport(
        UserImporter::class,
        'imports/people.csv',
        ['name' => 0],
        $this->user->getKey(),
        'admin',
    );

    $job->failed(new RuntimeException('unsupported file format'));

    // The upload was a means, not a record — the same reason it is deleted on
    // success. Leaving it would accumulate customer data nobody asked to
    // store, and a failure is exactly when nobody is watching for it.
    expect(Storage::disk(UserImporter::disk())->exists('imports/people.csv'))->toBeFalse();
});

it('tells the user an import failed, and why', function (): void {
    Storage::fake(UserImporter::disk());

    fakePanelNotifications();

    $job = new RunPanelImport(
        UserImporter::class,
        'imports/people.csv',
        ['name' => 0],
        $this->user->getKey(),
        'admin',
    );

    $job->failed(new RuntimeException('column count mismatch on row 12'));

    // The exception's own message, not a generic sentence: "column count
    // mismatch on row 12" tells somebody how to fix their file.
    assertPanelNotificationSentTo($this->user, 'Import failed');
});

it('tells the user an export failed rather than leaving them waiting', function (): void {
    fakePanelNotifications();

    $job = new RunPanelExport(
        UserExporter::class,
        UserResource::class,
        ['name'],
        SpreadsheetFormat::Csv,
        $this->user->getKey(),
        [],
        null,
        'admin',
    );

    $job->failed(new RuntimeException('the disk went away'));

    assertPanelNotificationSentTo($this->user, 'Export failed');
});

it('says nothing to a user who no longer exists', function (): void {
    Storage::fake(UserImporter::disk());

    $key = $this->user->getKey();

    $this->user->forceDelete();

    $job = new RunPanelImport(
        UserImporter::class,
        'imports/people.csv',
        ['name' => 0],
        $key,
        'admin',
    );

    // An account deleted between upload and failure is a normal race, not a
    // second error to raise inside a failure handler.
    expect(fn () => $job->failed(new RuntimeException('nope')))->not->toThrow(Throwable::class);
});
