<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use PandaPanel\Actions\DeleteAction;
use PandaPanel\Actions\Imports\Importer;
use PandaPanel\Core\PanelManager;
use PandaPanel\Integrations\Trigger;
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Tables\Filters\TrashedFilter;
use PandaPanel\Tables\TableSchema;

/**
 * The tests that keep a second locale honest.
 *
 * Two failure modes matter, and neither shows up in a screenshot. The first
 * is a key added to English and forgotten in Indonesian — the panel keeps
 * working and quietly serves one English sentence in the middle of an
 * Indonesian page. The second is a `__()` call naming a key no file defines,
 * which renders the key itself: `panda-panel::actions.delete.label` in place
 * of a button. Both are caught here by reading the files rather than the
 * screens, so a string added anywhere in `src` is covered without a test
 * being written for it.
 */

/**
 * @return list<string>
 */
function flattenKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $keys = array_merge(
            $keys,
            is_array($value) ? flattenKeys($value, "{$prefix}{$key}.") : ["{$prefix}{$key}"],
        );
    }

    sort($keys);

    return $keys;
}

function langPath(string $locale): string
{
    return dirname(__DIR__, 3).'/lang/'.$locale;
}

it('ships the same keys in every locale', function (): void {
    $english = File::files(langPath('en'));

    expect($english)->not->toBeEmpty();

    foreach ($english as $file) {
        $group = $file->getFilenameWithoutExtension();
        $indonesian = langPath('id').'/'.$file->getFilename();

        expect($indonesian)->toBeFile("lang/id/{$group}.php is missing");

        expect(flattenKeys(require $indonesian))
            ->toBe(flattenKeys(require $file->getPathname()), "lang/id/{$group}.php does not match lang/en/{$group}.php");
    }
});

it('leaves no key untranslated in either locale', function (): void {
    $sources = File::allFiles(dirname(__DIR__, 3).'/src');
    $referenced = [];

    foreach ($sources as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Only the literal keys. A key built from a variable — the HTTP
        // status notifications, the integration triggers — is covered by the
        // behavioural tests below instead, because a regular expression
        // cannot know what the variable holds.
        preg_match_all(
            "/(?:__|trans|trans_choice)\(\s*'panda-panel::([a-z0-9_.]+)'/i",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $key) {
            // A key ending in a dot is a prefix a `match` completes, not a
            // key. Its branches are covered behaviourally further down.
            if (str_ends_with($key, '.')) {
                continue;
            }

            $referenced[$key] = $file->getRelativePathname();
        }
    }

    expect($referenced)->not->toBeEmpty();

    foreach (['en', 'id'] as $locale) {
        App::setLocale($locale);

        foreach ($referenced as $key => $source) {
            expect(__("panda-panel::{$key}"))
                ->not->toBe("panda-panel::{$key}", "[{$locale}] {$source} asks for a key no translation file defines: {$key}");
        }
    }
});

it('speaks Indonesian when the application does', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    App::setLocale('id');

    // With a record, because the action authorizes against one and a null
    // record is refused before any label is ever built.
    $action = DeleteAction::make(UserResource::class)
        ->toArray(User::factory()->create());

    expect($action['label'])->toBe('Hapus')
        ->and($action['confirmation']['heading'])->toBe('Hapus data ini?')
        ->and($action['confirmation']['button'])->toBe('Hapus');

    expect(TableSchema::make()->toArray()['emptyState']['heading'])->toBe('Data tidak ditemukan');
    expect(TrashedFilter::make('trashed')->getLabel())->toBe('Data terhapus');
    expect(ProfileSettings::title())->toBe('Profil');
    expect(Trigger::AfterCreate->label())->toBe('Setelah dibuat');
});

it('falls back to English when nothing says otherwise', function (): void {
    expect(App::getLocale())->toBe('en');

    expect(TableSchema::make()->toArray()['emptyState']['heading'])->toBe('No records found');
    expect(ProfileSettings::title())->toBe('Profile');
    expect(Trigger::AfterCreate->label())->toBe('After create');
});

