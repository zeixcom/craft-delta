# Changelog

## 2.2.1 - 2026-07-02

### Changed

- Simplified the action row on an **approved** review: it now shows a single back-out button, the author's **Withdraw**. The reviewer's second back-out button was removed — it read as a near-duplicate of Withdraw and let a reviewer unilaterally kill an already-approved review. A reviewer who wants changes now leaves a comment instead.

## 2.2.0 - 2026-06-30

### Added

- Diff support for popular third-party field types, resolved through `FieldDiffService`'s differ map (unmapped types still fall back to a scalar value diff):
  - **TinyMCE** (`spicyweb/craft-tinymce`) → word-level HTML diff (reuses `HtmlDiffer`).
  - **Code** (`nystudio107/craft-code-field`) → word-level text diff (reuses `TextDiffer`).
  - **Neo** (`spicyweb/craft-neo`) → block-level diff (added / removed / modified / reordered, including nesting-level changes) with recursive sub-field diffs, via a new `NeoDiffer`.
  - **Composite SEO/meta fields** — Beacon's `BeaconSeoField` and SEOmatic's `SeoSettings` → per-attribute diff (`title`, `description`, `canonical`, OG/Twitter tags, …) via a new generic `StructDiffer` that flattens any model/struct value to sorted `key: value` text and reuses `TextDiffer`.
  - **Link fields** — Hyper's `HyperField` → per-link diff with friendly labels (`type`, `value`, `text`, `new window`, `aria`) via a new `LinkDiffer`.
- Third-party field classes are referenced only as `::class` map keys, so the integrations stay optional (no new Composer dependencies).

### Changed

- Matrix block accept/decline actions and comment triggers now sit in a top header bar beside the block toggle, matching field-level review UI.

### Notes

- Neo blocks render **read-only** in Review Mode (no per-block accept/reject) and apply via publish; per-block granular apply remains Matrix-only for now.

## 2.1.1 - 2026-06-19

Maintenance release — no functional changes from 2.1.0.

## 2.1.0 - 2026-06-19

Reworks the **Reviews dashboard** into a native Craft index and tightens the review queue.

### Added

- The Reviews dashboard (`/admin/delta-reviews`) is now a native Craft element-style index: a left **source sidebar** (Awaiting my verdict / My submissions / All reviews, each with a count badge) drives a single sortable, searchable, paginated table, replacing the three stacked tables.

### Changed

- **Awaiting my verdict** now lists only reviews still awaiting *your* verdict (pending in the current round), so it matches the control-panel nav badge.
- The actionable queues (Awaiting my verdict / My submissions) hide concluded reviews (published / declined / withdrawn); the admin **All reviews** source keeps the full audit history.
- The Status column sorts by workflow state rather than by the localized label text.

### Fixed

- Reviewer pills stay on a single line and truncate long names with an ellipsis (full name on hover) instead of wrapping into a multi-line blob.

### Performance

- Dashboard rows batch-load their entry titles in a single query instead of one lookup per row, and the table fetches only the requested source bucket instead of computing all three.

## 2.0.0 - 2026-06-18

Major release: adds an inline **Review Mode** and a multi-reviewer **submit-for-review workflow** on top of the v1.x diff viewer.

### Added

**Review Mode**

- Accept or reject individual changes on the review page, then publish only the accepted changes to the canonical entry as a new revision. Per-field and per-Matrix-block granularity, with block reordering as its own decision.
- Keyboard-driven stepper (`J`/`K` to navigate, `A`/`R` to decide). Decisions persist in browser `localStorage` and resume across restarts; stale-state detection against the canonical entry's `dateUpdated` guards against mid-review edits. The cursor highlight only appears once you start navigating, so the diff isn't pre-highlighted on load.
- An **Also delete source draft** option (off by default) when the source is a draft — publishes, then deletes the draft. Left unchecked, the draft persists as a queue of the changes that weren't accepted.
- `MergeService` owns all write logic — atom validation against a fresh diff, field/Matrix apply, a single `saveElement` followed by `applyDraft` for atomic publication.
- `DiffController::actionApply` endpoint with structured error codes (`no-changes`, `source-not-found`, `stale-atoms`, `validation-failed`).
- `Enable Review Mode` setting (default on) as a kill switch.

**Submit-for-review workflow**

