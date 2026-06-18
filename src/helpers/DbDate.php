<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use craft\helpers\DateTimeHelper;
use DateTime;

/** Parse UTC datetime strings from the DB without timezone drift. */
final class DbDate
{
    public static function parse(?string $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = DateTimeHelper::toDateTime($value);
        return $date instanceof DateTime ? $date : null;
    }
}
