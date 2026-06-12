<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

class Settings extends Model
{
    public int $diffContext = 3;
    public int $maxFieldLength = 50000;
    public bool $defaultShowUnchanged = false;

    /**
     * Enable review mode UI (Start Review button, accept/reject, apply).
     * When false, the plugin behaves as a pure read-only diff tool.
     */
    public bool $enableReviewMode = true;

    /**
     * Enable the submit-for-review workflow (v2.0+). When false, the plugin
     * behaves exactly like v1.1: no Submit button, no workflow toolbar.
     * Review Mode remains gated separately by enableReviewMode and the
     * Apply review-mode changes permission.
     */
    public bool $enableWorkflow = true;

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['diffContext'], 'integer', 'min' => 0, 'max' => 20];
        $rules[] = [['maxFieldLength'], 'integer', 'min' => 1000];

        return $rules;
    }
}
