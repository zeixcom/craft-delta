# Changelog

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
