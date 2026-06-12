<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/**
 * How a review comment is anchored in the diff UI.
 */
enum CommentAnchorType: string
{
    case General = 'general';
    case Field = 'field';
    case Atom = 'atom';
}
