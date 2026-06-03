# Changelog

## Unreleased

_No changes yet._

## 2.0.0 - 2026-06-03

Major release: adds an inline **Review Mode** and a **submit-for-review workflow** on top of the v1.x diff viewer.

### Added

**Review Mode**

- Accept or reject individual changes from inside the diff slideout, then publish only the accepted changes to the canonical entry as a new revision. Per-field and per-Matrix-block granularity, with block reordering as its own decision.
- Keyboard-driven stepper (`J`/`K` to navigate, `A`/`R` to decide). Decisions persist in browser `localStorage` and resume across restarts; stale-state detection against the canonical entry's `dateUpdated` guards against mid-review edits.
- An **Also delete source draft** option (off by default) when the source is a draft — publishes, then deletes the draft. Left unchecked, the draft persists as a queue of the changes that weren't accepted.
- `MergeService` owns all write logic — atom validation against a fresh diff, field/Matrix apply, a single `saveElement` followed by `applyDraft` for atomic publication.
- `DiffController::actionApply` endpoint with structured error codes (`no-changes`, `source-not-found`, `stale-atoms`, `validation-failed`).
- `Enable Review Mode` setting (default on) as a kill switch.

**Submit-for-review workflow**

- Authors submit a published draft and assign a reviewer. The reviewer can **Approve all** (publish now), **Schedule for…** (publish later via a queued job), **Reject** (terminal, with an optional note emailed to the author), or run a **Granular review** — which opens Review Mode and, on apply, publishes the accepted changes *and* closes the workflow as Approved (a partial apply finalizes the workflow; see the README).
- Three **general** (section-agnostic) permissions under *Craft Delta*: **Submit drafts for review** (`craftdelta-submitDraft`), **Review submitted drafts** (`craftdelta-reviewDraft`), and **Apply review-mode changes** (`craftdelta-applyReview`). Which sections a user can act on is governed by Craft's native section permissions; the reviewer dropdown lists only users who can view peer drafts in the draft's section.
- Email notifications on submit / approve / reject.
- A **Workflow** status column on entry index pages (Pending review / Approved / Approved — scheduled / Rejected).
- `WorkflowService` public API with `EVENT_AFTER_SUBMIT` / `EVENT_AFTER_APPROVE` / `EVENT_AFTER_REJECT` events for third-party integration.
- `Enable Workflow` setting (default on) as a kill switch.

**Other**

- `MatrixDiffer` emits each change's canonical `blockUid` so atom keys round-trip across canonical / draft / revision.
- Review-mode and workflow translations across all 8 supported locales.
- PHPUnit coverage for atom parsing, validation, and the Matrix merge/order algorithm.

### Changed

- Schema version bumped to `2.0.0`. Existing v1.x installs run an upgrade migration that adds the `craftdelta_draft_workflows` table.
- Applying changes (Review Mode or the workflow's Granular review) requires the dedicated **Apply review-mode changes** (`craftdelta-applyReview`) permission; edit/save permissions alone are not sufficient. Admins have it implicitly.

### Compatibility

- No breaking changes for users without workflow permissions — the v1.x read-only diff slideout and **Compare Revisions** button behave identically.

### Upgrading from a 2.0.0 pre-release

- Workflow permissions are no longer scoped per section. If a pre-release granted `craftdelta-*:<sectionUid>` keys, those are obsolete — re-grant the three general permissions and manage section access through Craft's native section permissions.

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
