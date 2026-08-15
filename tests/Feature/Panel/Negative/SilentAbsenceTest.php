<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use PandaPanel\Cache\DiscoveryFingerprint;
use PandaPanel\Cache\PanelManifest;
use PandaPanel\Core\PanelManager;
use PandaPanel\Core\PanelRegistry;
use PandaPanel\Discovery\PanelDiscoverer;
use PandaPanel\Support\MissingPolicyNotice;
use Tests\Fixtures\Panel\UnpolicedModel;

/*
 * "My resource is missing."
 *
 * Four different problems produce that one sentence: the resource is in
 * another panel, its policy says no, nobody wrote a policy at all, or the
 * cached manifest predates it. All four used to look identical — an absence,
 * with no route, no empty state and no error.
 *
 * These cover the two that are mistakes rather than decisions.
 */

beforeEach(function (): void {
    MissingPolicyNotice::forget();

    $this->actingAs(User::factory()->admin()->create());
});

/*
 * A policy nobody wrote
 */

it('says why a resource left the navigation when it has no policy', function (): void {
    Log::shouldReceive('debug')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'has no policy')
            && str_contains($message, 'make:policy')
            && str_contains($message, 'strictAuthorization'));

    MissingPolicyNotice::reportIfMissing('App\\Resources\\OrderResource', UnpolicedModel::class);
});

it('says it once, however many times the navigation is built', function (): void {
    Log::shouldReceive('debug')->once();

    MissingPolicyNotice::reportIfMissing('App\\Resources\\OrderResource', UnpolicedModel::class);
    MissingPolicyNotice::reportIfMissing('App\\Resources\\OrderResource', UnpolicedModel::class);
    MissingPolicyNotice::reportIfMissing('App\\Resources\\OrderResource', UnpolicedModel::class);
});

it('stays quiet when a policy exists and simply said no', function (): void {
    // A policy that considered the question is a decision, not a mistake.
    // `App\Models\User` has one in the example application.
    Log::shouldReceive('debug')->never();

    MissingPolicyNotice::reportIfMissing('App\\Resources\\UserResource', User::class);
})->skip(fn (): bool => Gate::getPolicyFor(User::class) === null,
    'The example application has no user policy to test against.');

it('suggests where the policy would live', function (): void {
    expect(MissingPolicyNotice::expectedPolicy('App\\Models\\Order'))
        ->toBe('App\\Policies\\OrderPolicy');
});

/*
 * A manifest that predates the resource
 */

it('notices that the classes on disk have moved since the manifest was written', function (): void {
    $panels = array_values(app(PanelRegistry::class)->all());

    $before = DiscoveryFingerprint::of($panels);

    expect(DiscoveryFingerprint::isStale($panels, $before))->toBeFalse();

    // The shape of the real mistake: a resource added after `panel:cache`.
    $added = base_path('examples/app/Panels/Admin/Resources/ZzTemporary.php');

    File::put($added, "<?php\n\n// Added after the manifest was written.\n");

    try {
        expect(DiscoveryFingerprint::isStale($panels, $before))->toBeTrue();
    } finally {
        File::delete($added);
    }
});

it('treats a removed file as a change too', function (): void {
    $panels = array_values(app(PanelRegistry::class)->all());

    $added = base_path('examples/app/Panels/Admin/Resources/ZzTemporary.php');
    File::put($added, "<?php\n");

    $withFile = DiscoveryFingerprint::of($panels);

    File::delete($added);

    expect(DiscoveryFingerprint::isStale($panels, $withFile))->toBeTrue();
});

it('says nothing when there is no fingerprint to compare against', function (): void {
    // A manifest written by an older version has none. Being unsure is not a
    // reason to tell somebody their cache is stale.
    $panels = array_values(app(PanelRegistry::class)->all());

    expect(DiscoveryFingerprint::isStale($panels, null))->toBeFalse()
        ->and(DiscoveryFingerprint::isStale($panels, ''))->toBeFalse();
});

it('warns at boot when the manifest no longer matches the disk', function (): void {
    $manifest = app(PanelManifest::class);

    $manifest->write(app(PanelRegistry::class));

    // Rewrite the file with a fingerprint that cannot match anything.
    $file = require PanelManifest::path();
    File::put(
        PanelManifest::path(),
        '<?php return '.var_export(['panels' => $file['panels'], 'fingerprint' => 'stale'], true).';',
    );

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'out of date')
            && str_contains($message, 'panel:clear'));

    // A fresh instance, so it reads the file rather than its own memory.
    (new PanelManifest(app(PanelDiscoverer::class)))
        ->warnIfStale(app(PanelRegistry::class));

    $manifest->clear();
});

it('reads a manifest written before fingerprints existed', function (): void {
    // The compatibility promise: an upgrade must not need a cache clear to
    // boot at all.
    File::put(
        PanelManifest::path(),
        '<?php return '.var_export([
            'admin' => ['resources' => [], 'pages' => [], 'widgets' => []],
        ], true).';',
    );

    $manifest = new PanelManifest(app(PanelDiscoverer::class));

    expect($manifest->for(app(PanelManager::class)->get('admin')))
        ->toBe(['resources' => [], 'pages' => [], 'widgets' => []]);

    $manifest->clear();
});
