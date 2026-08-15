<?php

declare(strict_types=1);

namespace PandaPanel\Tables\Columns;

use PandaPanel\Tables\Enums\ColumnType;

final class DateTimeColumn extends DateColumn
{
    protected string $format = 'M j, Y H:i';

    public function type(): ColumnType
    {
        return ColumnType::DateTime;
    }
}
