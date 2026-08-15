<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Pages\CreateUser;
use App\Panels\Admin\Resources\Users\Pages\EditUser;
use App\Panels\Admin\Resources\Users\Pages\ListUsers;
use App\Panels\Admin\Resources\Users\Pages\ViewUser;
use PandaPanel\Core\PanelManager;
use Tests\Fixtures\Panel\TitledEditUser;
use Tests\Fixtures\Panel\TitledListUsers;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));
});

/**
 * @return array{title: string, heading: string, subheading: string|null}
 */
function headingsOf(object $page, mixed ...$arguments): array
{
    $props = $page->render(request(), ...$arguments)
        ->toResponse(request())
        ->original
        ->getData()['page']['props'];

    return [
        'title' => $props['page']['title'],
        'heading' => $props['page']['heading'],
        'subheading' => $props['page']['subheading'],
    ];
}

/*
 * Defaults
 */

it('defaults a list page to the resource plural label', function (): void {
    expect(headingsOf(new ListUsers))->toBe([
        'title' => 'Users',
        'heading' => 'Users',
        'subheading' => null,
    ]);
});

it('defaults a create page to the resource label', function (): void {
    expect(headingsOf(new CreateUser))->toBe([
        'title' => 'New User',
        'heading' => 'New User',
        'subheading' => null,
    ]);
});

it('defaults a view page to the record title over the resource label', function (): void {
    $record = User::factory()->create(['name' => 'Ada Lovelace']);

    expect(headingsOf(new ViewUser, (string) $record->getKey()))->toBe([
        'title' => 'Ada Lovelace',
        'heading' => 'Ada Lovelace',
        'subheading' => 'User',
    ]);
});

it('heads an edit page with the record and titles the tab with the verb', function (): void {
    $record = User::factory()->create(['name' => 'Ada Lovelace']);

    expect(headingsOf(new EditUser, (string) $record->getKey()))->toBe([
        'title' => 'Edit Ada Lovelace',
        'heading' => 'Ada Lovelace',
        'subheading' => 'Edit User',
    ]);
});

/*
 * Overrides
 */

it('takes the title and subheading a page declares', function (): void {
    expect(headingsOf(new TitledListUsers))->toBe([
        'title' => 'Team directory',
        // Declared nowhere, so it follows the title rather than the label.
        'heading' => 'Team directory',
        'subheading' => 'Everyone with an account.',
    ]);
});

it('lets a page override the subheading with the record', function (): void {
    $record = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    expect(headingsOf(new TitledEditUser, (string) $record->getKey()))->toBe([
        // Untouched, so still the edit page's own default.
        'title' => 'Edit Ada Lovelace',
        'heading' => 'Account',
        'subheading' => 'Editing ada@example.com',
    ]);
});

it('resolves headings without a record', function (): void {
    $page = new TitledEditUser;

    expect($page->getSubheading())->toBeNull()
        ->and($page->getHeading())->toBe('Account')
        ->and((new EditUser)->getTitle())->toBe('Edit User');
});
