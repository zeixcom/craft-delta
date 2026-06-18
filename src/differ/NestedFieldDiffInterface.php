<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\differ;

use craft\base\FieldInterface;
use zeixcom\craftdelta\models\FieldDiff;

/**
 * Contract for diffing nested fields inside a Matrix block without coupling
 * differs back to {@see \zeixcom\craftdelta\services\FieldDiffService}.
 */
interface NestedFieldDiffInterface
{
    public function diff(FieldInterface $field, mixed $oldValue, mixed $newValue): ?FieldDiff;
}
