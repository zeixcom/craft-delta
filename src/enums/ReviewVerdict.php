<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/** One reviewer's verdict on a review round. */
enum ReviewVerdict: string
{
    case Pending = 'pending';
    case Approved = 'approved';
}