it('translates the panel error notifications the frontend receives', function (): void {
    App::setLocale('id');

    $notifications = app(PanelManager::class)
        ->all()[0]
        ->getErrorNotifications();

    expect($notifications[403]['title'])->toBe('Tidak diizinkan')
        ->and($notifications[404]['body'])->toBe('Data tersebut sudah tidak ada.')
        ->and($notifications[503]['title'])->toBe('Sedang tidak tersedia');
});

it('counts rows in the language the reader is using', function (): void {
    App::setLocale('id');

    expect(Importer::completedMessage(40, 0))->toBe('40 baris berhasil diimpor.');

    App::setLocale('en');

    expect(Importer::completedMessage(40, 0))->toBe('Imported 40 rows.');
});

it('defines every key the Vue components ask for', function (): void {
    // The frontend's half of "no key untranslated". The components read
    // `t('tables.rows_per_page')` out of one shared prop, so a key added to a
    // component and forgotten in `lang/*/frontend.php` renders a humanized
    // guess at the English — right in English, wrong everywhere else, and
    // visible on screen rather than in any log.
    $components = File::allFiles(dirname(__DIR__, 3).'/resources/js');
    $referenced = [];

    foreach ($components as $file) {
        if (! in_array($file->getExtension(), ['vue', 'ts'], true)) {
            continue;
        }

        // Literal keys only. A key assembled from a variable — the editor
        // toolbars, the layout toggle — is held in a constant in the same
        // file and is covered by the reverse check below.
        preg_match_all(
            "/\\bt\\(\\s*'([a-z0-9_]+\\.[a-z0-9_.]+)'/i",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $referenced[$key] = $file->getRelativePathname();
        }
    }

    expect($referenced)->not->toBeEmpty();

    foreach (['en', 'id'] as $locale) {
        App::setLocale($locale);

        foreach ($referenced as $key => $source) {
            expect(Lang::get("panda-panel::frontend.{$key}"))
                ->toBeString("[{$locale}] {$source} asks for a frontend key that is not a string: {$key}")
                ->not->toBe("panda-panel::frontend.{$key}", "[{$locale}] {$source} asks for a frontend key nothing defines: {$key}");
        }
    }
});

it('defines every key a component holds in a constant', function (): void {
    // The keys the regex above cannot see: a component that stores
    // `'tables.layout_table'` on an options array and calls `t(option.key)`.
    // Anything that *looks* like a frontend key is checked, which is why the
    // groups are named rather than matched loosely — a CSS class or an icon
    // name must not be mistaken for one.
    $groups = implode('|', array_keys(require dirname(__DIR__, 3).'/lang/en/frontend.php'));
    $components = File::allFiles(dirname(__DIR__, 3).'/resources/js');
    $referenced = [];

    foreach ($components as $file) {
        if (! in_array($file->getExtension(), ['vue', 'ts'], true)) {
            continue;
        }

        preg_match_all(
            "/'(({$groups})\\.[a-z0-9_]+)'/",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $referenced[$key] = $file->getRelativePathname();
        }
    }

    expect($referenced)->not->toBeEmpty();

    foreach (['en', 'id'] as $locale) {
        App::setLocale($locale);

        foreach ($referenced as $key => $source) {
            expect(Lang::get("panda-panel::frontend.{$key}"))
                ->not->toBe("panda-panel::frontend.{$key}", "[{$locale}] {$source} names a frontend key nothing defines: {$key}");
        }
    }
});

it('puts the dictionary and the locale on every panel page', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    App::setLocale('id');

    $props = $this->get('/admin')->viewData('page')['props'];

    expect($props['locale'])->toBe('id')
        ->and($props['translations']['tables']['rows_per_page'])->toBe('Baris per halaman')
        ->and($props['translations']['ui']['close'])->toBe('Tutup');

    // Only the `frontend` group crosses the wire. The abort messages, the
    // built-in action labels and the mail lines are read in PHP and would be
    // a hundred sentences in the page source of every screen.
    //
    // `actions` exists on both sides and means different things: in
    // `frontend` it is the chrome around an action dialog, in the PHP group
    // it is every built-in action's label and confirmation. Asserting on a
    // key only the PHP one has is what tells them apart.
    expect($props['translations'])->not->toHaveKey('errors')
        ->and($props['translations']['actions'])->not->toHaveKey('delete');
});
