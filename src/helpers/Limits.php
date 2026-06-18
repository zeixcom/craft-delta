<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

/**
 * Input bounds enforced server-side for workflow and merge endpoints.
 */
final class Limits
{
    public const COMMENT_BODY_MAX = 10000;
    public const WORKFLOW_NOTE_MAX = 2000;
    public const ACCEPTED_ATOMS_MAX = 500;
    public const REVIEWER_IDS_MAX = 50;
}
