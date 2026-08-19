<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\App;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Http\Middleware\SetPanelLocale;
use PandaPanel\Support\Format;
use PandaPanel\Tables\Columns\DateColumn;
use PandaPanel\Tables\Columns\DateTimeColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Widgets\Support\Stat;

/**
 * Letting a reader disagree with `app.locale`, and writing numbers their way.
 *
 * Two halves that only look separate. A panel that switched language and kept
 * `1,234.56` would be half-translated in the one place a half-translated
 * interface is not merely awkward: a number misread without anybody noticing.
 */
beforeEach(function (): void {
    config()->set('panda-panel.locales', [
        'en' => 'English',
        'id' => 'Bahasa Indonesia',
    ]);

    $this->actingAs(User::factory()->admin()->create());
});

/**
 * The admin panel, by id rather than through the `panel()` helper — that one
 * answers for the *current* request, and half of these tests never make one.
 */
function adminPanel(): Panel
{
    return app(PanelManager::class)->get('admin');
}

it('offers nothing to switch between until an application says so', function (): void {
    config()->set('panda-panel.locales', []);

    expect(adminPanel()->getLocales())->toBe([])
        ->and(adminPanel()->hasLocaleSwitcher())->toBeFalse();

    expect($this->get('/admin')->viewData('page')['props']['locales'])->toBeNull();
});

it('treats one locale as none', function (): void {
    config()->set('panda-panel.locales', ['en' => 'English']);

    expect(adminPanel()->hasLocaleSwitcher())->toBeFalse();
    expect($this->get('/admin')->viewData('page')['props']['locales'])->toBeNull();
});

it('discards a config entry that is not a code and a name', function (): void {
    // `['en', 'id']` is the shape somebody writes first, and a switcher whose
    // labels were `0` and `1` is worse than one that is simply absent.
    config()->set('panda-panel.locales', ['en', 'id']);

    expect(adminPanel()->getLocales())->toBe([]);
});

it('shares the languages on offer and where to post a choice', function (): void {
    $locales = $this->get('/admin')->viewData('page')['props']['locales'];

    expect($locales['current'])->toBe('en')
        ->and($locales['url'])->toBe('/admin/locale')
        ->and($locales['available'])->toBe([
            ['code' => 'en', 'name' => 'English', 'current' => true],
            ['code' => 'id', 'name' => 'Bahasa Indonesia', 'current' => false],
        ]);
});

it('renders the next request in the language that was chosen', function (): void {
    $this->post('/admin/locale', ['locale' => 'id'])->assertRedirect();

    expect(session(SetPanelLocale::SESSION_KEY))->toBe('id');

    $props = $this->get('/admin')->viewData('page')['props'];

    expect($props['locale'])->toBe('id')
        ->and($props['translations']['shell']['notifications'])->toBe('Notifikasi');
});

it('refuses a language this panel does not offer', function (): void {
    // `app()->setLocale()` accepts any string, so an unchecked one would let
    // a request write a directory traversal into the session for the
    // translator to try to load.
    $this->post('/admin/locale', ['locale' => '../../../etc'])->assertStatus(422);

    expect(session(SetPanelLocale::SESSION_KEY))->toBeNull();
});

it('ignores a stored locale a narrower panel does not offer', function (): void {
    // Two panels may offer different languages. The choice is kept rather
    // than cleared, so passing through the narrower one does not lose it for
    // the panel that did offer it.
    session([SetPanelLocale::SESSION_KEY => 'id']);

    config()->set('panda-panel.locales', ['en' => 'English', 'fr' => 'Français']);

    expect($this->get('/admin')->viewData('page')['props']['locale'])->toBe('en')
        ->and(session(SetPanelLocale::SESSION_KEY))->toBe('id');
});

it('lets a panel narrow what the config offers', function (): void {
    adminPanel()->locales(['en' => 'English']);

    expect(adminPanel()->getLocales())->toBe(['en' => 'English'])
        ->and(adminPanel()->hasLocaleSwitcher())->toBeFalse();
});

it('groups a number the way the locale writes it', function (): void {
    App::setLocale('en');

    expect(Format::number(1234567.891, 2))->toBe('1,234,567.89')
        ->and(Format::trimmedNumber(1234.5))->toBe('1,234.5')
        ->and(Format::trimmedNumber(1234.0))->toBe('1,234');

    App::setLocale('id');

    expect(Format::number(1234567.891, 2))->toBe('1.234.567,89')
        ->and(Format::trimmedNumber(1234.5))->toBe('1.234,5')
        // Trimming matches the locale's own decimal separator: doing it with
        // a literal dot would eat the thousands separator and leave "1".
        ->and(Format::trimmedNumber(1234.0))->toBe('1.234');
});

it('formats every figure the panel shows through the same table', function (): void {
    App::setLocale('id');

    $column = NumberColumn::make('total')->decimals(2);
    $record = new User;
    $record->setAttribute('total', 1234.5);

    expect($column->toCell($record)['display'])->toBe('1.234,50');

    expect(Stat::make('Orders', 1234567)->display())->toBe('1.234.567');
});

it('writes a date the way the locale writes it', function (): void {
    $record = new User;
    $record->setAttribute('created_at', now()->setDate(2026, 1, 5)->setTime(14, 30));

    App::setLocale('en');

    expect(DateColumn::make('created_at')->toCell($record)['display'])->toBe('Jan 5, 2026')
        ->and(DateTimeColumn::make('created_at')->toCell($record)['display'])->toBe('Jan 5, 2026 14:30');

    App::setLocale('id');

    expect(DateColumn::make('created_at')->toCell($record)['display'])->toBe('5 Jan 2026')
        ->and(DateTimeColumn::make('created_at')->toCell($record)['display'])->toBe('5 Jan 2026 14:30');
});

it('leaves a format the caller asked for alone', function (): void {
    $record = new User;
    $record->setAttribute('created_at', now()->setDate(2026, 1, 5));

    App::setLocale('id');

    expect(DateColumn::make('created_at')->format('Y-m-d')->toCell($record)['display'])
        ->toBe('2026-01-05');
});

it('falls back to English formatting for a locale that ships no table', function (): void {
    App::setLocale('fr');

    expect(Format::number(1234.5, 1))->toBe('1,234.5')
        ->and(Format::date())->toBe('M j, Y');
});
