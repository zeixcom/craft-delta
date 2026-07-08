<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\enums;

/**
 * Prefix kind in stable diff atom keys (field:…, matrix-block:…, etc.).
 */
enum AtomKind: string
{
    case Field = 'field';
    case MatrixBlock = 'matrix-block';
    // one changed field inside a modified block; added/removed blocks stay whole
    case MatrixField = 'matrix-field';
    case MatrixReorder = 'matrix-reorder';
}
