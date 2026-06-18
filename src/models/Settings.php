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

    /**
     * Show the live draft preview beside the diff on the review page (for entries
     * whose section has front-end URLs). When false, the preview pane is dropped
     * everywhere — distinct from the per-user hide toggle, which only collapses it
     * for that reviewer. Reviewers can still open the entry's own preview from the
     * editor as usual.
     */
    public bool $enablePreview = true;

    /**
     * Override the built-in notification email templates. Maps an email key to a
     * template path resolved against your site `templates/` folder; an unmapped
     * key (or one whose template is missing) falls back to the bundled default.
     * Config-file only — it's an array, so set it in `config/craft-delta.php`,
     * not the CP. Keys: `submitted`, `approved`, `declined`, `published`,
     * `comment`. See `config.php` for the variables each template receives.
     *
     * @var array<string, string>
     */
    public array $emailTemplates = [];

    protected function defineRules(): array
    {
        return [
            ...parent::defineRules(),
            [['diffContext'], 'integer', 'min' => 0, 'max' => 20],
            [['maxFieldLength'], 'integer', 'min' => 1000],
        ];
    }
}
