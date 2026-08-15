<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\Exports\UserExporter;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Enums\SpreadsheetFormat;
use PandaPanel\Actions\Exports\ExportRun;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;
use Tests\Fixtures\Panel\Forms\FormFixtureResource;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;

beforeEach(function (): void {
    FormFixturePanel::boot();
    FormFixturePanel::reset();

    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $query
 */
function formHostRoute(string $name, array $query = []): string
{
    return route('panel.'.FormFixturePanel::ID.'.'.$name, $query, absolute: false);
}

/*
 * Describing an action's form
 */

it('refuses to describe an action the resource never declared', function (): void {
    $this->getJson(formHostRoute('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'invented',
        'scope' => 'table',
    ]))->assertNotFound();
});

it('refuses a scope that is not one', function (): void {
    $this->getJson(formHostRoute('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'create',
        'scope' => 'anything',
    ]))->assertStatus(422);
});

it('refuses to run an action form for an action that has none', function (): void {
    // `deleteAll` is a plain table action on the fixture, with no schema.
    $this->getJson(formHostRoute('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'deleteAll',
        'scope' => 'table',
    ]))->assertStatus(400);
});

it('describes the dialog and where to submit it', function (): void {
    $response = $this->getJson(formHostRoute('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
    ]));

    $response->assertOk();

    expect($response->json('title'))->toBe('Rename everything')
        ->and($response->json('submitLabel'))->toBe('Rename')
        ->and($response->json('context.scope'))->toBe('table')
        ->and($response->json('modal.width'))->toBe('lg')
        // The submit URL is built by the server, so the browser never
        // assembles a panel URL.
        ->and($response->json('submitUrl'))->toContain('/actions/form');
});

it('refuses to describe a form to somebody who may not run the action', function (): void {
    ProjectPolicy::$creatable = false;

    $this->getJson(formHostRoute('actions.form', [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
    ]))->assertForbidden();
});

/*
 * Submitting it
 */

it('validates the submitted data against the action\'s own schema', function (): void {
    $this->post(formHostRoute('actions.submit'), [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
        // `name` is required by the action's schema.
    ])->assertSessionHasErrors('name');
});

it('runs the action with what the form submitted', function (): void {
    Project::query()->create(['name' => 'Apollo']);

    $this->post(formHostRoute('actions.submit'), [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
        'name' => 'Renamed',
    ])->assertRedirect();

    expect(Project::query()->pluck('name')->all())->toBe(['Renamed']);
});

it('discards a key the action\'s schema never declared', function (): void {
    Project::query()->create(['name' => 'Apollo']);

    $this->post(formHostRoute('actions.submit'), [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
        'name' => 'Renamed',
        'is_admin' => true,
    ])->assertRedirect();

    // The handler never sees anything the schema did not declare, exactly as
    // on a resource form.
    expect(FormFixtureResource::$lastData)->toBe(['name' => 'Renamed']);
});

it('refuses to submit for somebody who may not run the action', function (): void {
    ProjectPolicy::$creatable = false;

    $this->post(formHostRoute('actions.submit'), [
        'resource' => 'form-fixtures',
        'action' => 'rename',
        'scope' => 'table',
        'name' => 'Renamed',
    ])->assertForbidden();
});

/*
 * Downloads
 */

it('hands back an export filed under the user asking for it', function (): void {
    Storage::fake('local');

    User::factory()->create();

    $key = $this->admin->getKey();

    $result = ExportRun::write(
        UserExporter::class,
        User::query(),
        ['name'],
        SpreadsheetFormat::Csv,
        $key,
    );

    $this->get(formHostRoute('export-file', [
        'file' => $result['file'],
        'exporter' => UserExporter::class,
    ]))->assertOk();
});

it('cannot be pointed at another user\'s export', function (): void {
    Storage::fake('local');

    User::factory()->create();

    // Written for somebody else. The endpoint builds the directory from
    // whoever is asking, so this file is simply not reachable.
    $result = ExportRun::write(
        UserExporter::class,
        User::query(),
        ['name'],
        SpreadsheetFormat::Csv,
        999999,
    );

    $this->get(formHostRoute('export-file', [
        'file' => $result['file'],
        'exporter' => UserExporter::class,
    ]))->assertNotFound();
});

it('refuses a file name that is a path', function (): void {
    Storage::fake('local');

    $this->get(formHostRoute('export-file', [
        'file' => '..%2F..%2Fsecrets.env',
        'exporter' => UserExporter::class,
    ]))->assertNotFound();
});

it('refuses an exporter that is not one', function (): void {
    Storage::fake('local');

    $this->get(formHostRoute('export-file', [
        'file' => 'users.csv',
        'exporter' => User::class,
    ]))->assertNotFound();
});
