<?php

declare(strict_types=1);

namespace PandaPanel\Tours;

use InvalidArgumentException;
use PandaPanel\Enums\TourSide;
use PandaPanel\Support\CssHooks;

/**
 * One thing a guided tour has to say, and optionally what it says it about.
 *
 * A step that points at nothing is shown in the middle of the screen. So is
 * one whose target is not on screen — a column the reader has hidden, a field
 * their role does not see — because the sentence is usually still worth
 * reading.
 *
 * Naming a target twice keeps the last one: a popover has one anchor.
 */
final class TourStep
{
    private ?string $description = null;

    private ?string $target = null;

    private TourSide $side = TourSide::Bottom;

    private function __construct(private readonly string $title) {}

    public static function make(string $title): self
    {
        return new self($title);
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * A form field, by the name it was made with.
     */
    public function field(string $name): self
    {
        return $this->marked('field', $name);
    }

    /**
     * A table column's header, by the name it was made with. The card layout
     * draws no headers, so there the step is centred.
     */
    public function column(string $name): self
    {
        return $this->marked('column', $name);
    }

    /**
     * An action's button, by the action's name. The first one on screen when
     * every row carries it.
     */
    public function action(string $name): self
    {
        return $this->marked('action', $name);
    }

    /**
     * A named part of the shell — one of `CssHooks::HOOKS`.
     */
    public function region(string $region): self
    {
        if (! in_array($region, CssHooks::HOOKS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The tour step [%s] points at the region [%s], which the shell does not draw. One of: %s.',
                $this->title,
                $region,
                implode(', ', CssHooks::HOOKS),
            ));
        }

        $this->target = '.panel-'.$region;

        return $this;
    }

    /**
     * Any CSS selector, for something none of the above names.
     */
    public function selector(string $selector): self
    {
        $this->target = $selector;

        return $this;
    }

    public function side(TourSide $side): self
    {
        $this->side = $side;

        return $this;
    }

    /**
     * @return array{title: string, description: string|null, target: string|null, side: string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'target' => $this->target,
            'side' => $this->side->value,
        ];
    }

    /**
     * A target the panel's own components mark with `data-tour`.
     */
    private function marked(string $kind, string $name): self
    {
        $this->target = sprintf('[data-tour="%s:%s"]', $kind, addcslashes($name, '"\\'));

        return $this;
    }
}
