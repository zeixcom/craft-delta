<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\types;

/**
 * Shared array-shape aliases for PHPStan. No runtime code.
 *
 * @phpstan-type AnchorType value-of<\zeixcom\craftdelta\enums\CommentAnchorType>
 * @phpstan-type DiffStats array{additions: int, deletions: int}
 * @phpstan-type AggregateDiffStats array{fieldsChanged: int, additions: int, deletions: int}
 * @phpstan-type CommentAnchor array{anchorType: AnchorType, fieldHandle: ?string, blockUid: ?string, atomId: ?string}
 * @phpstan-type MatrixBlockFieldChange array{handle: string, label: string, fieldType: string, diffHtml: ?string}
 * @phpstan-type MatrixBlockChange array{type: value-of<\zeixcom\craftdelta\enums\DiffChangeType>, blockUid?: string, blockType?: string, summary?: string, fieldChanges?: list<MatrixBlockFieldChange>}
 * @phpstan-type AuthenticatedReviewTuple array{0: \zeixcom\craftdelta\Delta, 1: \zeixcom\craftdelta\models\Review, 2: \craft\elements\User}
 * @phpstan-type EntryPair array{0: \craft\elements\Entry, 1: \craft\elements\Entry}
 * @phpstan-type MatrixBlockAtom array{blockUid: string, changeType: value-of<\zeixcom\craftdelta\enums\DiffChangeType>}
 * @phpstan-type ParsedFieldAtom array{kind: 'field', handle: string}
 * @phpstan-type ParsedMatrixBlockAtom array{kind: 'matrix-block', fieldHandle: string, blockUid: string, changeType: value-of<\zeixcom\craftdelta\enums\DiffChangeType>}
 * @phpstan-type ParsedMatrixReorderAtom array{kind: 'matrix-reorder', fieldHandle: string}
 * @phpstan-type ParsedAtomKey ParsedFieldAtom|ParsedMatrixBlockAtom|ParsedMatrixReorderAtom
 * @phpstan-type ReviewBuckets array{assigned: \zeixcom\craftdelta\models\Review[], submitted: \zeixcom\craftdelta\models\Review[], all: \zeixcom\craftdelta\models\Review[]}
 * @phpstan-type ScalarFieldValue bool|\DateTime|\Money\Money|float|int|object|string|null
 * @phpstan-type TableCellValue string|int|float|bool|null
 * @phpstan-type TableRow array<string, TableCellValue>
 * @phpstan-type TableCellDiff array{col: string, old: string, new: string}
 * @phpstan-type TableRowChange array{type: value-of<\zeixcom\craftdelta\enums\DiffChangeType>, row: int, values: TableRow, cells?: list<TableCellDiff>}
 * @phpstan-type MatrixBlockPayload array{
 *   type: string,
 *   title: ?string,
 *   slug: ?string,
 *   enabled: bool,
 *   collapsed: bool,
 *   fields: array<string, mixed>
 * }
 * @phpstan-type SerializedMatrixBlock array{uid: string, draftEntryUid: string, payload: MatrixBlockPayload}
 * @phpstan-type OrderedMatrixBlock array{uid: string, draftEntryUid?: string, payload: MatrixBlockPayload}
 * @phpstan-type MatrixCanonicalDraftMap array<string, array{draftEntryUid: string}>
 * @phpstan-type MatrixSetValue array{entries: array<string, MatrixBlockPayload>, sortOrder: list<string>}
 * @phpstan-type ReviewTransitionAttrs array{
 *   state?: string,
 *   dateUpdated?: string,
 *   decidedBy?: int|null,
 *   decisionNote?: string|null,
 *   scheduledFor?: string|null,
 *   round?: int
 * }
 * @phpstan-type EmailExtraVars array{round?: int}
 * @phpstan-type EmailDispatchVars array{
 *   author: \craft\elements\User,
 *   entry: \craft\elements\Entry,
 *   url: string,
 *   reviewer?: \craft\elements\User,
 *   round?: int,
 *   note?: string|null,
 *   scheduledFor?: string|null,
 *   commenter?: string,
 *   comment?: string
 * }
 * @phpstan-type ReviewSummaryPayload array{id: ?int, state: string, round: int}
 * @phpstan-type CommentJsonFields array{
 *   id: ?int,
 *   body: string,
 *   authorName: ?string,
 *   anchorType: AnchorType,
 *   fieldHandle: ?string,
 *   blockUid: ?string,
 *   atomId: ?string,
 *   resolved: bool,
 *   outdated: bool,
 *   round: int,
 *   parentId: ?int,
 *   dateCreated: ?string
 * }
 * @phpstan-type CommentJsonReply array{
 *   id: ?int,
 *   body: string,
 *   authorName: ?string,
 *   anchorType: AnchorType,
 *   fieldHandle: ?string,
 *   blockUid: ?string,
 *   atomId: ?string,
 *   resolved: bool,
 *   outdated: bool,
 *   round: int,
 *   parentId: ?int,
 *   dateCreated: ?string,
 *   replies: list<never>
 * }
 * @phpstan-type CommentJsonPayload array{
 *   id: ?int,
 *   body: string,
 *   authorName: ?string,
 *   anchorType: AnchorType,
 *   fieldHandle: ?string,
 *   blockUid: ?string,
 *   atomId: ?string,
 *   resolved: bool,
 *   outdated: bool,
 *   round: int,
 *   parentId: ?int,
 *   dateCreated: ?string,
 *   replies: list<CommentJsonReply>
 * }
 */
final class ArrayTypes
{
    private function __construct()
    {
    }
}
