# Craft Delta — Review Mode Design

- **Date:** 2026-05-05
- **Status:** Draft (pending user review)
- **Branch:** `feature/review-mode`
- **Plugin:** `plugins/craft-delta`

## 1. Context

Craft Delta currently produces read-only field-by-field diffs between any two of `current`, a draft, or a revision of an entry. The diff renders inline (word-level for text, block-level for Matrix, value-level for everything else) but offers no way to act on what's shown.

User feedback flagged this as the dominant pain point: reviewers can see what changed, but to actually apply suggested edits they have to copy text out of the diff, navigate to the entry editor, find the right field, and paste it in — for each change. That breaks the review workflow.

This spec defines a **Review Mode** layered on top of the existing diff: per-field and per-Matrix-block accept/reject decisions, deferred application, and a single save event that produces a new draft of the canonical entry.

## 2. Goals and non-goals

### Goals

- Per-field and per-Matrix-block accept/reject from inside the diff slideout.
- Deferred application: decisions are staged client-side; one HTTP call applies them all.
- The merged result is saved as a **new draft** on the canonical entry, not a direct overwrite.
- Resumable across browser/tab restarts on the same device.
- Entirely additive — existing read-only diffs continue to work unchanged.

### Non-goals (v1)

- Sub-field merging (per-word inside CKEditor, per-cell inside Tables, per-row inside Tables).
- Cross-device resumability.
- Direct apply to canonical (without a draft step).
- Review mode when neither side of the comparison is `current`.
- Cross-site propagation of the merged result.
- Concurrency control beyond stale-state detection.

## 3. Locked decisions

These five questions were answered during brainstorming and the design depends on them:

1. **Direction of "accept"** — Accepting a change means *applying the non-current side's value into current*. The other side is the source of suggestions; current is the merge target.
2. **Granularity** — Per-field for plain fields; per-block for Matrix; reorder is its own atom. No sub-field merging.
3. **Apply target** — The merged result is published directly to the canonical entry as a new revision. Internally a transient draft is created and immediately applied via Craft's `Drafts::applyDraft()` so the publish goes through Craft's normal lifecycle (events, hooks, search reindex). Permission required: `saveEntries:<sectionUid>`. *(Original v1 plan was to leave the draft for the user to review and publish manually; revised after user testing — that step felt redundant after a careful per-change review.)*
4. **Selector constraint** — Review mode is available only when one of the two version selectors is `current`. Otherwise the diff stays read-only.
5. **Persistence** — Decisions are persisted to localStorage, keyed by `(userId, entryId, siteId, sourceRef)`, with a stale-state guard against the canonical's `dateUpdated`.

## 4. Architecture

Three new pieces; everything else stays as it is.

### 4.1 Client review-mode controller

Lives in the existing `src/assets/diff/dist/js/delta.js` as a top-level object alongside the current slideout logic. Owns the in-memory atom-state map, the localStorage mirror, the stepper, the per-atom buttons, and the apply call. Roughly 200–250 lines.

### 4.2 `actionApply` endpoint on `DiffController`

Receives the entry id, the source reference, and the list of accepted atoms. Validates, delegates to `MergeService`, returns the resulting draft's edit URL.

### 4.3 `MergeService`

A new service alongside `DiffService`, `RevisionService`, `FieldDiffService`. Owns all merge logic: validating atoms against a fresh diff, copying field values into a draft, and the Matrix-block merge algorithm. Pure write side; shares no mutable state with `DiffService`.

### 4.4 What stays unchanged

- `DiffService::compare` and the `DiffResult` / `FieldDiff` models.
- All differs (`TextDiffer`, `MatrixDiffer`, `RelationDiffer`, etc.).
- The existing `actionCompare` and `actionRevisions` endpoints.
- Diff template files structurally — they gain a `reviewMode` flag and `data-atom-id` attributes on existing wrappers, no layout changes.

## 5. Data contracts

### 5.1 The atom

Every accept/reject button operates on a *change atom* identified by a stable string key. Three kinds:

| Kind | Key format | Meaning |
|---|---|---|
| `field` | `field:<handle>` | Replace canonical's value for this field/attribute with source's. Used for `title`, `slug`, and all non-Matrix fields. |
| `matrix-block` | `matrix-block:<fieldHandle>:<blockUid>:<changeType>` | Apply one block-level Matrix change. `changeType` ∈ `{added, removed, modified}`. |
| `matrix-reorder` | `matrix-reorder:<fieldHandle>` | Use source's block order for this Matrix field. |

