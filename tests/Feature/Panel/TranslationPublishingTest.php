<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use PandaPanel\PandaPanelServiceProvider;
use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\PublishedAssets;

/*
|--------------------------------------------------------------------------
| Publishing the strings, and getting the next release's back
|--------------------------------------------------------------------------
|
| An application publishes the panel's translations for one reason: to reword
| a sentence the package chose. From that moment its copy is what the panel
| reads, and the copy is frozen at the release it came from — every
| improvement the package makes to that file afterwards stops there, with
| nothing to say so.
|
| That is the same problem the frontend has, so it is answered the same way:
| a published translation joins `.panel-assets.json` and is compared three
| ways. These tests are about the seam between the two halves — that nothing
| is tracked until it is published, that everything is tracked once it is, and
| that the two states a package update may act on are the two where this
| application demonstrably has no opinion about the file.
|
| ## Where they land, and why these tests move `lang_path()`
|
| They publish into `lang/{locale}` — `lang/en/tables.php`, beside the
| application's own strings — rather than into `lang/vendor/panda-panel`.
| Laravel looks for a namespaced override only in the second, so
| `PandaPanel\Translation\PanelTranslationLoader` is what makes the first
| resolve.
|
| Which means the destination is now the application's plain `lang/`, and in
| this repository — which is its own test application — that *is* the
| package's own `lang/`. Source and destination would be one directory, and a
| publish would be a copy of a file onto itself. So these tests point
| `lang_path()` at a scratch directory under `build/`, which is the only shape
| that models a real application: a package in `vendor/`, and a `lang/` of its
| own somewhere else entirely.
|
*/

beforeEach(function (): void {
    $this->published = dirname(__DIR__, 3).'/build/testbench/lang';

    File::deleteDirectory($this->published);
    File::ensureDirectoryExists($this->published);

    // A real application's `lang/` is not inside the package. This repository's
    // is, so it is moved for the length of these tests — see the note above.
    $this->app->useLangPath($this->published);

    // The translator caches per group, and a group read before the path moved
    // would be remembered for the rest of the test.
    Lang::setLoaded([]);

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->published);

    if (File::exists(AssetManifest::path())) {
        File::delete(AssetManifest::path());
    }
});

/**
 * What `vendor:publish --tag=panda-panel-translations` does, without the
 * console: copy the package's `lang/` into the application.
 */
function publishTranslations(): void
{
    foreach (PublishedAssets::translations() as $source => $destination) {
        File::copyDirectory($source, $destination);
    }
}

function packageLangPath(string $relative = ''): string
{
    return rtrim(dirname(__DIR__, 3).'/lang/'.$relative, '/');
}

/**
 * How many files the package ships across every locale it has.
 *
 * Counted rather than written down, so a locale added in a later release does
 * not silently make this assertion weaker than it reads.
 */
function shippedTranslationCount(): int
{
    return array_sum(array_map(
        static fn (string $directory): int => count(File::files($directory)),
        File::directories(packageLangPath()),
    ));
}

/*
 * The tag
 */

it('publishes its translations into lang/{locale}, under a tag of their own', function (): void {
    $paths = ServiceProvider::pathsToPublish(
        PandaPanelServiceProvider::class,
        'panda-panel-translations',
    );

    // The tag carries the package's `lang/` and nothing else. Where it lands
    // is asserted against the map rather than against this, because
    // `publishes()` runs at boot and froze whatever `lang_path()` was then —
    // which in this repository, before these tests move it, is the package's
    // own directory.
    expect(array_keys($paths))->toBe([packageLangPath('')]);

    // The whole point of the change: `lang/en` and `lang/id`, beside the
    // application's own strings, rather than three directories down under
    // `lang/vendor/panda-panel`.
    expect(PublishedAssets::translations())->toBe([packageLangPath('') => lang_path()]);
});

it('goes back to Laravel’s own convention when the config says so', function (): void {
    // The escape hatch, for a project that would rather keep the panel's
    // strings in a directory of their own. It needs no loader — Laravel reads
    // `lang/vendor/{namespace}` for a namespaced override by itself.
    config()->set('panda-panel.translations.publish_to_lang_root', false);

    expect(PublishedAssets::translations())
        ->toBe([packageLangPath('') => lang_path('vendor/panda-panel')]);
});

