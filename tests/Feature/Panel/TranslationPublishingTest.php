<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use PandaPanel\PandaPanelServiceProvider;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\PublishedAssets;

/*
|--------------------------------------------------------------------------
| Publishing the strings, and getting the next release's back
|--------------------------------------------------------------------------
|
| An application publishes `lang/vendor/panda-panel` for one reason: to reword
| a sentence the package chose. From that moment Laravel reads its copy first,
| and the copy is frozen at the release it came from — every improvement the
| package makes to that file afterwards stops at the vendor directory, with
| nothing to say so.
|
| That is the same problem the frontend has, so it is answered the same way:
| a published translation joins `.panel-assets.json` and is compared three
| ways. These tests are about the seam between the two halves — that nothing
| is tracked until it is published, that everything is tracked once it is, and
| that the two states a package update may act on are the two where this
| application demonstrably has no opinion about the file.
|
| The fixture publishes for real, into `lang_path('vendor/panda-panel')`. In
| this repository that lands *inside* the package's own `lang/`, because the
| base path during a test run is the package — which is exactly the case
| `PublishedAssets::expand()` guards against and the one an application never
| sees.
|
*/

beforeEach(function (): void {
    $this->published = lang_path('vendor/panda-panel');

    File::deleteDirectory(lang_path('vendor'));

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

afterEach(function (): void {
    File::deleteDirectory(lang_path('vendor'));

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

/**
 * What `vendor:publish --tag=panda-panel-translations` does, without the
 * console: copy the package's `lang/` into the application.
 *
 * Locale by locale rather than in one `copyDirectory()`, because in this
 * repository the destination sits inside the source — copying the whole
 * directory would copy the copy, forever. An application publishes into
 * `lang/vendor/panda-panel` from `vendor/chocoalano/panel/lang` and never
 * meets this.
 */
function publishTranslations(): void
{
    foreach (PublishedAssets::translations() as $source => $destination) {
        foreach (File::directories($source) as $locale) {
            if (basename($locale) === 'vendor') {
                continue;
            }

            File::copyDirectory($locale, $destination.'/'.basename($locale));
        }
    }
}

function packageLangPath(string $relative = ''): string
{
    return rtrim(dirname(__DIR__, 3).'/lang/'.$relative, '/');
}

/**
 * How many files the package ships across every locale it has.
 *
 * Counted rather than written down, and `vendor` is skipped because in this
 * repository the application's published copies land inside the very
 * directory being counted.
 */
function shippedTranslationCount(): int
{
    $locales = array_filter(
        File::directories(packageLangPath()),
        static fn (string $directory): bool => basename($directory) !== 'vendor',
    );

    return array_sum(array_map(
        static fn (string $directory): int => count(File::files($directory)),
        $locales,
    ));
}

/*
 * The tag
 */

it('publishes its translations under a tag of their own', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        PandaPanelServiceProvider::class,
        'panda-panel-translations',
    );

    expect($paths)->toBe([packageLangPath('') => lang_path('vendor/panda-panel')]);
});

it('publishes them with the umbrella tag too, and never with the assets tag', function (): void {
    // The umbrella is what a scripted install reaches for. The assets tag is
    // the frontend and only the frontend: a project that wanted the strings
    // in its repository said so, and one that did not should not find them
    // there after publishing components.
    expect(ServiceProvider::pathsToPublish(PandaPanelServiceProvider::class, 'panda-panel'))
        ->toHaveKey(dirname(__DIR__, 3).'/lang');

    expect(ServiceProvider::pathsToPublish(PandaPanelServiceProvider::class, 'panda-panel-assets'))
        ->not->toHaveKey(dirname(__DIR__, 3).'/lang');
});

/*
 * Tracked only once published
 */

it('tracks no translation until the application publishes one', function (): void {
    // An application reading the package's own strings cannot fall behind
    // them, so there is nothing to report and reporting it would be noise in
    // a list of three hundred.
    $tracked = array_keys(PublishedAssets::files());

    expect(PublishedAssets::translationFiles())->toBe([]);

    foreach ($tracked as $destination) {
        expect($destination)->not->toStartWith(lang_path('vendor'));
    }
});

it('tracks every locale it ships once the directory exists', function (): void {
    publishTranslations();

    $tracked = PublishedAssets::translationFiles();

    expect($tracked)->toHaveKey($this->published.'/en/actions.php')
        ->and($tracked)->toHaveKey($this->published.'/id/actions.php')
        ->and($tracked[$this->published.'/en/actions.php'])->toBe(packageLangPath('en/actions.php'))
        ->and($tracked)->toHaveCount(shippedTranslationCount());
});

it('never maps a published translation back onto itself', function (): void {
    // This repository is its own application, so the destination sits inside
    // the source. Without the guard every run would enumerate the copies it
    // made last time and nest them one directory deeper.
    publishTranslations();

    foreach (PublishedAssets::translationFiles() as $destination => $source) {
        expect($source)->not->toStartWith($this->published)
            ->and($destination)->not->toContain('vendor/panda-panel/vendor');
    }
});

/*
 * The three-way comparison, on strings rather than components
 */

it('reports a published translation the package has moved past as out of date', function (): void {
    publishTranslations();

    // Published, never touched here, and the package has since improved the
    // sentence. The one case an upgrade can act on with no risk at all.
    File::put($this->published.'/en/actions.php', "<?php\n\nreturn ['delete' => ['label' => 'Delete']];\n");

    // Recorded *after* the edit, which is what models a copy published from
    // an older release: on disk and in the manifest agree, and only the
    // package has moved.
    AssetManifest::write();

    expect(AssetManifest::compare()[PublishedAssets::relative($this->published.'/en/actions.php')]['status'])
        ->toBe(AssetManifest::STALE);
});

it('writes an out-of-date translation on --update, and says no build is needed', function (): void {
    publishTranslations();

    File::put($this->published.'/id/tables.php', "<?php\n\nreturn ['empty' => 'Kosong'];\n");

    AssetManifest::write();

    $this->artisan('panel:assets --update')
        ->expectsOutputToContain('Wrote 1 file(s).')
        // Translations are PHP. Telling somebody to rebuild the frontend
        // after a run that touched none of it is advice that teaches them to
        // skim past it on the run that does need one.
        ->doesntExpectOutputToContain('npm run build')
        ->assertSuccessful();

    expect(File::get($this->published.'/id/tables.php'))
        ->toBe(File::get(packageLangPath('id/tables.php')));
});

it('leaves a reworded translation alone and takes it only with --force', function (): void {
    publishTranslations();

    AssetManifest::write();

    $reworded = "<?php\n\nreturn ['records' => ['singular' => 'Row']];\n";

    File::put($this->published.'/en/tables.php', $reworded);

    $key = PublishedAssets::relative($this->published.'/en/tables.php');

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::MODIFIED);

    $this->artisan('panel:assets --update')->assertSuccessful();

    // The whole point of publishing was to change this sentence. An upgrade
    // that quietly changes it back is an upgrade that undid the work.
    expect(File::get($this->published.'/en/tables.php'))->toBe($reworded);

    $this->artisan('panel:assets --force')->assertSuccessful();

    expect(File::get($this->published.'/en/tables.php'))
        ->toBe(File::get(packageLangPath('en/tables.php')));
});

it('writes a locale added in a later release into a directory it already owns', function (): void {
    publishTranslations();

    // A locale the application does not have yet reads as `new` rather than
    // as somebody's deletion, because the manifest never recorded it.
    File::deleteDirectory($this->published.'/id');

    AssetManifest::write();

    expect(AssetManifest::compare()[PublishedAssets::relative($this->published.'/id/forms.php')]['status'])
        ->toBe(AssetManifest::NEW);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get($this->published.'/id/forms.php'))
        ->toBe(File::get(packageLangPath('id/forms.php')));
});

