# Changelog

## Unreleased

### Changed — review UI revamp

- **Reviews now happen on a dedicated full-page workspace** at `/admin/delta-review?reviewId=N` (`craft-delta/workflow/review`) instead of inside the diff slideout. The page has a clean header (reviewer roster + round, verdict actions, and a live "decided" counter), the diff, and the general discussion below it.
- **The diff slideout (and the `delta-compare` full page) are now diff-only.** When the draft under view has a review, a slim banner links to the dedicated review page ("Open review"). All verdict buttons, comments, and accept/reject controls were removed from the slideout.
- **Anchored comments render inline** under each changed field/Matrix block (GitHub-style), with an inline "Add comment" affordance — replacing the click-to-open comment panel on the review page.
- **Accept/reject is always-on** on the review page for reviewers who can apply (auto-enters review mode), with the decided counter and "Apply N accepted" in the header. Verdict (Approve) and Apply remain distinct actions. Honors the **Enable Review Mode** kill-switch — when off, the page shows verdict + comments only, no accept/reject/apply.
- The **Reviews dashboard** rows and the **email notifications** (submitted, re-requested, changes requested, declined) now link to the dedicated review page.

### Fixed

- A reply no longer shows a "Reply" affordance (the data model supports only one level of replies), which previously dead-ended in a generic "could not be saved" error.
- Closed/terminal reviews (published, declined, cancelled) now render comments **read-only** — the composer, reply, and add-comment affordances are hidden, since the backend rejects posts on an inactive review.

## 2.0.0 - 2026-06-12

Major release: adds an inline **Review Mode** and a multi-reviewer **submit-for-review workflow** on top of the v1.x diff viewer.

### Added

**Review Mode**

- Accept or reject individual changes from inside the diff slideout, then publish only the accepted changes to the canonical entry as a new revision. Per-field and per-Matrix-block granularity, with block reordering as its own decision.
- Keyboard-driven stepper (`J`/`K` to navigate, `A`/`R` to decide). Decisions persist in browser `localStorage` and resume across restarts; stale-state detection against the canonical entry's `dateUpdated` guards against mid-review edits.
- An **Also delete source draft** option (off by default) when the source is a draft — publishes, then deletes the draft. Left unchecked, the draft persists as a queue of the changes that weren't accepted.
- `MergeService` owns all write logic — atom validation against a fresh diff, field/Matrix apply, a single `saveElement` followed by `applyDraft` for atomic publication.
- `DiffController::actionApply` endpoint with structured error codes (`no-changes`, `source-not-found`, `stale-atoms`, `validation-failed`).
- `Enable Review Mode` setting (default on) as a kill switch.

**Submit-for-review workflow**

- Authors submit a published draft to **one or more reviewers**. Reviewers record verdicts: **Approve**, **Request changes** (with a note emailed to the author), or **Decline** (terminal). Any single change request blocks the review; one approval with no outstanding change requests approves it.
- **Review rounds**: after changes are requested, the author revises and **re-requests** — the reviewer set carries into a new round with fresh verdicts. Authors can **withdraw** an active request and resubmit the same draft later (the review re-opens with a new round); **decline** is terminal.
- **Publishing** an approved review — immediately or **scheduled** via a queued job — is additionally gated by Craft's native save permission on the entry. A schedule is rescinded automatically if the approval is rescinded (changes requested, decline, withdraw, re-request) and when the draft is deleted; deleting a draft cancels its active review.
- A **Granular review** path: Review Mode on a submitted draft; applying accepted atoms publishes them and closes the review (a partial apply finalizes the review; see the README).
- A **Reviews** dashboard in the CP nav: reviews awaiting your verdict, your submissions, and (admins) all reviews.
- Review comments: general or diff-atom-anchored comments with one level of replies, resolve/unresolve, and automatic outdated detection against the live diff. Per-atom comment triggers in the diff slideout, a general discussion thread, and an outdated-comments disclosure (`workflow/comment`, `workflow/resolve-comment`, `workflow/thread`).
- Three **general** (section-agnostic) permissions under *Craft Delta*: **Submit drafts for review** (`craftdelta-submitDraft`), **Review submitted drafts** (`craftdelta-reviewDraft`), and **Apply review-mode changes** (`craftdelta-applyReview`). Which sections a user can act on is governed by Craft's native section permissions; the reviewer picker lists only users who can view peer drafts in the draft's section.
- Email notifications on every transition (submitted, re-requested, changes requested, declined, approved & scheduled, published), each sent in the recipient's preferred language.
- A **Workflow** status column on entry index pages (In review / Changes requested / Approved / Approved — scheduled / Declined / Withdrawn / Published).
- `WorkflowService` events for third-party integration: `EVENT_AFTER_SUBMIT`, `EVENT_AFTER_APPROVE`, `EVENT_AFTER_CHANGES_REQUESTED`, `EVENT_AFTER_DECLINE`, `EVENT_AFTER_REREQUEST`, `EVENT_AFTER_WITHDRAW`, `EVENT_AFTER_PUBLISH` — each carrying the `Review` model as `$event->review`.
- `Enable Workflow` setting (default on) as a kill switch covering both the UI and the endpoints.

**Other**

- `MatrixDiffer` emits each change's canonical `blockUid` so atom keys round-trip across canonical / draft / revision.
- Review-mode and workflow translations across all 8 supported locales.
- PHPUnit coverage for atom parsing, validation, the Matrix merge/order algorithm, and the workflow state machine.

### Changed

- Schema version is `2.1.2`. The upgrade migrations create three tables: `craftdelta_reviews`, `craftdelta_review_reviewers`, and `craftdelta_review_comments`. Reviews survive publication as audit records (`reviews.draftId` is nulled when the draft is applied and deleted).
- Applying changes (Review Mode or the workflow's Granular review) requires the dedicated **Apply review-mode changes** (`craftdelta-applyReview`) permission; edit/save permissions alone are not sufficient. Admins have it implicitly.
- The full-page compare view moved to the `delta-compare` CP URL so it no longer requires the *Access Craft Delta* plugin permission (the old `craft-delta/compare` URL still resolves).

### Compatibility

- No breaking changes for users without workflow permissions — the v1.x read-only diff slideout and **Compare Revisions** button behave identically.

### Upgrading from a 2.0.0 pre-release

- Workflow permissions are no longer scoped per section. If a pre-release granted `craftdelta-*:<sectionUid>` keys, those are obsolete — re-grant the three general permissions and manage section access through Craft's native section permissions.
- The pre-release single-reviewer `craftdelta_draft_workflows` table is dropped and replaced by the reviews tables; pre-release workflow rows are not migrated.

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
