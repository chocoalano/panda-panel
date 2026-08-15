<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\FrontendRequirements;
use PandaPanel\Support\Installer\PanelRegistrar;

/*
|--------------------------------------------------------------------------
| An install that stops one step short of working is an install that failed
|--------------------------------------------------------------------------
|
| Three of the steps `panel:install` runs used to be printed instructions:
| register the panel, install the npm dependencies, make a user. Each one is
| the difference between a scaffold and a screen that answers, and each one is
| tested here for the thing that actually goes wrong — a config that has been
| reshaped, a dependency list read from the wrong file, a user model behind a
| guard nobody named.
|
*/

/**
 * The `panels` array as this package ships it.
 */
function shippedConfig(): string
{
    return <<<'PHP'
        <?php

        return [

            /*
            | Panels, in order.
            */

            'panels' => [
                // App\Panels\Admin\AdminPanelProvider::class,
            ],

            'register_routes' => true,

        ];
        PHP;
}

function configFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/panda-panel-config-'.bin2hex(random_bytes(6)).'.php';

    File::put($path, $contents);

    return $path;
}

/*
 * Registering the panel
 */

it('adds the panel to the config it just published', function (): void {
    $path = configFixture(shippedConfig());

    $result = PanelRegistrar::register('App\Panels\Support\SupportPanelProvider', $path);

    $written = File::get($path);

    expect($result)->toBe(PanelRegistrar::REGISTERED)
        ->and($written)->toContain('\App\Panels\Support\SupportPanelProvider::class,')
        // The comments are what the file is mostly made of. Re-emitting the
        // parsed array would have thrown every one of them away.
        ->and($written)->toContain('| Panels, in order.')
        ->and($written)->toContain("'register_routes' => true");

    File::delete($path);
});

it('leaves a panel that is already registered alone', function (): void {
    $path = configFixture(str_replace(
        '// App\Panels\Admin\AdminPanelProvider::class,',
        '\App\Panels\Admin\AdminPanelProvider::class,',
        shippedConfig(),
    ));

    $before = File::get($path);

    expect(PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider', $path))
        ->toBe(PanelRegistrar::ALREADY_PRESENT)
        ->and(File::get($path))->toBe($before);

    File::delete($path);
});

it('uncomments the placeholder rather than adding a second line', function (): void {
    // The line this package ships commented out names the same class the
    // default `panel:install` scaffolds. Reading it as "already registered"
    // would report success and leave the panel unreachable — the exact
    // failure this step exists to prevent — and adding a second line would
    // leave the file with both.
    $path = configFixture(shippedConfig());

    PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider', $path);

    $written = File::get($path);

    expect($written)->not->toContain('// App\Panels\Admin\AdminPanelProvider::class,')
        ->and(mb_substr_count($written, 'AdminPanelProvider::class'))->toBe(1);

    File::delete($path);
});

it('registers a second panel after the first', function (): void {
    $path = configFixture(shippedConfig());

    PanelRegistrar::register('App\Panels\Staff\StaffPanelProvider', $path);
    PanelRegistrar::register('App\Panels\Client\ClientPanelProvider', $path);

    $written = File::get($path);

    // Order decides which panel a user lands in when the request does not
    // name one, so a new panel goes last.
    expect(mb_strpos($written, 'StaffPanelProvider'))
        ->toBeLessThan(mb_strpos($written, 'ClientPanelProvider'));

    File::delete($path);
});

it('refuses to edit a config somebody has reshaped', function (): void {
    // A `panels` key built from a variable is a config being managed rather
    // than configured, and guessing where a line belongs in it is worse than
    // saying so.
    $path = configFixture("<?php\n\nreturn ['panels' => \$panels];\n");

    $before = File::get($path);

    expect(PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider', $path))
        ->toBe(PanelRegistrar::UNRECOGNISED)
        ->and(File::get($path))->toBe($before);

    File::delete($path);
});

it('says so when the config was never published', function (): void {
    expect(PanelRegistrar::register('App\Panels\Admin\AdminPanelProvider', '/nowhere/panda-panel.php'))
        ->toBe(PanelRegistrar::NO_CONFIG);
});

/*
 * Checking the frontend
 */

it('reads the npm dependency list from this package rather than restating it', function (): void {
    $packages = FrontendRequirements::npmPackages();

    // Whatever the list is, it is the one in `package.json` — the file the
    // toolchain installs from and the build proves. A second copy is a copy
    // that goes stale the first time a component imports something new.
    $declared = json_decode(File::get(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];

    expect($packages)->toHaveCount(count($declared))
        ->and($packages)->toContain('@inertiajs/vue3@'.$declared['@inertiajs/vue3'])
        ->and($packages)->toContain('reka-ui@'.$declared['reka-ui']);
});

it('counts a dependency the application already declares as present', function (): void {
    // The suite runs with this package as the application, and its own
    // `package.json` declares every one of them.
    expect(FrontendRequirements::missingNpmPackages())->toBe([]);
});

it('names the host modules an application has to provide', function (): void {
    $missing = FrontendRequirements::missingHostModules();

    // This package ships `resources/js` but not the eighteen the components
    // import from the application around them — which is exactly what an
    // install into a non-starter-kit project looks like.
    expect($missing)->toContain('@/routes/login')
        ->and($missing)->toContain('@/components/UserMenuContent')
        ->and($missing)->toContain('@/actions/App/Http/Controllers/Settings/ProfileController')
        // Shipped, so never reported.
        ->and($missing)->not->toContain('@/panel/palette');
});

/*
 * The first user
 */

it('creates a user through the guard\'s own model', function (): void {
    $this->artisan('panel:user', [
        '--name' => 'Ada',
        '--email' => 'ada@example.test',
        '--password' => 'correct-horse',
        '--panel' => 'admin',
    ])->assertSuccessful();

    $user = User::query()->where('email', 'ada@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Ada')
        // Created from the console by whoever owns the database, so not
        // walked into the verify-email wall on first sign-in.
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBe('correct-horse');
});

it('refuses a password too short to be one', function (): void {
    $this->artisan('panel:user', [
        '--name' => 'Ada',
        '--email' => 'ada@example.test',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('says so when no guard by that name exists', function (): void {
    $this->artisan('panel:user', [
        '--name' => 'Ada',
        '--email' => 'ada@example.test',
        '--password' => 'correct-horse',
        '--guard' => 'nothing-like-this',
    ])->assertFailed();
});
