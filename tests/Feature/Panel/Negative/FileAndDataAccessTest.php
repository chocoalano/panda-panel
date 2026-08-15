<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use App\Panels\Admin\Resources\Users\Imports\UserImporter;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Notifications\Notification;

/**
 * Reaching files and rows that belong to somebody else.
 *
 * An export is a copy of every record the exporter could see, written to disk
 * and left there. A failure report is a copy of what somebody tried to import.
 * Both are exactly the kind of file worth guessing at, and both are addressed
 * by a name the request supplies.
 *
 * The design says the directory is built from the authenticated user and the
 * request only ever names a file. These tests are the two halves of that: a
 * path cannot be smuggled through the name, and a name cannot reach another
 * user's directory.
 */
beforeEach(function (): void {
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();
    $this->other = User::factory()->admin()->create();
});

/*
 * Path traversal through the file name
 */

it('refuses every shape of traversal in an export file name', function (): void {
    // Both rungs a traversal would climb to: the disk root, and the directory
    // the per-user folders sit in. Without these the directories do not exist,
    // every attempt misses for the wrong reason, and the test would pass with
    // the guard deleted.
    Storage::disk('local')->put('secret.csv', 'ROOT SECRET');
    Storage::disk('local')->put(UserExporter::directory().'/secret.csv', 'PARENT SECRET');

    $this->actingAs($this->admin);

    $attempts = [
        '../secret.csv',
        '../../secret.csv',
        '..%2Fsecret.csv',
        '..%2F..%2Fsecret.csv',
        '%2e%2e%2fsecret.csv',
        '....//secret.csv',
        // Percent-encoded: a raw backslash is refused by the HTTP layer before
        // the application sees it, which is a different guard than this one.
        '..%5Csecret.csv',
        '/etc/passwd',
        'subdir/secret.csv',
        // The two that reach the controller as a name rather than being
        // normalised away by the router, and the reason the guard is not
        // redundant with route matching: without it these are a 500.
        '.',
        '..',
    ];

    foreach ($attempts as $attempt) {
        $response = $this->get('/admin/exports/'.$attempt.'?exporter='.urlencode(UserExporter::class));

        expect($response->getStatusCode())
            ->toBeIn([403, 404], "exports/{$attempt} answered {$response->getStatusCode()}");

        if ($response->getStatusCode() === 200) {
            expect($response->streamedContent())->not->toContain('SECRET');
        }
    }
});

it('refuses every shape of traversal in an import report name', function (): void {
    Storage::disk('local')->put('secret.csv', 'ROOT SECRET');
    Storage::disk('local')->put(UserImporter::directory().'/secret.csv', 'PARENT SECRET');

    $this->actingAs($this->admin);

    $attempts = ['../secret.csv', '..%2Fsecret.csv', '/etc/passwd', 'subdir/secret.csv', '.', '..'];

    foreach ($attempts as $attempt) {
        $response = $this->get('/admin/imports/'.$attempt.'?importer='.urlencode(UserImporter::class));

        expect($response->getStatusCode())
            ->toBeIn([403, 404], "imports/{$attempt} answered {$response->getStatusCode()}");
    }
});

/*
 * Reaching another user's directory
 */

it('does not let one user download another user\'s export', function (): void {
    Storage::disk('local')->put(
        UserExporter::directory().'/'.$this->other->id.'/report.csv',
        'name,email',
    );

    // The name is right; the directory it lives in is not this user's.
    $this->actingAs($this->admin)
        ->get('/admin/exports/report.csv?exporter='.urlencode(UserExporter::class))
        ->assertNotFound();
});

it('does not let one user download another user\'s import report', function (): void {
    Storage::disk('local')->put(
        UserImporter::directory().'/'.$this->other->id.'/failures.csv',
        'row,error',
    );

    $this->actingAs($this->admin)
        ->get('/admin/imports/failures.csv?importer='.urlencode(UserImporter::class))
        ->assertNotFound();
});

it('serves a user their own export, so the refusals above are not just a broken endpoint', function (): void {
    Storage::disk('local')->put(
        UserExporter::directory().'/'.$this->admin->id.'/report.csv',
        'name,email',
    );

    $this->actingAs($this->admin)
        ->get('/admin/exports/report.csv?exporter='.urlencode(UserExporter::class))
        ->assertOk();
});

/*
 * Naming a class that is not an exporter
 */

it('refuses an exporter parameter naming an arbitrary class', function (): void {
    $this->actingAs($this->admin);

    foreach ([User::class, 'Illuminate\Support\Facades\Storage', 'NoSuchClass', ''] as $class) {
        $this->get('/admin/exports/report.csv?exporter='.urlencode($class))
            ->assertNotFound();
    }
});

it('refuses an importer parameter naming an arbitrary class', function (): void {
    $this->actingAs($this->admin);

    foreach ([User::class, 'NoSuchClass', ''] as $class) {
        $this->get('/admin/imports/report.csv?importer='.urlencode($class))
            ->assertNotFound();
    }
});

it('refuses a download to a guest before looking at anything else', function (): void {
    $this->get('/admin/exports/report.csv?exporter='.urlencode(UserExporter::class))
        ->assertRedirect();
});

/*
 * Notifications belong to one user
 */

it('does not let a user read another user\'s notifications', function (): void {
    Notification::make('theirs')->title('Theirs')->persistent()->send($this->other);

    $body = $this->actingAs($this->admin)
        ->getJson('/admin/notifications')
        ->assertOk()
        ->json();

    expect($body['notifications'])->toBeEmpty()
        ->and($body['unread'])->toBe(0);
});

it('does not let a user mark another user\'s notification as read', function (): void {
    Notification::make('theirs')->title('Theirs')->persistent()->send($this->other);

    $notification = $this->other->notifications()->firstOrFail();

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/read', ['id' => $notification->getKey()])
        ->assertOk();

    // Scoped to the caller's own notifications, so the id matches nothing.
    expect($this->other->unreadNotifications()->count())->toBe(1);
});

it('does not let a user clear another user\'s notifications', function (): void {
    Notification::make('theirs')->title('Theirs')->persistent()->send($this->other);

    $this->actingAs($this->admin)
        ->postJson('/admin/notifications/clear', ['all' => true])
        ->assertOk();

    expect($this->other->notifications()->count())->toBe(1);
});

/*
 * Global search answers only what the user may see
 */

it('returns nothing from global search for a user who may not read the resource', function (): void {
    $member = User::factory()->create();

    User::factory()->create(['name' => 'Findable Person']);

    // A member cannot enter the admin panel at all, so the palette behind it
    // is not a second way in.
    $this->actingAs($member)
        ->get('/admin/search?q=Findable')
        ->assertForbidden();
});
