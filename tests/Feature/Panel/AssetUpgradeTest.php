<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\PublishedAssets;

/*
|--------------------------------------------------------------------------
| Upgrading a frontend the application now owns
|--------------------------------------------------------------------------
|
| Publishing the frontend is what makes it debuggable and what makes the
| build-time component registries possible. The cost is that a package update
| cannot improve a file the application owns — and `vendor:publish` has only
| two settings, both wrong on an upgrade: without `--force` nothing updates,
| with `--force` everything is overwritten including deliberate edits.
|
| The fix is a third hash: what the file looked like when it was published.
| These tests are about the four combinations that produces, and especially
| the two where guessing would destroy somebody's work.
|
| The fixture is a scratch pair of directories rather than the real publish
| map, because this repository is its own test application: its "published"
| copies *are* the package's files, so on disk and in the package can never
| differ, and the two cases that matter most would be untestable.
|
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/panda-assets-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($this->root.'/package');
    File::ensureDirectoryExists($this->root.'/app');

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->root);

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

/**
 * One shipped file, with independent control of all three states.
 *
 * @param  string|null  $onDisk  null to model a file the application deleted
 * @return array{array<string, string>, string} the compare() map, and the report key
 */
function scratchAsset(string $root, string $inPackage, ?string $onDisk): array
{
    File::put($root.'/package/Component.vue', $inPackage);

    if ($onDisk !== null) {
        File::put($root.'/app/Component.vue', $onDisk);
    }

    return [[$root.'/app/Component.vue' => $root.'/package/Component.vue'], $root.'/app/Component.vue'];
}

/**
 * @param  array<string, string>  $files
 */
function writeManifest(array $files): void
{
    File::put(AssetManifest::path(), json_encode(['files' => $files]));
}

function hashOf(string $contents): string
{
    return hash('xxh128', str_replace("\r\n", "\n", $contents));
}

/*
 * The four combinations
 */

it('leaves a file alone when neither side changed', function (): void {
    [$map, $key] = scratchAsset($this->root, 'original', 'original');

    writeManifest([$key => hashOf('original')]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('marks a file stale when the package moved on and the application did not', function (): void {
    // Published, never touched here, changed upstream. The one case an
    // upgrade can act on with no risk at all.
    [$map, $key] = scratchAsset($this->root, 'version two', 'version one');

    writeManifest([$key => hashOf('version one')]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::STALE);
});

it('marks a file modified when the application edited it and the package did not', function (): void {
    // Edited here, nothing new upstream. Overwriting would throw away the
    // edit for no gain whatsoever.
    [$map, $key] = scratchAsset($this->root, 'original', 'original, with our change');

    writeManifest([$key => hashOf('original')]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED);
});

it('marks a file conflicted when both sides changed', function (): void {
    // Neither copy is safe to throw away, which is exactly why the two-way
    // comparison `vendor:publish` does cannot be trusted on an upgrade.
    [$map, $key] = scratchAsset($this->root, 'version two', 'version one, with our change');

    writeManifest([$key => hashOf('version one')]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CONFLICT);
});

/*
 * The edges
 */

it('calls a file new when the manifest has never heard of it and it differs', function (): void {
    [$map, $key] = scratchAsset($this->root, 'a brand new component', 'something else entirely');

    writeManifest([]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::NEW);
});

it('calls an unrecorded file current when it already matches', function (): void {
    // An application that published before this manifest existed. Reporting
    // 340 identical files as `new` would send it to overwrite files that are
    // already right.
    [$map, $key] = scratchAsset($this->root, 'identical', 'identical');

    writeManifest([]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('records a file the application deleted as deleted rather than missing', function (): void {
    [$map, $key] = scratchAsset($this->root, 'still shipped', null);

    writeManifest([$key => hashOf('still shipped')]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::DELETED);
});

it('normalises line endings so a CRLF checkout is not one long conflict', function (): void {
    [$map, $key] = scratchAsset($this->root, "one\ntwo\n", "one\r\ntwo\r\n");

    writeManifest([$key => hashOf("one\ntwo\n")]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('reports a file it no longer ships without deleting it', function (): void {
    [$map] = scratchAsset($this->root, 'shipped', 'shipped');

    writeManifest(['resources/js/panel/GoneInThisRelease.vue' => 'some-hash']);

    expect(AssetManifest::compare($map)['resources/js/panel/GoneInThisRelease.vue']['status'])
        ->toBe(AssetManifest::REMOVED_UPSTREAM);
});

it('survives a manifest that is not valid json', function (): void {
    File::put(AssetManifest::path(), '{ not json');

    // Treated as absent rather than fatal: the worst outcome is that files
    // read as new, which is the state an application that never published is
    // already in.
    expect(AssetManifest::read())->toBe([]);

    [$map, $key] = scratchAsset($this->root, 'same', 'same');

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

/*
 * The command
 */

it('reads every real shipped file as current in this repository', function (): void {
    // The suite's application is this package, so its copies are byte
    // identical to what is shipped. Anything else would mean the publish map
    // and the tree had drifted.
    $statuses = array_unique(array_column(AssetManifest::compare(), 'status'));

    expect($statuses)->toBe([AssetManifest::CURRENT]);
});

it('records a hash for every file it ships', function (): void {
    AssetManifest::write();

    expect(AssetManifest::read())->toHaveCount(count(PublishedAssets::files()));
});

it('reports without writing anything when asked nothing', function (): void {
    $this->artisan('panel:assets')->assertSuccessful();

    // A bare run is a question. Recording hashes as a side effect of asking
    // would make the next run's answer depend on having asked.
    expect(File::exists(AssetManifest::path()))->toBeFalse();
});

it('exits zero on a conflict, because a conflict is not a failure', function (): void {
    writeManifest(['resources/js/panel/palette.ts' => 'neither-side-has-this-hash']);

    // The command did its job and found something a person has to look at.
    // A non-zero exit would break a deploy over a file somebody edited on
    // purpose.
    $this->artisan('panel:assets')
        ->expectsOutputToContain('CONFLICT')
        ->assertSuccessful();
});
