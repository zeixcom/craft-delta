<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

/**
 * Represents the diff result for a single field.
 */
class FieldDiff extends Model
{
    public string $fieldHandle;
    public string $fieldLabel;
    public string $fieldType;
    public string $tabName = '';
    public bool $hasChanges = false;

    /**
     * Rendered HTML of the diff, or structured JSON for complex fields.
     */
    public ?string $diffHtml = null;

    /**
     * Stats about the changes (additions, deletions).
     */
    public array $stats = [];
}