Atoms are flat strings end-to-end: in JS state, in localStorage, in the apply payload, and in DOM `data-atom-id` attributes.

### 5.2 Apply endpoint

```
POST /actions/craft-delta/diff/apply
Content-Type: application/json

{
  "entryId": 123,
  "siteId": 1,
  "sourceRef": "current" | "draft:<id>" | "<revisionId>",
  "acceptedAtoms": [
    "field:title",
    "field:body",
    "matrix-block:blocks:8a3f-...:added",
    "matrix-reorder:blocks"
  ]
}

200 OK
{
  "success": true,
  "draftId": 17,
  "draftEditUrl": "/admin/entries/blog/123-the-slug?draftId=17"
}

422 Unprocessable Entity
{
  "success": false,
  "errorCode": "stale-atoms" | "source-not-found" | "validation-failed" | "no-changes",
  "error": "Human-readable message"
}

403 Forbidden
{
  "success": false,
  "error": "Insufficient permissions to create a draft on this section."
}
```

Error codes are stable strings the client can branch on; messages are localized strings for display.

### 5.3 localStorage shape

```javascript
// key: craftdelta:review:<userId>:<entryId>:<siteId>:<sourceRef>
{
  "version": 1,
  "canonicalUpdatedAt": "2026-05-01T14:23:00Z",
  "decisions": {
    "field:title": "accepted",
    "field:body": "rejected",
    "matrix-block:blocks:8a3f-...:added": "accepted",
    "matrix-reorder:blocks": "pending"
  }
}
```

Atoms not present in `decisions` are implicitly `pending`. `canonicalUpdatedAt` is the entry's `dateUpdated` at the moment the review started — used as a stale-state sentinel.

### 5.4 Reorder corner case

If a user accepts an `added` Matrix atom but rejects the `matrix-reorder` atom for the same field, the source-only block is appended to the *end* of canonical's existing block order. v1 documents this rule explicitly. v2 may offer per-block insertion-position UI.

### 5.5 `FieldDiff` model changes

None. The model stays as-is. Templates gain a `reviewMode` boolean parameter and emit `data-atom-id` attributes on existing wrappers; the JS hangs Accept/Reject buttons off those attributes.

## 6. Server-side apply

### 6.1 `actionApply` flow

```
1. Resolve canonical and source via existing helpers.
2. Permission check: user can create drafts on this section.
3. Re-run DiffService::compare(canonical, source) for fresh atom validation.
4. Validate every acceptedAtom maps to a real change in the fresh diff.
   → if any stale atom: 422 errorCode="stale-atoms"
5. Create a Craft draft of canonical (Craft::$app->drafts->createDraft).
6. Apply field atoms (loop, copy source value → draft).
7. Apply Matrix atoms (per-field merge algorithm, see 6.2).
8. ONE Craft::$app->elements->saveElement($draft).
9. Return { draftId, draftEditUrl }.
```

Step 4 is non-negotiable: silently dropping stale atoms could mean the user's expected merge differs from the actual one. Fail loudly.

Step 8 is one save per apply, never per field — saving per-field would cascade element-index updates and re-fire events.

### 6.2 Matrix merge algorithm

For each Matrix field with at least one accepted atom:

**Inputs**
- `currentBlocks` — canonical's block list, in canonical order
- `sourceBlocks` — source's block list, in source order
- `acceptedBlockAtoms` — list of `(blockUid, changeType)` for this field
- `acceptedReorder` — bool

**Step A — build the surviving block set, starting from `currentBlocks`:**
- For each accepted `added` atom: take that block from `sourceBlocks`, mark it for inclusion.
- For each accepted `removed` atom: drop that block from the working set.
- For each accepted `modified` atom: replace the block's content with `sourceBlocks`' version (UID preserved).
- Rejected atoms are no-ops (current state wins by default).

**Step B — order the surviving blocks:**

If `acceptedReorder == true`:
- Spine = source's order.
- Both-sides blocks: at their source position.
- Source-only accepted-`added` blocks: at their source position.
- Kept current-only blocks (those whose `removed` atom was rejected): preserve their *relative* order from current, inserted immediately after the most-recent both-sides anchor from current's perspective.

