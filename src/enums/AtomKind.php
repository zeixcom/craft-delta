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
    case MatrixReorder = 'matrix-reorder';
}
