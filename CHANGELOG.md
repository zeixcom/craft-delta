# Changelog

## Unreleased

### Added
- **Review Mode** — accept or reject individual changes from inside the diff slideout, then publish all accepted changes to the canonical entry as a new revision. Per-field and per-Matrix-block granularity (with reorder as its own atom). Stepper navigation with J/K/A/R keyboard shortcuts. Decisions persist via browser localStorage (`craftdelta:review:<userId>:<entryId>:<siteId>:<sourceRef>` key) and are resumable across browser restarts. Stale-state detection against the canonical entry's `dateUpdated` guards against mid-review edits.
- New `MergeService` owns all write logic — atom validation against a fresh diff, field/Matrix apply, single `saveElement` followed by `applyDraft` for atomic publication.
- New `actionApply` endpoint on `DiffController` with structured error codes (`stale-atoms`, `source-not-found`, `validation-failed`, `no-changes`).
- New plugin setting `Enable Review Mode` (default: on) acting as a kill switch.
- 23 PHPUnit unit tests covering atom parsing, validation, and the Matrix merge algorithm including the spec's worked anchor-rule example.
- Translations for review-mode strings across all 8 supported locales.
- `MatrixDiffer` now emits `blockUid` (the canonical block UID) on each change entry — required for atom keys to round-trip across canonical/draft/revision.

### Changed
- Apply requires the `saveEntries:<sectionUid>` permission (not `createEntryDrafts`) since accepted changes publish directly to the canonical entry rather than to a separate draft.

## 1.1.0 - 2026-04-15

### Added
- Asset relation diffs now render thumbnails, filenames and metadata (dimensions, file size) instead of bare titles.
- Sticky tab headers and a jump-to-section navigation bar at the top of the diff, with a live highlight of the section currently in view while scrolling.
- "Tab:" prefix on tab headers and jump-nav items so it's always clear what kind of section a heading refers to.

### Changed
- Diff colors are now stable across selection changes. Comparisons are always sorted chronologically server-side, so additions stay green and removals stay red regardless of which version sits in which dropdown. The redundant swap button has been replaced with a static arrow indicator.

### Fixed
- Relation diffs inside unsaved Matrix sub-fields no longer leak raw element IDs ("+ 900") into the rendered output. `RelationDiffer` now hydrates raw integer IDs and handles `ElementCollection` values from Craft 5's eager-load path.
- `RelationDiffer::indexById` no longer crashes on transient elements with a null `id`.

### Tests
- Added `RelationDifferTest` with 20 unit tests covering null/empty inputs, set-difference logic, HTML escaping, `ElementCollection`, raw-ID hydration, and the null-id guard. Five Asset rendering tests are explicitly skipped pending a Craft kernel test bootstrap.

## 1.0.0 - 2026-03-02

- Initial release
