<?php

declare(strict_types=1);

namespace PandaPanel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\AssetManifest;

/**
 * Tells an application which published frontend files are behind, which it has
 * edited, and which of the two are both.
 *
 * ## The problem
 *
 * The panel's frontend is published into the application rather than imported
 * from the package. That is the right trade — the component registries are
 * build-time `import.meta.glob` allowlists over the application's own tree,
 * and a component you cannot read the source of is one you cannot debug — but
 * it costs the thing every published-asset design costs: **a package update
 * cannot improve a file the application now owns.**
 *
 * `vendor:publish` alone cannot help, because it has only two settings and
 * both are wrong on an upgrade. Without `--force` it skips every file that
 * exists, so nothing updates. With `--force` it overwrites everything,
 * including the files the project deliberately changed. Neither can tell the
 * difference: "differs from the package's copy" is equally true of a stale
 * file and an edited one.
 *
 * ## The fix
 *
 * `AssetManifest` records what each file looked like *when it was published*.
 * That third value is the common ancestor, and it turns an ambiguous
 * two-way comparison into an unambiguous three-way one — the same move
 * `git merge-base` makes. See that class for the full table.
 *
 * Only two categories are ever written automatically, and in both the
 * application demonstrably has no opinion about the file: one it never had,
 * and one it has never touched. A file edited on both sides is reported with
 * its path and left exactly as it is, because resolving that by guessing is
 * how an upgrade eats somebody's work.
 */
final class PanelAssetsCommand extends Command
{
    protected $signature = 'panel:assets
        {--update : Write the files that are safe to write}
        {--force : Also overwrite files this application has edited}';

    protected $description = 'Report which published panel assets are out of date, and update the ones that are safe to';

    /**
     * How each status reads, and whether `--update` acts on it.
     *
     * @var array<string, array{label: string, colour: string, writes: bool}>
     */
    private const STATUSES = [
        AssetManifest::NEW => ['label' => 'new', 'colour' => 'green', 'writes' => true],
        AssetManifest::STALE => ['label' => 'out of date', 'colour' => 'yellow', 'writes' => true],
        AssetManifest::CONFLICT => ['label' => 'CONFLICT', 'colour' => 'red', 'writes' => false],
        AssetManifest::MODIFIED => ['label' => 'yours', 'colour' => 'blue', 'writes' => false],
        AssetManifest::DELETED => ['label' => 'deleted by you', 'colour' => 'gray', 'writes' => false],
        AssetManifest::REMOVED_UPSTREAM => ['label' => 'no longer shipped', 'colour' => 'gray', 'writes' => false],
        AssetManifest::CURRENT => ['label' => 'current', 'colour' => 'gray', 'writes' => false],
    ];

    public function handle(): int
    {
        $report = AssetManifest::compare();

        $counts = array_count_values(array_column($report, 'status'));

        if (! AssetManifest::exists()) {
            $this->components->warn(
                'No .panel-assets.json, so there is no record of what this application published. '
                .'Everything already identical to the package reads as current; anything else reads as new. '
                .'Run --update to write one.',
            );
            $this->newLine();
        }

        $this->summary($counts);

        $written = $this->option('update') || $this->option('force')
            ? $this->update($report)
            : 0;

        $this->detail($report);

        if ($written > 0) {
            // Re-read from disk: the manifest records what the application
            // *has*, so it has to be written after the files, not from what
            // we intended to write.
            AssetManifest::write(AssetManifest::read());

            $this->newLine();
            $this->components->info(sprintf('Wrote %d file(s). Run `npm run build`.', $written));
        }

        if (! $this->option('update') && ! $this->option('force') && $this->writable($counts) > 0) {
            $this->newLine();
            $this->components->info('Run `php artisan panel:assets --update` to write the safe ones.');
        }

        // A conflict is not a failure of this command — it ran correctly and
        // found something a person has to look at. Reporting it as a non-zero
        // exit would break a deploy over a file somebody edited on purpose.
        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{status: string, destination: string, source: string|null}>  $report
     */
    private function update(array $report): int
    {
        $written = 0;

        foreach ($report as $relative => $entry) {
            $writes = self::STATUSES[$entry['status']]['writes'] ?? false;

            // `--force` extends to files this application edited, and to
            // nothing else: a file deleted on purpose stays deleted, and one
            // the package no longer ships is not resurrected.
            if ($this->option('force') && $entry['status'] === AssetManifest::CONFLICT) {
                $writes = true;
            }

            if ($this->option('force') && $entry['status'] === AssetManifest::MODIFIED) {
                $writes = true;
            }

            if (! $writes || $entry['source'] === null) {
                continue;
            }

            File::ensureDirectoryExists(dirname($entry['destination']));
            File::copy($entry['source'], $entry['destination']);

            $this->components->twoColumnDetail($relative, '<fg=green>written</>');

            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function summary(array $counts): void
    {
        foreach (self::STATUSES as $status => $meta) {
            $count = $counts[$status] ?? 0;

            if ($count === 0) {
                continue;
            }

            $this->components->twoColumnDetail(
                $meta['label'],
                sprintf('<fg=%s>%d</>', $meta['colour'], $count),
            );
        }
    }

    /**
     * Lists the files that need a human, and only those.
     *
     * `current` files are the overwhelming majority and saying so 300 times
     * is how a report becomes something nobody reads.
     *
     * @param  array<string, array{status: string, destination: string, source: string|null}>  $report
     */
    private function detail(array $report): void
    {
        $conflicts = array_keys(array_filter(
            $report,
            static fn (array $entry): bool => $entry['status'] === AssetManifest::CONFLICT,
        ));

        if ($conflicts === []) {
            return;
        }

        $this->newLine();
        $this->components->warn(sprintf(
            '%d file(s) changed both here and upstream. Neither copy is safe to throw away, so '
            .'nothing was written. Diff each against the package copy under '
            .'vendor/panda/panel, then re-run with --force once you have merged:',
            count($conflicts),
        ));
        $this->newLine();

        foreach ($conflicts as $path) {
            $this->line('  '.$path);
        }
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function writable(array $counts): int
    {
        return ($counts[AssetManifest::NEW] ?? 0) + ($counts[AssetManifest::STALE] ?? 0);
    }
}