If `acceptedReorder == false`:
- Spine = current's order.
- Modified blocks stay at their current position (UID-stable, content from source).
- Source-only accepted-`added` blocks: appended at the *end*.
- Kept current-only blocks: at their current position.

**Worked example for the anchor rule (`acceptedReorder == true`):**

- Current order: `A, B, C, D, E` (B exists only in current — was "removed" from source's perspective).
- Source order: `A, X, C, E, D` (X is source-only; D and E reordered relative to current).
- User accepts: X-`added`, reorder. User rejects: B-`removed`. (So B survives.)

Spine = source order = `A, X, C, E, D`. B's most-recent both-sides anchor in current's order is A (B was at position 2 in current, A was at position 1). B inserts immediately after A in the result.

Result: `A, B, X, C, E, D`.

**Step C — write to draft:**

Set the merged block list as the field value via Craft's standard `setFieldValue` Matrix pathway. No manual SQL.

### 6.3 Plain-field apply

```php
$draft->setFieldValue($handle, $source->getFieldValue($handle));
```

Trusts Craft's serialization. Verified through integration tests for CKEditor (with embedded entry references), Asset (with focal points / transforms), Money, Categories, Tags, Entries, Tables, scalars.

### 6.4 Title and slug

Attributes, not fields: `$draft->title = $source->title` / `$draft->slug = $source->slug`. Pattern matches existing `DiffService::compareAttributes`.

### 6.5 Multisite

Atoms are site-aware via the `siteId` in the apply payload. `MergeService::merge` operates on canonical/source for that single site only. The created draft exists on that site. Cross-site propagation is out of scope for v1.

### 6.6 Failure modes

| Scenario | Response |
|---|---|
| Source revision not found / deleted mid-review | 422 `errorCode="source-not-found"` — client clears localStorage |
| Stale atom (diff changed since review started) | 422 `errorCode="stale-atoms"` — client clears localStorage and reloads diff |
| User lacks `createEntryDrafts:<sectionUid>` | 403 — client preserves localStorage |
| Draft validation fails on save | 422 `errorCode="validation-failed"` with field error in message — client preserves localStorage so user can retry |
| Empty `acceptedAtoms` | 422 `errorCode="no-changes"` — UI prevents reaching this in normal use |
| Source has a field not present on canonical (schema change) | Atom for that field treated as stale → 422 `errorCode="stale-atoms"` |

### 6.7 New service file layout

```
src/services/MergeService.php
  public  merge(Entry $canonical, Entry $source, array $acceptedAtoms): Entry  // returns the saved draft
  private validateAtoms(DiffResult $freshDiff, array $acceptedAtoms): void
  private applyFieldAtoms(Entry $draft, Entry $source, array $fieldAtoms): void
  private applyMatrixAtoms(Entry $draft, Entry $source, array $matrixAtoms, array $reorderHandles): void
  private buildMatrixBlockList(array $current, array $source, array $blockAtoms): array
  private orderMatrixBlocks(array $survivingBlocks, array $current, array $source, bool $acceptedReorder): array
```

Registered on `Delta.php` as `$plugin->merge`, mirroring the existing `$plugin->diff`, `$plugin->revision`, `$plugin->fieldDiff`.

## 7. Client UI

### 7.1 Start Review button

Lives in the slideout header. Visible and enabled only when one of the two version selectors is `current`. When the user changes a selector such that neither side is `current`, the button disappears and any in-progress decisions are cleared from localStorage for that key.

### 7.2 Atom-state map

Single in-memory object:

```javascript
const reviewState = {
  "field:title": "accepted",
  "matrix-block:blocks:8a3f-...:added": "pending"
};
```

States: `"accepted" | "rejected" | "pending"` (default for missing keys). Writes to localStorage are debounced ~150ms.

### 7.3 Per-atom buttons

Each `FieldDiff` wrapper and each Matrix block change wrapper carries `data-atom-id="<key>"` (added in templates). A single delegated click handler on the slideout root reads `event.target.closest('[data-atom-id]')` and dispatches.

Button layout: **inline pair** ("✓ Accept" / "✗ Reject") rendered to the right of each change wrapper. Chosen over hover-revealed for accessibility (keyboard-only and mobile users). A future "compact mode" toggle can be added in plugin settings if dense diffs feel cluttered.

### 7.4 Visual atom states

| State | Style |
|---|---|
| pending | neutral dotted border on the change wrapper |
| accepted | solid green left-border, faint green tint, ✓ icon next to atom-id |
| rejected | solid red left-border, faint red tint, strike-through overlay on the diff content |
| stepper focus | blue 2px outline ring (independent of the state above) |

Reuses existing `delta-ins`/`delta-del` classes for diff content. New state classes (`delta-atom-accepted`, `delta-atom-rejected`, etc.) live on the wrapper.

### 7.5 Stepper bar

Sticky bar at the top of the slideout, visible only in review mode:

```
[12 / 38 decided]  [← Prev]  [Next →]  [Apply 8 accepted →]
```

- **Next / Prev** walk through *all* atoms in document order, regardless of decision state. v2 may add a "skip-decided" mode.
- Keyboard shortcuts: `J` / `K` (next / prev), `A` (accept current focus), `R` (reject current focus). Help tooltip lists them.
- Scrolling updates "current focus" via an IntersectionObserver.

### 7.6 Apply CTA

Right-most button in the stepper. Disabled until at least one atom is `accepted`. Click flow:

1. Confirm modal: "Create a new draft with N accepted changes? Rejected changes will not affect the entry."
2. POST `acceptedAtoms` to `actionApply`.
3. **200**: toast "Draft created" with two buttons — "Open draft" (navigates to `draftEditUrl`) and "Stay here". localStorage for this review is cleared.
4. **422 stale-atoms**: banner "the entry has changed since you started reviewing; restarting" + clear localStorage + reload diff.
5. **422 validation-failed**: toast with the field error; localStorage *preserved* so the user can drop the offending atom and retry.
6. **403 / 404 / network error**: toast with retry; localStorage preserved.

### 7.7 Resume flow

On slideout open, read localStorage for the current `(userId, entryId, siteId, sourceRef)` key:

- No entry → fresh review-mode session (when activated).
- Entry exists, `canonicalUpdatedAt` matches the live entry's `dateUpdated` → banner: "Resume previous review (X of Y decided)?" with [Resume] / [Start fresh] buttons.
- Entry exists but `canonicalUpdatedAt` drifted → silently drop the cache; toast: "the entry has changed since your last review; starting fresh".

### 7.8 Cancel / abandon

A "Cancel review" link in the stepper:
- No decisions yet → exits review mode silently.
- Decisions exist → confirm "Discard N decisions?" → on confirm, clear localStorage and exit.

Closing the slideout *without* clicking Cancel = decisions persist (intentional; supports "boss interrupted me" scenarios).

### 7.9 JS surface

A new top-level `reviewMode` object inside `delta.js`:

```javascript
reviewMode = {
  enter(),
  exit(),
  recordDecision(atomId, state),
  next(),
  prev(),
  apply(),
  cancel(),
  loadFromStorage(),
  saveToStorage(),     // debounced
  // private helpers...
}
```

Roughly 200–250 lines. The existing `applyFilter` / `restoreCollapsed` / etc. logic is untouched.

## 8. Error handling matrix (consolidated)

| Scenario | Handling |
|---|---|
| Source revision deleted mid-review | 422 source-not-found → clear localStorage → banner |
| Canonical edited mid-review by another user | 422 stale-atoms → clear localStorage → restart diff |
| Two reviewers reviewing the same entry simultaneously | Each gets their own draft; canonical immutability preserved; no conflict |
| Network error / timeout on apply | Toast with retry; localStorage preserved |
| User loses draft permission mid-review | 403 → localStorage preserved |
| Schema change (source has a field not on canonical) | Atom marked stale → 422 stale-atoms |
| Source's accepted Matrix block has invalid content | 422 validation-failed; localStorage preserved; user drops atom and retries |
| All decisions rejected | UI disables Apply; never reaches server |

## 9. Testing

### 9.1 Unit tests (no Craft kernel needed)

- `MergeServiceTest::buildMatrixBlockList` — table-driven across `(added, removed, modified) × (accepted, rejected) × (reorder accepted, reorder rejected)`. ~24 cases.
- `MergeServiceTest::orderMatrixBlocks` — both reorder modes, including the kept-current-only "anchor" rule.
- `MergeServiceTest::validateAtoms` — stale-atom detection: feed atoms not in the fresh diff; expect rejection.
- `AtomKeyParserTest` — regex-based parsing of atom keys, including malformed input rejection.

### 9.2 Integration tests (Craft kernel boot needed — prerequisite)

- End-to-end apply: build canonical + source revision, apply atoms, verify resulting draft matches expectation.
- Stale detection: modify canonical mid-flow, verify 422.
- Permission failure: user without `createEntryDrafts:<sectionUid>` gets 403.
- Multisite: apply on site B doesn't touch site A.
- Title/slug attribute apply (separate code path from fields).
- Field types: CKEditor with embedded entries, Asset with focal points, Money with currency, Table.

### 9.3 Manual QA checklist

- [ ] Resume banner appears after browser close + reopen
- [ ] Stale-state banner appears when canonical changes mid-review
- [ ] Cancel mid-review with confirmation
- [ ] Apply with 0 accepts: button disabled
- [ ] Matrix: accept add + accept reorder
- [ ] Matrix: accept add + reject reorder (verify "appended at end" rule)
- [ ] Matrix: accept modified block, reject reorder
- [ ] Multisite entry: site A review doesn't bleed into site B
- [ ] CKEditor field with embedded entries: embeds survive apply
- [ ] Asset field: focal points / transforms preserved
- [ ] Permission denial: user without draft rights → 403
- [ ] Keyboard shortcuts (J / K / A / R)

## 10. Settings

One new plugin setting:

| Setting | Default | Description |
|---|---|---|
| Enable Review Mode | On | Show the "Start Review" button. When off, plugin behaves as today (read-only diffs). |

A kill switch for conservative deployments. No other new settings.

## 11. Translations

The new UI strings need entries in all 8 supported locales (en, de, fr, es, it, nl, pt, pl). Approximately 15–20 new strings:

- "Start Review", "Cancel review"
- "Accept", "Reject", "Pending"
- "{count} of {total} decided"
- "Apply {count} accepted changes"
- "Create a new draft with {count} accepted changes?"
- "Resume previous review ({count} of {total} decided)?"
- "Resume", "Start fresh"
- "The entry has changed since you started reviewing; restarting"
- "Source version not found"
- "Insufficient permissions to create a draft on this section"
- Apply success/failure toasts and modal copy

Concrete string list will be in the implementation plan.

## 12. Documentation

Update `README.md`:
- "Features" — add review-mode bullet.
- "Usage" — describe Start Review → accept/reject flow → Apply.
- "Settings" — add the kill-switch row.

The "Roadmap" line about extending differs (NEO, Hyper, etc.) stays — review mode and field-type extension are independent axes.

## 13. Open questions / future work

These are explicitly **out of scope for v1** but worth flagging for v2 consideration:

- **Per-word / per-cell granularity**. Would require refactoring the diff result into structured patch operations rather than the current mix of HTML and JSON, plus a non-trivial reconstruction step on apply.
- **Skip-decided stepper mode** — "Next undecided" jump.
- **Per-block insertion-position UI** for accepted-add + rejected-reorder.
- **Compact button mode** — hover/focus-revealed instead of inline pair.
- **Cross-site propagation of merge result** — currently the draft only exists for the request's `siteId`.
- **Server-side resumability** — for cross-device resumption.

## Appendix A — Example apply payload

User reviewed a comparison of `revision:42` against `current` for entry 123 on site 1, accepted the title change and one Matrix block addition, accepted the reorder, rejected a Matrix block removal:

```json
{
  "entryId": 123,
  "siteId": 1,
  "sourceRef": "42",
  "acceptedAtoms": [
    "field:title",
    "matrix-block:blocks:8a3f-1234-...:added",
    "matrix-reorder:blocks"
  ]
}
```

Note: the rejected `matrix-block:blocks:def-...:removed` atom is simply absent from the array — no need to send rejections.

## Appendix B — Example error response (stale atoms)

```json
{
  "success": false,
  "errorCode": "stale-atoms",
  "error": "The entry has changed since you started reviewing. Please reload the diff and restart your review."
}
```

Client behavior on this response: clear the localStorage entry for the current key, reload `actionCompare` to refresh the diff HTML, show the banner above the diff body.
