<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Plugins;

use PandaPanel\Core\Panel;
use PandaPanel\Plugins\Plugin;
use Tests\Fixtures\Panel\Relations\ProjectRelationResource;

/**
 * A plugin that is configurable, so both halves of the contract are proven:
 * what it registers, and that the panel can ask it how it was configured.
 */
final class ReportingPlugin extends Plugin
{
    private bool $withResource = true;

    private ?string $group = 'Reporting';

    public static function make(): self
    {
        return new self;
    }

    /**
     * Configurable, which is the point: one plugin, two panels, different
     * shapes — without either panel shipping a class of its own.
     */
    public function withResource(bool $with = true): self
    {
        $this->withResource = $with;

        return $this;
    }

    public function group(?string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function hasResource(): bool
    {
        return $this->withResource;
    }

    public function register(Panel $panel): void
    {
        if ($this->group !== null) {
            $panel->navigationGroups([$this->group]);
        }

        if ($this->withResource) {
            $panel->resources([ProjectRelationResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        // Proves the second phase runs, and runs later than `register()`.
        $panel->cssHooks(['page' => 'reporting-page']);
    }

    /**
     * @return array<string, string>
     */
    public function publishes(): array
    {
        return [
            __DIR__.'/stubs' => resource_path('js/pages/Panels/Reporting'),
        ];
    }
}
