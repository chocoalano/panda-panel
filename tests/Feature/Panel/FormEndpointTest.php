<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Panel\Forms\FormFixturePanel;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\ProjectPolicy;

beforeEach(function (): void {
    FormFixturePanel::boot();
    FormFixturePanel::reset();

    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, string>  $query
 */
function formHostUrl(string $name, array $query = []): string
{
    return route('panel.'.FormFixturePanel::ID.'.'.$name, $query, absolute: false);
}

/*
 * Uploads
 */

it('stores a file where the field says, not where the request asks', function (): void {
    Storage::fake('public');

    $response = $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
            // Ignored. The disk and the directory come from the field.
            'disk' => 'local',
            'directory' => '../../etc',
        ],
    );

    $path = $response->json('path');

    expect($response->status())->toBe(200)
        ->and($path)->toStartWith('attachments/')
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('refuses to store a file for a field that does not take one', function (): void {
    Storage::fake('public');

    $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'name',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(400);
});

it('refuses a field the schema never declared', function (): void {
    Storage::fake('public');

    $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'nothing_like_this',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(404);
});

it('applies the field\'s own limits to the real file', function (): void {
    Storage::fake('public');

    // The field accepts PNG only, whatever the name says.
    $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'attachment',
            'file' => UploadedFile::fake()->create('notes.pdf', 4, 'application/pdf'),
        ],
    )->assertStatus(302);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses an upload from someone who may not write', function (): void {
    Storage::fake('public');

    ProjectPolicy::$creatable = false;
    ProjectPolicy::$listable = false;

    $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertForbidden();
});

/*
 * Which permission an upload needs
 *
 * The context in the URL — create, edit and which record, a relation and its
 * operation, an action — is what decides the ability asked. Being able to
 * read the resource is never one of the answers: an upload puts a file on a
 * disk, and looking at a list is not permission to do that.
 */

it('refuses an upload from a reader who may not create', function (): void {
    Storage::fake('public');

    // Listable but not creatable: the shape of a read-only user, and the one
    // an `canCreate() || canViewAny()` gate would have let through.
    ProjectPolicy::$creatable = false;
    ProjectPolicy::$listable = true;

    $this->post(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertForbidden();

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('asks the record\'s own policy for an upload on an edit form', function (): void {
    Storage::fake('public');

    $project = Project::query()->create(['name' => 'Apollo']);

    // No `create` ability at all — an edit form must not borrow it.
    ProjectPolicy::$creatable = false;

    $response = $this->post(
        formHostUrl('uploads', [
            'resource' => 'form-fixtures',
            'page' => 'edit',
            'record' => (string) $project->getKey(),
        ]),
        [
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    );

    expect($response->status())->toBe(200)
        ->and(Storage::disk('public')->exists((string) $response->json('path')))->toBeTrue();
});

it('refuses an edit-form upload that names no record', function (): void {
    Storage::fake('public');

    // Without a record there is nothing to ask `update` about, so there is
    // no question this request could be answered yes to.
    $this->postJson(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'edit']),
        [
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(422);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses an edit-form upload for a record outside the resource', function (): void {
    Storage::fake('public');

    $this->postJson(
        formHostUrl('uploads', [
            'resource' => 'form-fixtures',
            'page' => 'edit',
            'record' => '999999',
        ]),
        [
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(404);
});

it('refuses a page it does not recognise rather than defaulting to create', function (): void {
    Storage::fake('public');

    // The old behaviour was "edit, or else create". Anything unrecognised
    // then became the one form that needs no record — which is the check
    // being dodged.
    $this->postJson(
        formHostUrl('uploads', ['resource' => 'form-fixtures', 'page' => 'anything']),
        [
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(422);
});

it('reads the resource from the URL and not from the form body', function (): void {
    Storage::fake('public');

    // A form whose values happen to include `resource` must not be able to
    // point the upload somewhere else.
    $this->postJson(
        formHostUrl('uploads', ['page' => 'create']),
        [
            'resource' => 'form-fixtures',
            'field' => 'attachment',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    )->assertStatus(422);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

/*
 * Rebuilding the form
 */

it('answers with the schema as it now stands', function (): void {
    $response = $this->postJson(
        formHostUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => ['name' => 'Apollo'], 'changed' => 'kind'],
    );

    $names = array_column($response->json('form.schema'), 'name');

    expect($response->status())->toBe(200)
        ->and($names)->toContain('name')
        // The values typed so far come back on the fields that hold them.
        ->and($response->json('form.schema.0.value'))->toBe('Apollo');
});

it('writes nothing when a live field changes', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    $this->postJson(
        formHostUrl('form-state', [
            'resource' => 'form-fixtures',
            'page' => 'edit',
            'record' => (string) $project->getKey(),
        ]),
        ['state' => ['name' => 'Renamed'], 'changed' => 'kind'],
    )->assertOk();

    // Asking what a form looks like is not a submit.
    expect($project->fresh()?->getAttribute('name'))->toBe('Apollo');
});

it('discards a key the schema never declared', function (): void {
    $response = $this->postJson(
        formHostUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        [
            'state' => ['name' => 'Apollo', 'injected' => 'nope'],
            'changed' => 'kind',
        ],
    );

    $names = array_column($response->json('form.schema'), 'name');

    expect($names)->not->toContain('injected');
});

it('refuses to describe a form to someone who may not open it', function (): void {
    ProjectPolicy::$creatable = false;

    $this->postJson(
        formHostUrl('form-state', ['resource' => 'form-fixtures', 'page' => 'create']),
        ['state' => [], 'changed' => 'kind'],
    )->assertForbidden();
});

it('answers 404 for a record that is not there', function (): void {
    $this->postJson(
        formHostUrl('form-state', [
            'resource' => 'form-fixtures',
            'page' => 'edit',
            'record' => '999999',
        ]),
        ['state' => [], 'changed' => 'kind'],
    )->assertNotFound();
});
