<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;

class Settings extends Model
{
    public int $diffContext = 3;
    public int $maxFieldLength = 50000;
    public bool $defaultShowUnchanged = false;

    /** Enable review mode UI (Start Review button, accept/reject, apply). When false, pure read-only diff. */
    public bool $enableReviewMode = true;

    /**
     * Submit-for-review workflow (Submit button, workflow toolbar). When false,
     * only the read-only diff UI remains. Review Mode is gated separately by
     * enableReviewMode and the Apply review-mode changes permission.
     */
    public bool $enableWorkflow = true;

    protected function defineRules(): array
    {
        return [
            ...parent::defineRules(),
            [['diffContext'], 'integer', 'min' => 0, 'max' => 20],
            [['maxFieldLength'], 'integer', 'min' => 1000],
        ];
    }
}