it('publishes them with the umbrella tag too, and never with the assets tag', function (): void {
    // The umbrella is what a scripted install reaches for. The assets tag is
    // the frontend and only the frontend: a project that wanted the strings
    // in its repository said so, and one that did not should not find them
    // there after publishing components.
    expect(ServiceProvider::pathsToPublish(PandaPanelServiceProvider::class, 'panda-panel'))
        ->toHaveKey(packageLangPath(''));

    expect(ServiceProvider::pathsToPublish(PandaPanelServiceProvider::class, 'panda-panel-assets'))
        ->not->toHaveKey(packageLangPath(''));
});

/*
 * Tracked only once published
 */

it('tracks no translation until the application publishes one', function (): void {
    // An application reading the package's own strings cannot fall behind
    // them, so there is nothing to report and reporting it would be noise in
    // a list of three hundred.
    //
    // A `lang/` directory is no longer the signal it was: `lang:publish`
    // creates one, and so does a project that wrote a single sentence of its
    // own. This one exists and is empty.
    expect(File::isDirectory(lang_path()))->toBeTrue()
        ->and(PublishedAssets::translationFiles())->toBe([]);

    foreach (array_keys(PublishedAssets::files()) as $destination) {
        expect($destination)->not->toStartWith(lang_path().'/');
    }
});

it('is not fooled by the application’s own strings living there', function (): void {
    // `lang/en/messages.php` is not one of ours, and a project that has
    // written one has not adopted anything.
    File::ensureDirectoryExists(lang_path('en'));
    File::put(lang_path('en/messages.php'), "<?php\n\nreturn ['hello' => 'Hello'];\n");

    expect(PublishedAssets::translationFiles())->toBe([]);
});

it('tracks every locale it ships once one of its files is there', function (): void {
    publishTranslations();

    $tracked = PublishedAssets::translationFiles();

    expect($tracked)->toHaveKey(lang_path('en/actions.php'))
        ->and($tracked)->toHaveKey(lang_path('id/actions.php'))
        ->and($tracked[lang_path('en/actions.php')])->toBe(packageLangPath('en/actions.php'))
        ->and($tracked)->toHaveCount(shippedTranslationCount());
});

/*
 * The three-way comparison, on strings rather than components
 */

it('reports a published translation the package has moved past as out of date', function (): void {
    publishTranslations();

    // Published, never touched here, and the package has since improved the
    // sentence. The one case an upgrade can act on with no risk at all.
    File::put(lang_path('en/actions.php'), "<?php\n\nreturn ['delete' => ['label' => 'Delete']];\n");

    // Recorded *after* the edit, which is what models a copy published from
    // an older release: on disk and in the manifest agree, and only the
    // package has moved.
    AssetManifest::write();

    expect(AssetManifest::compare()[PublishedAssets::relative(lang_path('en/actions.php'))]['status'])
        ->toBe(AssetManifest::STALE);
});

it('writes an out-of-date translation on --update, and says no build is needed', function (): void {
    publishTranslations();

    File::put(lang_path('id/tables.php'), "<?php\n\nreturn ['empty' => 'Kosong'];\n");

    AssetManifest::write();

    $this->artisan('panel:assets --update')
        ->expectsOutputToContain('Wrote 1 file(s).')
        // Translations are PHP. Telling somebody to rebuild the frontend
        // after a run that touched none of it is advice that teaches them to
        // skim past it on the run that does need one.
        ->doesntExpectOutputToContain('npm run build')
        ->assertSuccessful();

    expect(File::get(lang_path('id/tables.php')))
        ->toBe(File::get(packageLangPath('id/tables.php')));
});

it('leaves a reworded translation alone and takes it only with --force', function (): void {
    publishTranslations();

    AssetManifest::write();

    $reworded = "<?php\n\nreturn ['records' => ['singular' => 'Row']];\n";

    File::put(lang_path('en/tables.php'), $reworded);

    $key = PublishedAssets::relative(lang_path('en/tables.php'));

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::MODIFIED);

    $this->artisan('panel:assets --update')->assertSuccessful();

    // The whole point of publishing was to change this sentence. An upgrade
    // that quietly changes it back is an upgrade that undid the work.
    expect(File::get(lang_path('en/tables.php')))->toBe($reworded);

    $this->artisan('panel:assets --force')->assertSuccessful();

    expect(File::get(lang_path('en/tables.php')))
        ->toBe(File::get(packageLangPath('en/tables.php')));
});

