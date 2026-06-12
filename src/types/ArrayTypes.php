<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\types;

/**
 * Shared array-shape aliases for PHPStan. No runtime code.
 *
 * @phpstan-type DiffStats array{additions: int, deletions: int}
 * @phpstan-type AggregateDiffStats array{fieldsChanged: int, additions: int, deletions: int}
 * @phpstan-type CommentAnchor array{anchorType: string, fieldHandle: ?string, blockUid: ?string, atomId: ?string}
 * @phpstan-type MatrixBlockAtom array{blockUid: string, changeType: string}
 * @phpstan-type ParsedFieldAtom array{kind: 'field', handle: string}
 * @phpstan-type ParsedMatrixBlockAtom array{kind: 'matrix-block', fieldHandle: string, blockUid: string, changeType: string}
 * @phpstan-type ParsedMatrixReorderAtom array{kind: 'matrix-reorder', fieldHandle: string}
 * @phpstan-type ParsedAtomKey ParsedFieldAtom|ParsedMatrixBlockAtom|ParsedMatrixReorderAtom
 * @phpstan-type ReviewBuckets array{assigned: \zeixcom\craftdelta\models\Review[], submitted: \zeixcom\craftdelta\models\Review[], all: \zeixcom\craftdelta\models\Review[]}
 * @phpstan-type SerializedMatrixBlock array{uid: string, draftEntryUid: string, payload: array<string, mixed>}
 * @phpstan-type ReviewSummaryPayload array{id: ?int, state: string, round: int}
 * @phpstan-type CommentJsonPayload array{
 *   id: ?int,
 *   body: string,
 *   authorName: ?string,
 *   anchorType: string,
 *   fieldHandle: ?string,
 *   blockUid: ?string,
 *   atomId: ?string,
 *   resolved: bool,
 *   outdated: bool,
 *   round: int,
 *   parentId: ?int,
 *   dateCreated: ?string,
 *   replies: list<array<string, mixed>>
 * }
 */
final class ArrayTypes
{
    private function __construct()
    {
    }
}
