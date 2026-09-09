<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Enums;

/**
 * A string-backed enum, cast on a fixture model.
 *
 * The shape an application reaches for constantly and the shape a `Select`
 * could not read: a case object is neither a string nor an int, so the form
 * cast answered null and the control rendered empty.
 */
enum EmploymentType: string
{
    case Permanent = 'permanent';
    case Contract = 'contract';
}
