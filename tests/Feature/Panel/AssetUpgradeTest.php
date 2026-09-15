<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\FrontendPaths;
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

it('calls a file new when the manifest has never heard of it and it is not on disk', function (): void {
    // `new` is about absence. A file that is on disk and differs is a
    // conflict instead — see the PP-42 block below, which is the bug this
    // test used to assert as the behaviour.
    [$map, $key] = scratchAsset($this->root, 'a brand new component', null);

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

it('expands every directory in the publish map, source and destination alike', function (): void {
    // A count rather than a spot check, because the way this breaks is
    // silently: a file list that quietly shrinks reports the files it stopped
    // listing as `no longer shipped` and everything else as current, which
    // reads exactly like a healthy tree.
    //
    // The trap is this repository. Source and destination are the same path
    // here, so a rule about a destination nested inside its source — which is
    // what the published translations are — matches every frontend file too
    // unless it insists on *strictly* nested.
    $expected = 0;

    foreach (PublishedAssets::map() as $source => $destination) {
        $expected += File::isDirectory($source) ? count(File::allFiles($source)) : 1;
    }

    expect($expected)->toBeGreaterThan(100)
        ->and(PublishedAssets::files())->toHaveCount($expected)
        ->and(PublishedAssets::files())->toHaveKey(FrontendPaths::panel('palette.ts'));
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

/*
|--------------------------------------------------------------------------
| What an update records (PP-41)
|--------------------------------------------------------------------------
|
| `compare()` was right from the start; `write()` was not. It hashed the
| application's copy for every file on disk, so an update recorded a locally
| modified file's *own* content as the ancestor. The file then read as `stale`
| on the next run — the package copy differs from an ancestor that is now the
| application's edit — and `stale` is the one state an update overwrites
| without asking. An edit survived exactly one release.
|
| The invariant these tests hold: the recorded ancestor is the hash of the
| **package** copy the application copy was last reconciled with. An update
| that did not reconcile a file does not move its ancestor.
|
*/

/**
 * One update cycle over a scratch map: write what is safe, then record.
 *
 * Mirrors what `panel:assets --update` does to a single file, without the
 * real publish map — the same reason `scratchAsset()` exists.
 *
 * @param  array<string, string>  $map
 */
function updateScratch(array $map, bool $force = false): void
{
    foreach (AssetManifest::compare($map) as $entry) {
        $status = $entry['status'];

        $writes = in_array($status, [AssetManifest::NEW, AssetManifest::STALE], true)
            || ($force && in_array($status, [AssetManifest::CONFLICT, AssetManifest::MODIFIED], true));

        if ($writes && $entry['source'] !== null) {
            File::copy($entry['source'], $entry['destination']);
        }
    }

    AssetManifest::write(AssetManifest::read(), $map);
}

it('keeps a locally modified file modified across an update', function (): void {
    // PP-41. The application edited it, the package did not move. An update
    // writes nothing — and must record nothing either, or the next run reads
    // the edit as the ancestor and calls the package's copy an upgrade.
    [$map, $key] = scratchAsset($this->root, 'original', 'ours');

    writeManifest([$key => hashOf('original')]);

    updateScratch($map);

    expect(AssetManifest::read()[$key])->toBe(hashOf('original'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED)
        ->and(File::get($key))->toBe('ours');
});

it('does not overwrite a locally modified file on the update after the one that recorded it', function (): void {
    // The failure PP-41 actually caused: not the wrong label, but the edit
    // being thrown away one release later. Two updates, and the second one
    // is where the old behaviour reached for the package's copy.
    [$map, $key] = scratchAsset($this->root, 'original', 'ours');

    writeManifest([$key => hashOf('original')]);

    updateScratch($map);
    updateScratch($map);

    expect(File::get($key))->toBe('ours')
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED);
});

it('keeps a conflict a conflict across an update', function (): void {
    // Both sides moved. An update must leave the ancestor where it is, or the
    // conflict reads as stale next time and is silently overwritten — which
    // is the same data loss, reached through the state that was supposed to
    // prevent it.
    [$map, $key] = scratchAsset($this->root, 'theirs', 'ours');

    writeManifest([$key => hashOf('original')]);

    updateScratch($map);

    expect(AssetManifest::read()[$key])->toBe(hashOf('original'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CONFLICT)
        ->and(File::get($key))->toBe('ours');
});

it('rebases a stale file onto the package copy it just wrote', function (): void {
    [$map, $key] = scratchAsset($this->root, 'version two', 'version one');

    writeManifest([$key => hashOf('version one')]);

    updateScratch($map);

    expect(File::get($key))->toBe('version two')
        ->and(AssetManifest::read()[$key])->toBe(hashOf('version two'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('records a new file against the package copy it wrote', function (): void {
    // Nothing on disk, so this really is new and the update really does write
    // it. A file that was already there would be a conflict — PP-42.
    [$map, $key] = scratchAsset($this->root, 'shipped', null);

    writeManifest([]);

    updateScratch($map);

    expect(File::get($key))->toBe('shipped')
        ->and(AssetManifest::read()[$key])->toBe(hashOf('shipped'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('leaves a current file exactly where it was', function (): void {
    [$map, $key] = scratchAsset($this->root, 'original', 'original');

    writeManifest([$key => hashOf('original')]);

    updateScratch($map);

    expect(AssetManifest::read()[$key])->toBe(hashOf('original'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('keeps a file the application deleted deleted rather than resurrecting it', function (): void {
    // Dropping the record turned `deleted` into `new`, and `new` is written.
    // A file removed on purpose came back on the next update.
    [$map, $key] = scratchAsset($this->root, 'shipped', null);

    writeManifest([$key => hashOf('shipped')]);

    updateScratch($map);

    expect(File::exists($key))->toBeFalse()
        ->and(AssetManifest::read())->toHaveKey($key)
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::DELETED);
});

it('keeps recording a file the package no longer ships', function (): void {
    [$map, $key] = scratchAsset($this->root, 'shipped', 'shipped');

    writeManifest([$key => hashOf('shipped'), 'resources/js/panel/Gone.vue' => hashOf('gone')]);

    updateScratch($map);

    expect(AssetManifest::read())->toHaveKey('resources/js/panel/Gone.vue')
        ->and(AssetManifest::compare($map)['resources/js/panel/Gone.vue']['status'])
        ->toBe(AssetManifest::REMOVED_UPSTREAM);
});

it('rebases a force-overwritten conflict onto the package copy', function (): void {
    [$map, $key] = scratchAsset($this->root, 'theirs', 'ours');

    writeManifest([$key => hashOf('original')]);

    updateScratch($map, force: true);

    expect(File::get($key))->toBe('theirs')
        ->and(AssetManifest::read()[$key])->toBe(hashOf('theirs'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

/*
 * Reconciliation
 */

it('records a merged file against the current package copy without touching its contents', function (): void {
    // The only way out of a conflict that keeps both sides: a person merges,
    // then says so. The ancestor moves to the package copy they merged
    // against; the content stays theirs. The file reads as `modified` after,
    // which is the state that means "ours, and up to date with upstream".
    [$map, $key] = scratchAsset($this->root, 'theirs', 'ours');

    writeManifest([$key => hashOf('original')]);

    File::put($key, 'ours and theirs');

    expect(AssetManifest::reconcile([$key], $map))->toBe([$key]);

    expect(File::get($key))->toBe('ours and theirs')
        ->and(AssetManifest::read()[$key])->toBe(hashOf('theirs'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED);
});

it('keeps a reconciled file modified through the next update', function (): void {
    [$map, $key] = scratchAsset($this->root, 'theirs', 'ours and theirs');

    writeManifest([$key => hashOf('original')]);

    AssetManifest::reconcile([$key], $map);

    updateScratch($map);

    expect(File::get($key))->toBe('ours and theirs')
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED);
});

it('reconciles only the files it was named and reports the rest', function (): void {
    // Bounded on purpose. "Mark every conflict resolved" is a way to lose the
    // review this state exists to demand.
    [$map, $key] = scratchAsset($this->root, 'theirs', 'ours');

    writeManifest([$key => hashOf('original')]);

    expect(AssetManifest::reconcile(['resources/js/panel/NotOurs.vue'], $map))->toBe([]);

    expect(AssetManifest::read()[$key])->toBe(hashOf('original'))
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CONFLICT);
});

/*
 * The reconciliation workflow, end to end
 */

it('clears a conflict for the file it was named and leaves the rest alone', function (): void {
    // Bounded on purpose: two files in conflict, one path given, one cleared.
    writeManifest([
        'resources/js/panel/palette.ts' => 'neither-side-has-this-hash',
        'resources/js/panel/contract.ts' => 'neither-side-has-this-hash',
    ]);

    $before = File::get(base_path('resources/js/panel/palette.ts'));

    $this->artisan('panel:assets --reconciled=resources/js/panel/palette.ts')
        ->expectsOutputToContain('reconciled')
        ->assertSuccessful();

    $report = AssetManifest::compare();

    expect(File::get(base_path('resources/js/panel/palette.ts')))->toBe($before)
        ->and($report['resources/js/panel/palette.ts']['status'])->not->toBe(AssetManifest::CONFLICT)
        ->and($report['resources/js/panel/contract.ts']['status'])->toBe(AssetManifest::CONFLICT);
});

it('refuses a path it does not publish and records nothing', function (): void {
    writeManifest(['resources/js/panel/palette.ts' => 'neither-side-has-this-hash']);

    $this->artisan('panel:assets --reconciled=resources/js/app.ts')
        ->expectsOutputToContain('nothing to reconcile')
        ->assertSuccessful();

    expect(AssetManifest::read()['resources/js/panel/palette.ts'])
        ->toBe('neither-side-has-this-hash');
});

/*
|--------------------------------------------------------------------------
| A file on disk this manifest has never heard of (PP-42)
|--------------------------------------------------------------------------
|
| `new` used to mean two different things: a file the application does not
| have, and a file it does have that we have no record of. The first is safe to
| write. The second is not, and writing it is how an application lost its own
| work.
|
| It reached a real project through the flat translation layout. The package
| publishes `lang/{locale}/*.php` beside the application's own strings, and an
| application that already had `lang/id/formats.php` — its own file, its own
| keys, never published by anybody — had it classified `new` and overwritten on
| the first `panel:assets --update` after the upgrade. `formats.currency_prefix`
| went with it, and every amount on every screen rendered as the name of the key
| that used to hold it.
|
| The same shape reaches a partial override. `lang/vendor/panda-panel/{locale}/
| frontend.php` holding only the keys an application added is a complete and
| supported file — Laravel merges it recursively over ours — and replacing it
| with our own copy deletes every key it existed to add.
|
| So an unrecorded file that is on disk and differs from ours is a `conflict`:
| nobody can tell from here whether it is ours, theirs, or both, and guessing
| is the one thing this class exists not to do. `--reconciled` keeps theirs,
| `--force` takes ours, and until one of those is asked for, nothing is written.
|
*/

it('refuses to overwrite an unrecorded file that is already on disk', function (): void {
    // The application's own file, at a path this package also ships to.
    [$map, $key] = scratchAsset($this->root, 'the package copy', 'the application own file');

    writeManifest([]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CONFLICT);

    updateScratch($map);

    expect(File::get($key))->toBe('the application own file');
});

it('still calls a file new when nothing is on disk to lose', function (): void {
    // The case `new` was always right for, and the one an update may write.
    [$map, $key] = scratchAsset($this->root, 'a component this release adds', null);

    writeManifest([]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::NEW);

    updateScratch($map);

    expect(File::get($key))->toBe('a component this release adds');
});

it('keeps an unrecorded file that already matches current', function (): void {
    // Published by `vendor:publish` and never edited. Identical to ours, so
    // there is nothing to decide and nothing to warn about.
    [$map, $key] = scratchAsset($this->root, 'identical', 'identical');

    writeManifest([]);

    expect(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});

it('lets a person keep their own unrecorded file by reconciling it', function (): void {
    [$map, $key] = scratchAsset($this->root, 'the package copy', 'the application own file');

    writeManifest([]);

    AssetManifest::reconcile([$key], $map);

    updateScratch($map);

    expect(File::get($key))->toBe('the application own file')
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::MODIFIED);
});

it('lets a person take the package copy of an unrecorded file with force', function (): void {
    [$map, $key] = scratchAsset($this->root, 'the package copy', 'the application own file');

    writeManifest([]);

    updateScratch($map, force: true);

    expect(File::get($key))->toBe('the package copy')
        ->and(AssetManifest::compare($map)[$key]['status'])->toBe(AssetManifest::CURRENT);
});
