<?php

declare(strict_types=1);

namespace PandaPanel\Tours;

/**
 * A guided tour of one screen: information, or a tutorial, in a few steps.
 *
 * Optional everywhere. `Resource::tour()`, `ResourcePage::tour()`, and
 * `Page::tour()` answer null until somebody says otherwise, and a screen with
 * no tour draws nothing at all.
 *
 * It opens by itself the first time a reader meets it, and from a button in
 * the page header after that. "Seen" is remembered in their browser under the
 * tour's key, so a rewritten tour that should be shown again takes a new one.
 */
final class Tour
{
    /** @var list<TourStep> */
    private array $steps = [];

    private function __construct(private readonly ?string $key) {}

    /**
     * Null takes the screen's own key, which is right until the tour needs
     * showing again to people who have seen it.
     */
    public static function make(?string $key = null): self
    {
        return new self($key);
    }

    /**
     * @param  array<array-key, TourStep>  $steps  in the order they are shown
     */
    public function steps(array $steps): self
    {
        // Reindexed, so a step filtered out by a condition leaves no hole.
        $this->steps = array_values($steps);

        return $this;
    }

    /**
     * Null for a tour with no steps — there is nothing to open.
     *
     * @return array{key: string, steps: list<array{title: string, description: string|null, target: string|null, side: string}>}|null
     */
    public function toArray(string $defaultKey): ?array
    {
        if ($this->steps === []) {
            return null;
        }

        return [
            'key' => $this->key ?? $defaultKey,
            'steps' => array_map(
                static fn (TourStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
