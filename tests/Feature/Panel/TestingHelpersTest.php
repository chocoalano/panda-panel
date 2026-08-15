<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Core\PanelManager;
use PandaPanel\Notifications\Notification;
use PandaPanel\Tables\Filters\TernaryFilter;

/**
 * The helpers, tested against the real admin resource.
 *
 * A helper is only worth having if it goes through the same code the
 * application does — one that computed its own idea of what a table shows
 * would pass while the table was broken. These tests are as much about that
 * as about the helpers: each one asserts something that is true of the
 * resource, not of the helper.
 */
beforeEach(function (): void {
    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($this->admin);
});

/*
 * Tables
 */

it('says which records a table is showing', function (): void {
    $other = User::factory()->create(['name' => 'Grace Hopper']);

    panelTable(UserResource::class)
        ->assertCanSeeRecord($this->admin)
        ->assertCanSeeRecord($other)
        ->assertCount(2);
});

it('filters a table the way a URL does', function (): void {
    $unverified = User::factory()->unverified()->create(['name' => 'Grace Hopper']);

    // `verified` is a ternary filter on the users table, and the admin from
    // the factory is verified — so this narrows to exactly one row.
    panelTable(UserResource::class)
        ->filter(['verified' => TernaryFilter::FALSE])
        ->assertCanSeeRecord($unverified)
        ->assertCanNotSeeRecord($this->admin);
});

it('searches a table', function (): void {
    $grace = User::factory()->create(['name' => 'Grace Hopper']);

    panelTable(UserResource::class)
        ->search('Grace')
        ->assertCanSeeRecord($grace)
        ->assertCanNotSeeRecord($this->admin);
});

it('sorts a table and asserts the order', function (): void {
    $grace = User::factory()->create(['name' => 'Grace Hopper']);

    panelTable(UserResource::class)
        ->sort('name', 'asc')
        ->assertRecordsInOrder([$this->admin, $grace]);
});

it('reads one cell as the frontend receives it', function (): void {
    panelTable(UserResource::class)
        ->assertColumnExists('email')
        ->assertCellEquals($this->admin, 'email', $this->admin->email);
});

it('reads an editable cell as the payload the input is bound to', function (): void {
    // `name` is a TextInputColumn, so its cell is the input's state rather
    // than a string — which is the sort of thing a test should be able to
    // see, because that shape is what the frontend binds to.
    panelTable(UserResource::class)
        ->assertCellEquals($this->admin, 'name', ['value' => 'Ada Lovelace', 'disabled' => false]);
});

/*
 * Actions
 */

it('finds the actions a resource declares, and only those', function (): void {
    panelRecordActions(UserResource::class)
        ->assertExists('edit')
        ->assertExists('delete')
        ->assertDoesNotExist('invented');
});

it('reports an action as hidden when it is refused for the record', function (): void {
    // Deleting yourself is refused by the policy, so the button is absent
    // from that row rather than answering 403.
    panelRecordActions(UserResource::class)
        ->assertVisible('edit', $this->admin);
});

it('runs a table action through the schema that declared it', function (): void {
    User::factory()->unverified()->create();

    panelTableActions(UserResource::class)->call('purgeUnverified');

    expect(User::query()->whereNull('email_verified_at')->count())->toBe(0);
});

it('reaches the bulk and infolist scopes too', function (): void {
    // Four scopes, matching the four places a schema declares actions — a
    // record action and a bulk action can share a name and be different
    // things, so the scope is part of the lookup.
    panelBulkActions(UserResource::class)->assertExists('verify');

    panelInfolistActions(UserResource::class)->assertExists('note');
});

it('says an action is refused for a user who may not run it', function (): void {
    $this->actingAs(User::factory()->create());

    // The assertion worth writing: the button being absent. `call()` checks
    // this too and fails rather than running — a helper that ran it anyway
    // would prove the handler works and nothing about whether it is
    // reachable.
    panelTableActions(UserResource::class)->assertCanNotRun('purgeUnverified');
});

/*
 * Forms
 */

it('says which fields a form declares', function (): void {
    panelForm(UserResource::class)
        ->assertHasField('name')
        ->assertHasField('email')
        ->assertDoesNotHaveField('invented');
});

it('says which fields are required', function (): void {
    panelForm(UserResource::class)->assertFieldIsRequired('name');
});

it('says what a form would actually write', function (): void {
    $written = panelForm(UserResource::class)->dehydrate([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'unknown' => 'discarded',
    ]);

    // The place most form bugs live: a field that validates and then does
    // not persist, or one that persists under a different name.
    expect($written)->toHaveKey('name')
        ->and($written)->not->toHaveKey('unknown');
});

it('asserts the whole dehydrated payload at once', function (): void {
    panelForm(UserResource::class)->assertDehydratesTo(
        ['name' => 'Ada', 'unknown' => 'discarded'],
        ['name' => 'Ada'],
    );
});

/*
 * Notifications
 */

it('asserts a toast reached a user', function (): void {
    fakePanelNotifications();

    Notification::make('saved')->title('Saved.')->send($this->admin);

    assertPanelNotificationSentTo($this->admin, 'Saved.');
});

it('asserts nothing was sent', function (): void {
    fakePanelNotifications();

    assertNoPanelNotifications();
});

it('asserts a notification can be found later', function (): void {
    Notification::make('export')->title('Export ready')->persistent()->send($this->admin);

    // A different question from the toast: a broadcast that was not persisted
    // is gone the moment the tab closes.
    assertPanelNotificationStoredFor($this->admin, 'Export ready');
});
