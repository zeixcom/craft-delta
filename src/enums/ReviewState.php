<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/** A review's overall state. See models\Review for the state-machine diagram. */
enum ReviewState: string
{
    case Open = 'open';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Published = 'published';
}
