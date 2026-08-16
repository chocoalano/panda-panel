<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

use PandaPanel\Support\Installer\AssetManifest;
use PandaPanel\Support\Installer\FrontendRequirements;
use PandaPanel\Support\Installer\PanelRegistrar;

/**
 * The one command a fresh install runs.
 *
 * Everything it does is available separately — `vendor:publish` by tag,
 * `make:panel`, `panel:user` — so nothing here is a step that cannot be taken
 * by hand or repeated. It exists because the order matters and because an
 * install that stops one step short of working is the same as an install that
 * failed.
 *
 * Six steps, and each one either does the thing or says exactly what is left:
 *
 * 1. Publish the config, the frontend, and — if asked — the migrations.
 * 2. Scaffold the first panel.
 * 3. Register it in `config/panda-panel.php`, which is what makes its URL
 *    answer.
 * 4. Check the frontend: npm dependencies, the host seam, Vite, Inertia.
 * 5. Offer to create an account that can sign in.
 * 6. Report anything still outstanding, in words, once.
 *
 * The guest redirect used to be a seventh step and a manual one. It is now
 * registered by the service provider — see
 * `PandaPanelServiceProvider::registerGuestRedirect()`, and the
 * `register_guest_redirect` config key for the case where an application
 * would rather own it.
 */
final class InstallPanelCommand extends Command
{
    protected $signature = 'panel:install
        {--panel=Admin : The name of the first panel to scaffold}
        {--no-panel : Publish and configure without scaffolding a panel}
        {--no-user : Skip the offer to create a signing-in account}
        {--force : Overwrite files that already exist}';

    protected $description = 'Publish the panel config and frontend, scaffold a first panel, and register it';

    /**
     * What could not be done for the application, collected as it goes and
     * printed once at the end.
     *
     * Reported together rather than as they happen: an install that
     * interleaves five successes with three warnings is an install whose
     * warnings are read as noise.
     *
     * @var list<string>
     */
    private array $outstanding = [];

    public function handle(): int
    {
        $this->components->info('Installing Panda Panel.');

        $this->publish('panda-panel-config');
        $this->publish('panda-panel-assets');

        // Records what was just published, which is what lets a later
        // `panel:assets` tell a file this application edited from one that
        // has simply fallen behind. Without it, every future upgrade is a
        // choice between overwriting your work and updating nothing.
        AssetManifest::write(AssetManifest::read());

        if ($this->shouldPublishMigrations()) {
            $this->publish('panda-panel-migrations');
        }

        $panel = $this->scaffoldPanel();

        if ($panel !== null) {
            $this->registerPanel($panel);
        }

        $this->reportHomeRedirect();
        $this->checkFrontend();
        $this->offerUser($panel);

        $this->reportOutstanding();

        return self::SUCCESS;
    }

    /**
     * @return string|null the panel name, or null when none was scaffolded
     */
    private function scaffoldPanel(): ?string
    {
        if ($this->option('no-panel')) {
            return null;
        }

        $panel = (string) $this->option('panel');

        $this->call('make:panel', [
            'name' => $panel,
            '--force' => (bool) $this->option('force'),
        ]);

        return $panel;
    }

    /**
     * Adds the new panel to the `panels` list.
     *
     * Without this the install finishes, the provider exists, and the panel's
     * URL 404s — the single most common "it did not work" after an install,
     * and the one thing standing between a scaffold and a working screen.
     */
    private function registerPanel(string $panel): void
    {
        $provider = "App\\Panels\\{$panel}\\{$panel}PanelProvider";

        $result = PanelRegistrar::register($provider);

        match ($result) {
            PanelRegistrar::REGISTERED => $this->components->info(
                "Registered {$provider} in config/panda-panel.php.",
            ),
            PanelRegistrar::ALREADY_PRESENT => $this->components->info(
                'That panel is already registered in config/panda-panel.php.',
            ),
            default => $this->outstanding[] = sprintf(
                "Add %s::class to 'panels' in config/panda-panel.php. (%s)",
                $provider,
                $result === PanelRegistrar::NO_CONFIG
                    ? 'The config has not been published'
                    : 'The config has been reshaped, so it was left alone',
            ),
        };
    }

    /**
     * Says out loud that the starter kit's dashboard now leads into the panel.
     *
     * It is the one thing installing this package changes about a screen the
     * application already had, and a redirect nobody was told about is a bug
     * report. Printed rather than asked, because the answer lives in config
     * and is one line to reverse.
     */
    private function reportHomeRedirect(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('panda-panel.home_redirect', []);

        if (($config['enabled'] ?? false) !== true) {
            return;
        }

        /** @var list<string> $paths */
        $paths = array_values(array_filter(
            (array) ($config['paths'] ?? []),
            static fn (mixed $path): bool => is_string($path) && $path !== '',
        ));

        if ($paths === []) {
            return;
        }

        $this->components->info(sprintf(
            'Signed-in visitors to /%s now land in the panel. '
                .'Set home_redirect.enabled to false in config/panda-panel.php to keep your own.',
            implode(', /', $paths),
        ));
    }