/*
 * Recording what was published
 */

it('records the published translations in the manifest', function (): void {
    publishTranslations();

    AssetManifest::write();

    expect(AssetManifest::read())
        ->toHaveKey(PublishedAssets::relative($this->published.'/en/actions.php'))
        ->toHaveCount(count(PublishedAssets::files()));
});

it('records the state on --update even when there was nothing to write', function (): void {
    publishTranslations();

    // Recording is half of what an update is for, and a freshly published
    // tree has nothing to write: every file is identical to the package's.
    // Without this the manifest would first appear on the release that
    // happened to change something, and everything published before it would
    // have no ancestor.
    $this->artisan('panel:assets --update')
        ->expectsOutputToContain('Nothing to write.')
        ->assertSuccessful();

    expect(AssetManifest::exists())->toBeTrue()
        ->and(AssetManifest::read())
        ->toHaveKey(PublishedAssets::relative($this->published.'/en/actions.php'));
});

it('protects a rewording made after the publish was recorded', function (): void {
    publishTranslations();

    $this->artisan('panel:assets --update')->assertSuccessful();

    $reworded = "<?php\n\nreturn ['reordered' => 'Sequence saved.'];\n";

    File::put($this->published.'/en/tables.php', $reworded);

    $key = PublishedAssets::relative($this->published.'/en/tables.php');

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::MODIFIED);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get($this->published.'/en/tables.php'))->toBe($reworded);
});

it('cannot protect a rewording made before anything recorded the publish', function (): void {
    // The limitation, stated rather than papered over. `vendor:publish`
    // cannot write the manifest, so a file published and then edited with no
    // record in between has no ancestor — and "differs from the package's
    // copy" is equally true of a rewording and of a file a later release
    // added. It reads as `new`, and an update writes it.
    //
    // The answer is an order rather than a heuristic: publish, run
    // `panel:assets --update` to record what you got, then reword.
    publishTranslations();

    File::put($this->published.'/en/tables.php', "<?php\n\nreturn ['reordered' => 'Sequence saved.'];\n");

    $key = PublishedAssets::relative($this->published.'/en/tables.php');

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::NEW);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get($this->published.'/en/tables.php'))
        ->toBe(File::get(packageLangPath('en/tables.php')));
});

/*
 * What publishing does to what the panel reads
 */

it('reads the published sentence in place of the package one', function (): void {
    publishTranslations();

    File::put($this->published.'/en/tables.php', "<?php\n\nreturn ['empty_state' => ['heading' => 'Nothing here yet']];\n");

    app('translator')->setLoaded([]);

    expect(__('panda-panel::tables.empty_state.heading'))->toBe('Nothing here yet')
        // Key by key, not file by file: a published copy missing a key added
        // in a later release still gets that key from the package.
        ->and(__('panda-panel::tables.search.placeholder'))->toBe('Search...');
});
