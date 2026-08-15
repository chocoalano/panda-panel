<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Actions\Imports\ImportRun;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Tenancy\Tenancy;
use Tests\Fixtures\Panel\Relations\BadTenantResource;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

/*
 * Declarations that point at something which cannot answer.
 *
 * Each of these used to fail somewhere else: inside Eloquent's internals, on
 * every row of a ten thousand row file, or not at all.
 */

beforeEach(function (): void {
    RelationSchema::create();
});

/*
 * Tenancy
 */

it('refuses a tenant relationship that is not a relationship', function (): void {
    // `method_exists` passes — the failure was inside `whereHas`, as "Call to
    // a member function getRelated() on null", which names neither the
    // resource nor the property that pointed at it.
    $panel = Panel::make('tenanted')
        ->path('tenanted')
        ->tenant(Project::class, static fn () => null);

    app(PanelManager::class)->register($panel);
    app(PanelManager::class)->setCurrentPanel($panel);

    $tenant = Project::query()->create(['name' => 'Tenant']);

    expect(fn () => Tenancy::for($tenant, static fn () => BadTenantResource::query()))
        ->toThrow(
            PanelRegistrationException::class,
            'exists but does not return an Eloquent relationship',
        );
});

it('names the resource, the model and the method it cannot use', function (): void {
    $panel = Panel::make('tenanted2')
        ->path('tenanted2')
        ->tenant(Project::class, static fn () => null);

    app(PanelManager::class)->register($panel);
    app(PanelManager::class)->setCurrentPanel($panel);

    $tenant = Project::query()->create(['name' => 'Tenant']);

    try {
        Tenancy::for($tenant, static fn () => BadTenantResource::query());
    } catch (PanelRegistrationException $exception) {
        expect($exception->getMessage())
            ->toContain('BadTenantResource')
            ->toContain('Project::getTable()')
            ->toContain('belongsTo');
    }
});

/*
 * Import
 */

it('says which required column the file has no heading for', function (): void {
    $missing = ImportRun::missingColumnsMessage(['email'], ['name', 'created at']);

    // Not "The email field is required", ten thousand times. The file simply
    // does not have that column.
    expect($missing)
        ->toContain('no column for [email]')
        ->toContain('Its headings are: name, created at');
});

it('reads as a sentence for more than one missing column', function (): void {
    expect(ImportRun::missingColumnsMessage(['email', 'name'], ['a']))
        ->toContain('[email], [name]')
        ->toContain('they are required');
});

it('says so plainly when the file has no headings at all', function (): void {
    expect(ImportRun::missingColumnsMessage(['email'], []))
        ->toContain('Its headings are: (none)');
});

/*
 * The build-time component registries
 */

it('tells a developer when a widget component is not in the registry', function (): void {
    $registry = File::get(base_path('resources/js/panel/widgets/registry.ts'));

    // The fallback looks exactly like a component that rendered nothing, and
    // the three reasons for it — a typo, a file outside the globbed directory,
    // a build that was not re-run — are indistinguishable from the screen.
    expect($registry)
        ->toContain('is not in the build-time registry')
        ->toContain('resources/js/pages/Panels/{Panel}/Widgets/')
        ->toContain('import.meta.env.DEV')
        ->toContain('missing.has(name)');
});

it('tells a developer when a form component is not in the registry', function (): void {
    expect(File::get(base_path('resources/js/panel/forms/registry.ts')))
        ->toContain('is not in the build-time registry')
        ->toContain('import.meta.env.DEV');
});

/*
 * Already covered, and left alone
 */

it('already refuses an operation the relation shape cannot perform', function (): void {
    // Asserted here so the next audit does not "fix" it twice: attach and
    // associate are derived from the relation in the UI and checked again at
    // the endpoint, which answers 403. See `PanelRelationController`.
    $source = File::get(base_path('src/Http/Controllers/PanelRelationController.php'));

    expect($source)
        ->toContain('RelationOperation::Attach => $manager::isManyToMany($owner)')
        ->toContain('RelationOperation::Associate => $manager::isOneToMany($owner)');
});
