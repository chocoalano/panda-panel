<?php

declare(strict_types=1);

namespace PandaPanel\Enums;

/**
 * Which side of the thing it points at a tour step prefers.
 *
 * A preference: the popover flips to the opposite side when there is no room.
 */
enum TourSide: string
{
    case Top = 'top';
    case Right = 'right';
    case Bottom = 'bottom';
    case Left = 'left';
}
