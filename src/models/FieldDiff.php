<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\FieldInterface;
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
     * Single place that knows how to build a FieldDiff from a Craft field.
     *
     * @param DiffStats $stats
     */
    public static function make(FieldInterface $field, bool $hasChanges, string $diffHtml, array $stats, string $tabName = ''): self
    {
        return new self([
            'fieldHandle' => $field->handle,
            'fieldLabel' => $field->name,
            'fieldType' => $field::class,
            'tabName' => $tabName,
            'hasChanges' => $hasChanges,
            'diffHtml' => $diffHtml,
            'stats' => $stats,
        ]);
    }
}
