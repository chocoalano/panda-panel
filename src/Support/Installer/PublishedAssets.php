<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;
use PandaPanel\Support\FrontendPaths;

/**
 * Every file this package publishes into an application, in one place.
 *
 * The service provider's `publishes()` calls read this, and so does
 * `panel:assets`. That is the whole reason it exists as a class: a publish map
 * written out in the provider and a second copy in the command that has to
 * diff it would drift the first time a directory was added, and the symptom
 * would be a file that publishes but is never reported as out of date — which
 * is worse than not reporting at all.
 *
 * The frontend is published rather than imported from the package because
 * every component registry is an `import.meta.glob` allowlist over the
 * application's own tree: a component the build never saw is a component that
 * cannot resolve. Published files are the application's — in its repository,
 * in its build, and editable.
 *
 * The cost of that decision is the one `panel:assets` exists to pay: once a
 * file is the application's, a package update cannot silently improve it.
 *
 * ## Two maps, and why the translations are the second one
 *
 * The frontend has to be published for a panel to work at all. The
 * translations do not: `loadTranslationsFrom()` reads the package's own
 * `lang/` directory, and an application that publishes nothing already speaks
 * every locale the package ships. Publishing them is a choice — to reword a
 * sentence, or to keep the strings in the project's own repository.
 *
 * So they are tracked only once that choice has been made. `files()` includes
 * a translation the moment `lang/vendor/panda-panel` exists and never before
 * it, which means an application that never published is never told about
 * files it did not ask for, and one that did publish gets them treated
 * exactly like the frontend from then on: reported when the package moves
 * ahead, written by `panel:assets --update`, and never silently overwritten
 * where it has reworded a line.
 */
final class PublishedAssets
{
    /**
     * The map `vendor:publish --tag=panda-panel-assets` is given: absolute
     * source => absolute destination.
     *
     * Built per call rather than held in a constant because two destinations
     * are configurable, and reading config at class-definition time would
     * freeze whatever it happened to be during package discovery.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            self::packagePath('resources/js/panel') => FrontendPaths::panel(),
            self::packagePath('resources/js/components') => resource_path('js/components'),
            self::packagePath('resources/js/composables') => resource_path('js/composables'),
            self::packagePath('resources/js/lib') => resource_path('js/lib'),
            self::packagePath('resources/js/pages') => resource_path('js/pages'),
            self::packagePath('resources/js/types') => resource_path('js/types'),
            self::packagePath('resources/css/panda-panel.css') => resource_path('css/panda-panel.css'),
        ];
    }

    /**
     * The map `vendor:publish --tag=panda-panel-translations` is given.
     *
     * `lang/vendor/panda-panel` is where Laravel's file loader looks for a
     * namespaced override, and it merges key by key over the package's own
     * copy — so a published file that is missing a key added in a later
     * release still gets that key from the package.
     *
     * @return array<string, string>
     */
    public static function translations(): array
    {
        return [
            self::packagePath('lang') => lang_path('vendor/panda-panel'),
        ];
    }

    /**
     * Every individual file, keyed by where it lands in the application.
     *
     * Files rather than directories, because "is this up to date" is a
     * question about a file: a directory that gained one component and had
     * another edited is neither changed nor unchanged.
     *
     * @return array<string, string> absolute destination => absolute source
     */
    public static function files(): array
    {
        return [
            ...self::expand(self::map()),
            ...self::translationFiles(),
        ];
    }

    /**
     * The translations this application has actually adopted, file by file.
     *
     * Empty until something publishes them, which is the whole of the
     * "tracked only once the choice has been made" rule above. Once the
     * directory is there every locale the package ships is tracked against
     * it, including one added in a later release — that file reads as `new`
     * and `panel:assets --update` writes it.
     *
     * @return array<string, string> absolute destination => absolute source
     */
    public static function translationFiles(): array
    {
        $adopted = array_filter(
            self::translations(),
            static fn (string $destination): bool => File::exists($destination),
        );

        return self::expand($adopted);
    }

    /**
     * A destination path as it reads in a report — relative to the
     * application, because an absolute path in a list of forty is noise.
     */
    public static function relative(string $path): string
    {
        $base = base_path().'/';

        return str_starts_with($path, $base) ? mb_substr($path, mb_strlen($base)) : $path;
    }

    /**
     * A directory map, expanded to one entry per file.
     *
     * @param  array<string, string>  $map  source => destination
     * @return array<string, string> destination => source
     */
    private static function expand(array $map): array
    {
        $files = [];

        foreach ($map as $source => $destination) {
            if (! File::isDirectory($source)) {
                $files[$destination] = $source;

                continue;
            }

            // A destination *inside* its own source. It cannot happen in an
            // application — the package sits in `vendor/` — but it is exactly
            // the shape of this repository, which is its own test
            // application: `lang_path('vendor/panda-panel')` lands under the
            // package's own `lang/`. Left in, the published copies would be
            // enumerated as sources and mapped back onto themselves, one
            // directory deeper on every run.
            //
            // Strictly inside: the frontend map has source and destination
            // *equal* here for the same reason, and skipping on that would
            // skip every file this package ships.
            $nested = $destination !== $source && str_starts_with($destination, $source.'/');

            foreach (File::allFiles($source) as $file) {
                if ($nested && str_starts_with($file->getPathname(), $destination.'/')) {
                    continue;
                }

                $files[$destination.'/'.$file->getRelativePathname()] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function packagePath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
