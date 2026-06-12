<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/**
 * Review-request lifecycle state persisted on craftdelta_reviews.state.
 */
enum ReviewState: string
{
    case Open = 'open';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Published = 'published';
}