    /**
     * Everything the published components need from the application.
     *
     * Each of these fails at `npm run build` with a message about a module
     * specifier, which is a true error about the wrong thing. Naming them here
     * is the difference between "install the dependencies" and knowing which.
     */
    private function checkFrontend(): void
    {
        foreach (FrontendRequirements::missingInertia() as $missing) {
            $this->outstanding[] = "This application is missing {$missing}. "
                .'Every panel screen is an Inertia response and will 500 without it — '
                .'a Laravel Vue starter kit has both already.';
        }

        if (! FrontendRequirements::hasVite()) {
            $this->outstanding[] = 'This application has no vite.config — the published '
                .'components are Vue and have to be built by something.';
        }

        // Before the list itself, because an empty list means two different
        // things and only one of them is good news.
        if (! FrontendRequirements::hasNpmManifest()) {
            $this->outstanding[] = sprintf(
                "This package's own package.json is not installed at %s, so the npm dependencies "
                    ."the published components import could not be checked at all.\n\n"
                    .'     This is a packaging fault rather than something wrong with your application: '
                    .'the file is export-ignored out of the Composer archive. Report it, and in the '
                    .'meantime install the frontend dependencies listed in the package repository by hand.',
                FrontendRequirements::npmManifestPath(),
            );
        }

        $packages = FrontendRequirements::missingNpmPackages();

        if ($packages !== []) {
            $this->outstanding[] = 'Install the npm dependencies the components import, then rebuild:'
                ."\n\n     npm install ".implode(" \\\n       ", $packages)
                ."\n     npm run build";
        }

        foreach (FrontendRequirements::layoutOverrides() as $override) {
            $this->outstanding[] = sprintf(
                "%s line %d overwrites the layout every panel page declares:\n\n       %s\n\n"
                    ."     Make it fall back instead, so a page that names its own layout keeps it:\n\n"
                    ."       page.default.layout ??= AppLayout\n\n"
                    .'     Left as it is, every panel screen renders inside your application shell — '
                    .'with your sidebar, not the panel navigation, and no error to say so.',
                $override['file'],
                $override['line'],
                $override['code'],
            );
        }

        $modules = FrontendRequirements::missingHostModules();

        if ($modules === []) {
            return;
        }

        // Deliberately a list rather than a count: which ones are missing says
        // what to do. All of `@/routes/*` and `@/actions/*` means Wayfinder
        // has not run; a handful of components means this is not a starter
        // kit application.
        $this->outstanding[] = 'The published components import these modules, which belong to '
            ."your application and are not there yet:\n\n       "
            .implode("\n       ", $modules)
            ."\n\n     `@/routes/*` and `@/actions/*` are generated — run "
            .'`php artisan wayfinder:generate`. The rest come with a Laravel Vue starter kit.';
    }

    /**
     * Offers the account that makes the login page usable.
     *
     * Offered rather than assumed: an install into an application that
     * already has users does not need one, and creating a row in somebody's
     * user table uninvited is not a thing an installer should do.
     */
    private function offerUser(?string $panel): void
    {
        if ($this->option('no-user') || ! $this->input->isInteractive()) {
            return;
        }

        if (! confirm(label: 'Create a user who can sign in?', default: false)) {
            return;
        }

        $this->call('panel:user', $panel === null ? [] : ['--panel' => mb_strtolower($panel)]);
    }

    private function reportOutstanding(): void
    {
        $this->newLine();

        if ($this->outstanding === []) {
            $this->components->info('Done. Nothing is left to do by hand.');

            return;
        }

        $this->components->warn(sprintf(
            '%d thing(s) this package cannot do for your application:',
            count($this->outstanding),
        ));

        foreach ($this->outstanding as $index => $step) {
            $this->newLine();
            $this->line('  '.($index + 1).'. '.$step);
        }

        $this->newLine();
    }

    private function publish(string $tag): void
    {
        $this->call('vendor:publish', [
            '--tag' => $tag,
            '--force' => (bool) $this->option('force'),
        ]);
    }

    /**
     * Whether to hand the migrations over to the application.
     *
     * They already run from the package, so this is about ownership rather
     * than about the tables existing: a project that wants them in its own
     * `database/migrations` — to edit, or to keep one directory as the record
     * of its schema — says so here. Offered rather than assumed, because a
     * published copy and a package copy of the same migration is a schema
     * applied twice.
     */
    private function shouldPublishMigrations(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return confirm(
            label: 'Publish the migrations into database/migrations?',
            default: false,
            hint: 'They already run from the package. Publish only to own them, '
                .'and set load_migrations to false in config/panda-panel.php if you do.',
        );
    }
}
