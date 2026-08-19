<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Exports\ExportColumn;
use PandaPanel\Actions\Imports\ImportColumn;
use PandaPanel\Core\Panel;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\Support\Block;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Support\Label;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\Group;
use PandaPanel\Tables\Tab;

/**
 * Labels the panel derives from the application's own names.
 *
 * `Str::headline('created_at')` is "Created At" in every locale, which made
 * the package's translations only half a translation: a panel in Indonesian
 * still had English column headers, and the only fix was `->label()` on every
 * column of every table.
 *
 * The derivation now asks the application first, through `PandaPanel\Support\Label`.
 * These tests assert both halves of that — that a name the application named
 * is used, and that a name it did not name still headlines exactly as before.
 *
 * The fixture is loaded through `FileLoader::addPath()` rather than written to
 * `lang/`, because this suite's application base path *is* the package: a
 * `lang/en/panel.php` there would ship inside the Composer archive as though
 * it were one of the package's own files.
 */
beforeEach(function (): void {
    Lang::getLoader()->addPath(dirname(__DIR__, 2).'/Fixtures/lang');

    // The loader caches per group, and a group read before the path was added
    // would be remembered empty for the rest of the request.
    Lang::setLoaded([]);
});

it('uses the application word for a field it named', function (): void {
    App::setLocale('id');

    expect(TextColumn::make('created_at')->getLabel())->toBe('Dibuat pada')
        ->and(TextInput::make('name')->getLabel())->toBe('Nama lengkap')
        ->and(SelectFilter::make('created_at')->getLabel())->toBe('Dibuat pada')
        ->and(ImportColumn::make('created_at')->getLabel())->toBe('Dibuat pada')
        ->and(Group::make('created_at')->getLabel())->toBe('Dibuat pada');
});

it('headlines a name the application did not translate', function (): void {
    App::setLocale('id');

    expect(TextColumn::make('email_address')->getLabel())->toBe('Email Address');
});

it('keeps every locale on its own answer', function (): void {
    App::setLocale('en');
    expect(TextColumn::make('name')->getLabel())->toBe('Full name');

    App::setLocale('id');
    expect(TextColumn::make('name')->getLabel())->toBe('Nama lengkap');

    // English never named `created_at`, so it still headlines while
    // Indonesian answers from the file.
    App::setLocale('en');
    expect(TextColumn::make('created_at')->getLabel())->toBe('Created At');
});

it('lets an explicit label win over the file', function (): void {
    App::setLocale('id');

    expect(TextColumn::make('created_at')->label('Tanggal')->getLabel())->toBe('Tanggal');
});

it('reads a dotted relation attribute as a flat key', function (): void {
    App::setLocale('id');

    // `Arr::get()` checks the literal key before splitting on dots, so an
    // application writes `'user.name' => …` without nesting.
    expect(TextEntry::make('user.name')->getLabel())->toBe('Nama pengguna')
        ->and(ExportColumn::make('user.name')->getLabel())->toBe('Nama pengguna');

    // And one it did not name still reads as prose rather than as a path.
    expect(TextEntry::make('user.email')->getLabel())->toBe('User Email');
});

it('translates the names that are not fields', function (): void {
    App::setLocale('id');

    expect(Action::make('impersonate')->getLabel())->toBe('Masuk sebagai')
        ->and(Tab::make('active')->getLabel())->toBe('Aktif')
        ->and(Block::make('paragraph')->getLabel())->toBe('Paragraf')
        ->and(Panel::make('admin')->getName())->toBe('Administrasi');
});

it('names a resource and its plural separately', function (): void {
    App::setLocale('id');

    // The singular is translated and the plural is not, so the plural is the
    // singular unchanged. `Str::plural()` knows English and would have
    // produced "Penggunas".
    expect(UserResource::defaultLabel())->toBe('Pengguna')
        ->and(UserResource::defaultPluralLabel())->toBe('Pengguna');

    App::setLocale('en');

    expect(UserResource::defaultLabel())->toBe('User')
        ->and(UserResource::defaultPluralLabel())->toBe('Users');
});

it('reads the file the config names', function (): void {
    App::setLocale('id');

    config()->set('panda-panel.labels.file', 'something-else');

    expect(Label::lookup('fields', 'created_at'))->toBeNull()
        ->and(TextColumn::make('created_at')->getLabel())->toBe('Created At');
});

it('costs an application that has no such file nothing but the fallback', function (): void {
    config()->set('panda-panel.labels.file', 'no-such-file');

    expect(Label::lookup('fields', 'name'))->toBeNull()
        ->and(Label::resolve('fields', 'name', fn (): string => 'fallback'))->toBe('fallback');
});

it('renders a real table in the reader\'s language', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    App::setLocale('id');

    $columns = collect($this->get('/admin/users')->viewData('page')['props']['table']['columns'])
        ->keyBy('name');

    // Derived, so it follows the application's file.
    expect($columns['name']['label'])->toBe('Nama lengkap');

    // Declared with ->label(), so both are untouched — the escape hatch for
    // the column that needs a word the file does not give it.
    expect($columns['created_at']['label'])->toBe('Registered')
        ->and($columns['passkeys.name']['label'])->toBe('Passkey names');

    // Named by neither, so it still headlines.
    expect($columns['email']['label'])->toBe('Email');
});
