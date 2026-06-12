<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

/**
 * @phpstan-import-type DiffStats from \zeixcom\craftdelta\types\ArrayTypes
 */
class FieldDiff extends Model
{
    public string $fieldHandle;
    public string $fieldLabel;
    public string $fieldType;
    public string $tabName = '';
    public bool $hasChanges = false;

    public ?string $diffHtml = null;

    /** @var DiffStats */
    public array $stats = ['additions' => 0, 'deletions' => 0];

    /**
     * Whether a field type class string refers to Craft's Matrix field.
     * Must match the templates' Matrix test (_field-diff.twig and
     * _diff-content.twig both key on the class ending in "\Matrix").
     */
    public static function isMatrixFieldType(string $fieldType): bool
    {
        return str_ends_with($fieldType, '\\Matrix');
    }
}
