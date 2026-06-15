<?php

declare(strict_types=1);

namespace zeixcom\craftdelta;

/**
 * Workflow permission handles — single source of truth for every ->can() check.
 */
final class Permissions
{
    public const SUBMIT = 'craftdelta-submitDraft';
    public const REVIEW = 'craftdelta-reviewDraft';
    public const APPLY = 'craftdelta-applyReview';
}
