<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Enums;

/**
 * An int-backed enum. Separate from `EmploymentType` because the backing type
 * is the thing under test: a fix that stringified everything would make this
 * one render `"2"` where the contract says `2`.
 */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
