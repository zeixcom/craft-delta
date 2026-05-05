<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

/**
 * Global plugin settings for Craft Delta.
 */
class Settings extends Model
{
    /**
     * Number of unchanged lines to show around changes.
     */
    public int $diffContext = 3;

    /**
     * Max characters before showing simplified diff.
     */
    public int $maxFieldLength = 50000;

    /**
     * Show unchanged fields by default.
     */
    public bool $defaultShowUnchanged = false;

    /**
     * Enable review mode UI (Start Review button, accept/reject, apply).
     * When false, the plugin behaves as a pure read-only diff tool.
     */
    public bool $enableReviewMode = true;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['diffContext'], 'integer', 'min' => 0, 'max' => 20];
        $rules[] = [['maxFieldLength'], 'integer', 'min' => 1000];

        return $rules;
    }
}