it('writes a locale added in a later release into a directory it already owns', function (): void {
    publishTranslations();

    // A locale the application does not have yet reads as `new` rather than
    // as somebody's deletion, because the manifest never recorded it.
    File::deleteDirectory(lang_path('id'));

    AssetManifest::write();

    expect(AssetManifest::compare()[PublishedAssets::relative(lang_path('id/forms.php'))]['status'])
        ->toBe(AssetManifest::NEW);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get(lang_path('id/forms.php')))
        ->toBe(File::get(packageLangPath('id/forms.php')));
});

/*
 * Recording what was published
 */

it('records the published translations in the manifest', function (): void {
    publishTranslations();

    AssetManifest::write();

    expect(AssetManifest::read())
        ->toHaveKey(PublishedAssets::relative(lang_path('en/actions.php')))
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
        ->toHaveKey(PublishedAssets::relative(lang_path('en/actions.php')));
});

it('protects a rewording made after the publish was recorded', function (): void {
    publishTranslations();

    $this->artisan('panel:assets --update')->assertSuccessful();

    $reworded = "<?php\n\nreturn ['reordered' => 'Sequence saved.'];\n";

    File::put(lang_path('en/tables.php'), $reworded);

    $key = PublishedAssets::relative(lang_path('en/tables.php'));

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::MODIFIED);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get(lang_path('en/tables.php')))->toBe($reworded);
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

    File::put(lang_path('en/tables.php'), "<?php\n\nreturn ['reordered' => 'Sequence saved.'];\n");

    $key = PublishedAssets::relative(lang_path('en/tables.php'));

    expect(AssetManifest::compare()[$key]['status'])->toBe(AssetManifest::NEW);

    $this->artisan('panel:assets --update')->assertSuccessful();

    expect(File::get(lang_path('en/tables.php')))
        ->toBe(File::get(packageLangPath('en/tables.php')));
});

/*
 * What publishing does to what the panel reads
 */

it('reads the published sentence in place of the package one', function (): void {
    publishTranslations();

    File::put(lang_path('en/tables.php'), "<?php\n\nreturn ['empty_state' => ['heading' => 'Nothing here yet']];\n");

    Lang::setLoaded([]);

    expect(__('panda-panel::tables.empty_state.heading'))->toBe('Nothing here yet')
        // Key by key, not file by file: a published copy missing a key added
        // in a later release still gets that key from the package. The
        // framework does this for `lang/vendor/{namespace}` by itself, and
        // `PanelTranslationLoader` is what carries it over to `lang/{locale}`.
        ->and(__('panda-panel::tables.search.placeholder'))->toBe('Search...');
});

it('reads a locale from lang/{locale} without touching the other one', function (): void {
    publishTranslations();

    File::put(lang_path('id/tables.php'), "<?php\n\nreturn ['empty_state' => ['heading' => 'Belum ada apa-apa']];\n");

    Lang::setLoaded([]);

    /** @var array<string, mixed> $english */
    $english = require packageLangPath('en/tables.php');

    expect(__('panda-panel::tables.empty_state.heading', [], 'id'))->toBe('Belum ada apa-apa')
        ->and(__('panda-panel::tables.empty_state.heading', [], 'en'))
        ->toBe($english['empty_state']['heading']);
});

it('leaves every lookup that is not the panel’s alone', function (): void {
    // The flat layout means `lang/en/tables.php` may well be the
    // application's own file too. The merge is one-directional and namespaced:
    // a plain `__('tables.…')` is handed straight to the wrapped loader and
    // comes back exactly as it would have without this package installed.
    File::ensureDirectoryExists(lang_path('en'));
    File::put(lang_path('en/greetings.php'), "<?php\n\nreturn ['hello' => 'Hello'];\n");

    Lang::setLoaded([]);

    expect(__('greetings.hello'))->toBe('Hello')
        ->and(__('panda-panel::greetings.hello'))->toBe('panda-panel::greetings.hello');
});