- Authors submit a published draft to **one or more reviewers**. Reviewers **Approve** or **Decline** (with an optional note emailed to the author). To request changes, a reviewer leaves a comment — there is no separate "request changes" verdict.
- A configurable **approval policy** decides when a review passes: **any one** reviewer (default), **all** assigned reviewers, or **at least N** (clamped to the number assigned).
- Reviews happen on a **dedicated full-page workspace** at `/admin/delta-review?reviewId=N`: reviewer roster + round, verdict actions, the diff with **inline (GitHub-style) anchored comments**, a general discussion, and — for reviewers who can apply — always-on per-change accept/reject with a live "decided" counter and **Apply N accepted**. The diff slideout and the `delta-compare` page are diff-only and link to the review page ("Open review").
- A **live draft preview** renders beside the diff on the review page for sections with front-end URLs (a tokenized render of the draft). Open it full-width in a new window, hide it per reviewer (remembered across reviews), or turn it off globally with the **Enable Preview** setting.
- A submitted draft isn't locked: the author can keep revising it (reviewers see the updated diff), **withdraw** an active request (re-submitting re-opens it in a new **round**), or the review is finalized by a verdict. **Decline** is terminal.
- **Publishing** an approved review — immediately, or **scheduled** for later via a queued job and Craft's native date + time picker — requires the `craftdelta-applyReview` permission **and** Craft's native save permission on the entry, so a review-only role can't push content live. A schedule is rescinded automatically when the review leaves the approved state (declined/withdrawn) or its draft is deleted.
- A **Granular review** path: Review Mode on a submitted draft; applying accepted atoms publishes them and closes the review (a partial apply finalizes the review; see the README).
- A **Reviews** dashboard in the CP nav (awaiting your verdict / your submissions / all for admins), and a **Workflow** status column on entry index pages (In review / Approved / Approved — scheduled / Declined / Withdrawn / Published).
- **Review comments**: general or diff-atom-anchored, with one level of replies, resolve/unresolve, and automatic outdated detection against the live diff. Posting a comment notifies the other side (author ↔ reviewers), debounced so a burst sends at most one email per recipient per review (`workflow/comment`, `workflow/resolve-comment`, `workflow/thread`).
- Three **general** (section-agnostic) permissions under *Craft Delta*: **Submit drafts for review** (`craftdelta-submitDraft`), **Review submitted drafts** (`craftdelta-reviewDraft`), and **Apply review-mode changes** (`craftdelta-applyReview`). Which sections a user can act on is governed by Craft's native section permissions; the reviewer picker lists only users who can view peer drafts in the draft's section.
- **Email notifications** on every transition (submitted, approved, declined, published, scheduled-publish, and new comment), each sent in the recipient's preferred language. The five templates are **overridable** from `config/craft-delta.php` (`emailTemplates`) — map any of `submitted` / `approved` / `declined` / `published` / `comment` to your own site template; an unmapped or missing template falls back to the bundled default.
- `WorkflowService` events for third-party integration: `EVENT_AFTER_SUBMIT`, `EVENT_AFTER_APPROVE`, `EVENT_AFTER_DECLINE`, `EVENT_AFTER_WITHDRAW`, `EVENT_AFTER_PUBLISH` — each carrying the `Review` model as `$event->review`.
- `Enable Workflow` setting (default on) as a kill switch covering both the UI and the endpoints.

**Other**

- `MatrixDiffer` emits each change's canonical `blockUid` so atom keys round-trip across canonical / draft / revision.
- Translations across all 8 supported locales (English, German, Spanish, French, Italian, Dutch, Polish, Portuguese).
- A bundled `config.php` documenting every setting (all overridable per-environment via `config/craft-delta.php`) and the variables each email template receives.
- PHPUnit coverage for atom parsing, validation, the Matrix merge/order algorithm, the workflow state machine, and the approval policy.

### Changed

- Schema version is `2.1.3`. The upgrade migrations create three tables: `craftdelta_reviews`, `craftdelta_review_reviewers`, and `craftdelta_review_comments`. Reviews survive publication as audit records (`reviews.draftId` is nulled when the draft is applied and deleted).
- Applying changes (Review Mode or the workflow's Granular review) requires the dedicated **Apply review-mode changes** (`craftdelta-applyReview`) permission; edit/save permissions alone are not sufficient. Admins have it implicitly.
- The full-page compare view moved to the `delta-compare` CP URL so it no longer requires the *Access Craft Delta* plugin permission (the old `craft-delta/compare` URL still resolves).

### Security

- **Publishing an approved review requires the `craftdelta-applyReview` permission**, matching the granular Review-Mode apply path — native entry save rights alone are no longer sufficient. Verified: a user with section save rights but without `applyReview` is rejected (403) at `workflow/publish`; `applyReview` holders and admins are unaffected.

### Fixed

- A reply no longer shows a "Reply" affordance (the data model supports only one level of replies), which previously dead-ended in a generic "could not be saved" error.
- Closed/terminal reviews (published, declined, cancelled) render comments **read-only** — the composer, reply, and add-comment affordances are hidden, since the backend rejects posts on an inactive review.
- **Review UI polish.** Non-primary buttons no longer use the CP accent color — "Approve" reads as green and "Decline" as red, rather than the install's accent. "Submit comment" is a quiet right-aligned button rather than a full-width CTA. Zero-change reviews no longer render an "Apply 0 accepted" toolbar. The reviewer picker is a styled checkbox list instead of a raw native multi-select. Scheduling uses a native date + time picker instead of a free-text prompt.

### Compatibility

- No breaking changes for users without workflow permissions — the v1.x read-only diff slideout and **Compare Revisions** button behave identically.

### Upgrading from a 2.0.0 pre-release

- The pre-release **"Request changes" verdict and the re-request / review-rounds loop are gone.** Reviewers now **Approve** or **Decline** only; requests for changes are expressed as comments. An in-flight `changes_requested` review is reopened by the schema `2.1.3` migration (→ `open`, reviewer verdicts reset to pending) so existing rows aren't stranded.
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
