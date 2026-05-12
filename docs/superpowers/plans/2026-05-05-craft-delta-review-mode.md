# Craft Delta Review Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add inline accept/reject and deferred-apply review mode to the existing Craft Delta diff slideout, producing a new draft of the canonical entry from the user's selected changes.

**Architecture:** Three new pieces layered on top of the existing read-only diff: (1) a `MergeService` owning all write logic, (2) an `actionApply` endpoint on `DiffController`, (3) a `reviewMode` controller object inside the existing `delta.js`. `DiffService`, all differs, and the existing slideout flows are untouched.

**Tech Stack:** PHP 8.2+, Craft CMS 5.8+, vanilla JS (hand-written, no build pipeline), Twig templates, PHPUnit for unit tests, browser localStorage for review-state persistence.

**Branch:** `feature/review-mode` (already created). **Commits stay on this branch.** Do not push to `origin` and do not merge to `main` without explicit user approval.

**Spec reference:** `docs/superpowers/specs/2026-05-05-craft-delta-review-mode-design.md`

---

## File map

**New files:**
- `src/services/MergeService.php` — merge logic (atom validation, field/Matrix apply, draft save)
- `tests/Unit/Service/MergeServiceTest.php` — pure-data unit tests (no Craft kernel needed)

**Modified files:**
- `src/Delta.php` — register `merge` service component
- `src/models/Settings.php` — add `enableReviewMode` property
- `src/templates/settings.twig` — kill-switch toggle
- `src/controllers/DiffController.php` — add `actionApply` method
- `src/templates/_diff-slideout.twig` — Start Review button, stepper bar, apply CTA, reviewMode flag plumbing
- `src/templates/_field-diff.twig` — `data-atom-id` attribute, Accept/Reject button markup
- `src/templates/_diff-content.twig` — `data-atom-id` on Matrix block wrappers, Accept/Reject markup
- `src/assets/diff/dist/js/delta.js` — `reviewMode` controller (~200-250 lines added)
- `src/assets/diff/dist/css/delta.css` — atom state styles, stepper bar, button styles
- `src/translations/{en,de,fr,es,it,nl,pt,pl}/craft-delta.php` — new strings
- `README.md` — Features / Usage / Settings updates

---

## Task 1: Add `enableReviewMode` setting

**Goal:** Foundation kill switch. Plugin behaves as today when the setting is off.

**Files:**
- Modify: `src/models/Settings.php`
- Modify: `src/templates/settings.twig`

- [ ] **Step 1: Add the property to Settings**

In `src/models/Settings.php`, after the existing `$defaultShowUnchanged` property (around line 27):

```php
    /**
     * Enable review mode UI (Start Review button, accept/reject, apply).
     * When false, the plugin behaves as a pure read-only diff tool.
     */
    public bool $enableReviewMode = true;
```

- [ ] **Step 2: Add the toggle to the settings template**

In `src/templates/settings.twig`, append a new `lightswitchField` block matching the existing pattern. (Read the file first to mirror the existing layout — settings use Craft CP form macros.)

```twig
{{ forms.lightswitchField({
    label: 'Enable Review Mode'|t('craft-delta'),
    instructions: 'Show the "Start Review" button on the diff slideout and allow accepting/rejecting changes into a new draft.'|t('craft-delta'),
    id: 'enableReviewMode',
    name: 'enableReviewMode',
    on: settings.enableReviewMode,
}) }}
```

- [ ] **Step 3: Manually verify the settings page renders**

Run the Craft dev server (or open the existing one), navigate to **Settings → Plugins → Craft Delta**, confirm the new toggle appears, save, reload, confirm the value persists.

- [ ] **Step 4: Commit**

```bash
git add src/models/Settings.php src/templates/settings.twig
git commit -m "feat(review-mode): add enableReviewMode kill-switch setting"
```

---

## Task 2: Stub `MergeService` and register on `Delta.php`

**Goal:** Service exists, is registered, returns nothing meaningful yet — locks in the wiring before logic.

**Files:**
- Create: `src/services/MergeService.php`
- Modify: `src/Delta.php`

- [ ] **Step 1: Create the service skeleton**

Create `src/services/MergeService.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use craft\base\Component;
use craft\elements\Entry;

/**
 * Owns the write side of review mode: validates accepted atoms against a
 * fresh diff, copies field/Matrix values from source to a new draft of the
 * canonical entry, saves once.
 *
 * Pure write-side. Shares no mutable state with DiffService.
 */
class MergeService extends Component
{
    /**
     * Apply the user's accepted atoms onto a new draft of the canonical entry.
     *
     * @param string[] $acceptedAtoms List of stable atom keys (see spec §5.1)
     * @return Entry The newly saved draft
     */
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms): Entry
    {
        // TODO: implemented across Tasks 3-9.
        throw new \LogicException('MergeService::merge not implemented yet.');
    }
}
```

- [ ] **Step 2: Register the service in `Delta.php`**

In `src/Delta.php`:

1. Add the import alongside the existing service imports (after line 20):

```php
use zeixcom\craftdelta\services\MergeService;
```

2. Update the `@property-read` block (line 25-27) to add:

```php
 * @property-read MergeService $merge
```

3. Update the `config()` array (lines 36-43) to register the component:

```php
public static function config(): array
{
    return [
        'components' => [
            'diff' => DiffService::class,
            'fieldDiff' => FieldDiffService::class,
            'revision' => RevisionService::class,
            'merge' => MergeService::class,
        ],
    ];
}
```

- [ ] **Step 3: Verify wiring loads without errors**

Run any existing PHP entry point (e.g. `php craft plugin/list`). Expected: no errors. The class is autoloaded and the component is registered.

- [ ] **Step 4: Commit**

```bash
git add src/services/MergeService.php src/Delta.php
git commit -m "feat(review-mode): stub MergeService and register on plugin"
```

---

## Task 3: Atom key parsing (TDD)

**Goal:** Pure-data parsing of atom keys into structured arrays. Foundation for validation and apply.

**Files:**
- Create: `tests/Unit/Service/MergeServiceTest.php`
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Write failing tests for atom key parsing**

Create `tests/Unit/Service/MergeServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\services\MergeService;

class MergeServiceTest extends TestCase
{
    public function testParseFieldAtom(): void
    {
        $parsed = MergeService::parseAtomKey('field:title');

        $this->assertSame('field', $parsed['kind']);
        $this->assertSame('title', $parsed['handle']);
    }

    public function testParseMatrixBlockAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-block:blocks:8a3f-1234:added');

        $this->assertSame('matrix-block', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
        $this->assertSame('8a3f-1234', $parsed['blockUid']);
        $this->assertSame('added', $parsed['changeType']);
    }

    public function testParseMatrixReorderAtom(): void
    {
        $parsed = MergeService::parseAtomKey('matrix-reorder:blocks');

        $this->assertSame('matrix-reorder', $parsed['kind']);
        $this->assertSame('blocks', $parsed['fieldHandle']);
    }

    public function testRejectsMalformedAtom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('bogus:thing');
    }

    public function testRejectsUnknownChangeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('matrix-block:blocks:abc:exploded');
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MergeService::parseAtomKey('');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd plugins/craft-delta
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 6 failures, all citing `parseAtomKey` not found.

- [ ] **Step 3: Implement `parseAtomKey`**

In `src/services/MergeService.php`, add the static method:

```php
    /**
     * Parse a stable atom key into a structured array.
     *
     * @return array{kind: string, ...}
     * @throws \InvalidArgumentException when the key is malformed
     */
    public static function parseAtomKey(string $key): array
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Empty atom key');
        }

        $parts = explode(':', $key);
        $kind = $parts[0];

        switch ($kind) {
            case 'field':
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed field atom: $key");
                }
                return ['kind' => 'field', 'handle' => $parts[1]];

            case 'matrix-block':
                if (count($parts) !== 4) {
                    throw new \InvalidArgumentException("Malformed matrix-block atom: $key");
                }
                $changeType = $parts[3];
                if (!in_array($changeType, ['added', 'removed', 'modified'], true)) {
                    throw new \InvalidArgumentException("Unknown change type: $changeType");
                }
                return [
                    'kind' => 'matrix-block',
                    'fieldHandle' => $parts[1],
                    'blockUid' => $parts[2],
                    'changeType' => $changeType,
                ];

            case 'matrix-reorder':
                if (count($parts) !== 2 || $parts[1] === '') {
                    throw new \InvalidArgumentException("Malformed matrix-reorder atom: $key");
                }
                return ['kind' => 'matrix-reorder', 'fieldHandle' => $parts[1]];

            default:
                throw new \InvalidArgumentException("Unknown atom kind: $kind");
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/MergeServiceTest.php src/services/MergeService.php
git commit -m "feat(review-mode): atom key parsing with full test coverage"
```

---

## Task 4: `validateAtoms()` (TDD) — stale-atom detection

**Goal:** Reject the apply request if any accepted atom doesn't correspond to a real change in a fresh diff.

**Files:**
- Modify: `tests/Unit/Service/MergeServiceTest.php`
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Write failing tests**

Append to `MergeServiceTest.php`:

```php
    public function testValidateAtomsAcceptsKnownAtoms(): void
    {
        // Set of stable keys representing the fresh diff's available atoms
        $availableAtoms = [
            'field:title',
            'matrix-block:blocks:8a3f:added',
            'matrix-reorder:blocks',
        ];

        $accepted = ['field:title', 'matrix-reorder:blocks'];

        // No exception means valid
        MergeService::validateAtoms($availableAtoms, $accepted);
        $this->addToAssertionCount(1);
    }

    public function testValidateAtomsRejectsUnknownAtom(): void
    {
        $availableAtoms = ['field:title'];
        $accepted = ['field:body'];

        $this->expectException(\zeixcom\craftdelta\services\StaleAtomException::class);
        MergeService::validateAtoms($availableAtoms, $accepted);
    }

    public function testValidateAtomsRejectsMalformedAtom(): void
    {
        $availableAtoms = ['field:title'];
        $accepted = ['malformed-key'];

        $this->expectException(\InvalidArgumentException::class);
        MergeService::validateAtoms($availableAtoms, $accepted);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 3 new failures (validateAtoms / StaleAtomException not defined).

- [ ] **Step 3: Implement `validateAtoms` + `StaleAtomException`**

Create `src/services/StaleAtomException.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

class StaleAtomException extends \RuntimeException
{
}
```

Add the method to `MergeService.php`:

```php
    /**
     * Validate that every accepted atom corresponds to a real atom in the
     * fresh diff. Malformed atoms throw InvalidArgumentException; unknown
     * atoms throw StaleAtomException.
     *
     * @param string[] $availableAtoms All atom keys present in the fresh diff
     * @param string[] $acceptedAtoms  The user's accepted atoms
     * @throws \InvalidArgumentException
     * @throws StaleAtomException
     */
    public static function validateAtoms(array $availableAtoms, array $acceptedAtoms): void
    {
        $available = array_flip($availableAtoms);

        foreach ($acceptedAtoms as $atom) {
            self::parseAtomKey($atom); // throws on malformed

            if (!isset($available[$atom])) {
                throw new StaleAtomException("Atom '$atom' is not present in the fresh diff");
            }
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 9 tests pass total.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/MergeServiceTest.php src/services/MergeService.php src/services/StaleAtomException.php
git commit -m "feat(review-mode): atom validation with stale-state detection"
```

---

## Task 5: `buildMatrixBlockList()` (TDD) — Step A of merge algorithm

**Goal:** Build the surviving block set after applying accept/reject decisions to add/remove/modify atoms. Order is handled separately in Tasks 6-7.

**Files:**
- Modify: `tests/Unit/Service/MergeServiceTest.php`
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Write table-driven failing tests**

Append to `MergeServiceTest.php`:

```php
    public function testBuildBlockListAcceptedAddedIncludesSourceBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a']];
        $source = [['uid' => 'A', 'content' => 'a'], ['uid' => 'X', 'content' => 'x']];
        $atoms = [['blockUid' => 'X', 'changeType' => 'added']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertContains('A', $uids);
        $this->assertContains('X', $uids);
    }

    public function testBuildBlockListRejectedAddedDoesNotIncludeSourceBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a']];
        $source = [['uid' => 'A', 'content' => 'a'], ['uid' => 'X', 'content' => 'x']];
        $atoms = []; // X-added not accepted

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertNotContains('X', $uids);
    }

    public function testBuildBlockListAcceptedRemovedDropsBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a'], ['uid' => 'B', 'content' => 'b']];
        $source = [['uid' => 'A', 'content' => 'a']];
        $atoms = [['blockUid' => 'B', 'changeType' => 'removed']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertNotContains('B', $uids);
    }

    public function testBuildBlockListRejectedRemovedKeepsBlock(): void
    {
        $current = [['uid' => 'A', 'content' => 'a'], ['uid' => 'B', 'content' => 'b']];
        $source = [['uid' => 'A', 'content' => 'a']];
        $atoms = []; // B-removed rejected

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $uids = array_column($result, 'uid');
        $this->assertContains('B', $uids);
    }

    public function testBuildBlockListAcceptedModifiedReplacesContent(): void
    {
        $current = [['uid' => 'A', 'content' => 'old']];
        $source = [['uid' => 'A', 'content' => 'new']];
        $atoms = [['blockUid' => 'A', 'changeType' => 'modified']];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $this->assertSame('new', $result[0]['content']);
    }

    public function testBuildBlockListRejectedModifiedKeepsCurrentContent(): void
    {
        $current = [['uid' => 'A', 'content' => 'old']];
        $source = [['uid' => 'A', 'content' => 'new']];
        $atoms = [];

        $result = MergeService::buildMatrixBlockList($current, $source, $atoms);

        $this->assertSame('old', $result[0]['content']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 6 new failures.

- [ ] **Step 3: Implement `buildMatrixBlockList`**

Add to `MergeService.php`:

```php
    /**
     * Step A of the Matrix merge: build the surviving block set, before
     * ordering. Each block is an associative array; this method is content-
     * agnostic — it operates on UIDs.
     *
     * @param array<int, array<string, mixed>> $current        Current blocks
     * @param array<int, array<string, mixed>> $source         Source blocks
     * @param array<int, array{blockUid: string, changeType: string}> $atoms Accepted block atoms for this field
     * @return array<int, array<string, mixed>>                Surviving blocks (order not yet applied)
     */
    public static function buildMatrixBlockList(array $current, array $source, array $atoms): array
    {
        $sourceByUid = [];
        foreach ($source as $block) {
            $sourceByUid[$block['uid']] = $block;
        }

        $working = [];
        foreach ($current as $block) {
            $working[$block['uid']] = $block;
        }

        foreach ($atoms as $atom) {
            $uid = $atom['blockUid'];
            switch ($atom['changeType']) {
                case 'added':
                    if (isset($sourceByUid[$uid])) {
                        $working[$uid] = $sourceByUid[$uid];
                    }
                    break;
                case 'removed':
                    unset($working[$uid]);
                    break;
                case 'modified':
                    if (isset($sourceByUid[$uid])) {
                        $working[$uid] = $sourceByUid[$uid];
                    }
                    break;
            }
        }

        return array_values($working);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 15 tests pass total.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/MergeServiceTest.php src/services/MergeService.php
git commit -m "feat(review-mode): build surviving Matrix block set"
```

---

## Task 6: `orderMatrixBlocks()` for `acceptedReorder=false` (TDD)

**Goal:** Apply the simpler ordering rule from spec §6.2 Step B (no reorder accepted): current's order is the spine; source-only added blocks go to the end.

**Files:**
- Modify: `tests/Unit/Service/MergeServiceTest.php`
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Write failing tests**

Append to `MergeServiceTest.php`:

```php
    public function testOrderNoReorderPreservesCurrentOrder(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'C'], ['uid' => 'B'], ['uid' => 'A']]; // reversed

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'uid'));
    }

    public function testOrderNoReorderAppendsSourceOnlyAddedAtEnd(): void
    {
        // Survivors include X (source-only added) — should land at end.
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'X'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B']];
        $source = [['uid' => 'X'], ['uid' => 'A'], ['uid' => 'B']]; // X is first in source

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'X'], array_column($result, 'uid'));
    }

    public function testOrderNoReorderKeepsCurrentOnlyBlocksInPlace(): void
    {
        // B is in current only (its 'removed' atom was rejected).
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'A'], ['uid' => 'C']]; // B not in source

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, false);

        $this->assertSame(['A', 'B', 'C'], array_column($result, 'uid'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 3 new failures.

- [ ] **Step 3: Implement `orderMatrixBlocks` (no-reorder branch only for now)**

Add to `MergeService.php`:

```php
    /**
     * Step B of the Matrix merge: order the surviving blocks per spec §6.2.
     *
     * @param array<int, array<string, mixed>> $survivors         Output from buildMatrixBlockList
     * @param array<int, array<string, mixed>> $current           Original current blocks (for spine)
     * @param array<int, array<string, mixed>> $source            Original source blocks (for spine)
     * @param bool $acceptedReorder                               Whether the matrix-reorder atom was accepted
     * @return array<int, array<string, mixed>>
     */
    public static function orderMatrixBlocks(array $survivors, array $current, array $source, bool $acceptedReorder): array
    {
        $survivorsByUid = [];
        foreach ($survivors as $block) {
            $survivorsByUid[$block['uid']] = $block;
        }

        if (!$acceptedReorder) {
            return self::orderByCurrentSpine($survivorsByUid, $current, $source);
        }

        // Reorder branch implemented in Task 7
        return self::orderBySourceSpine($survivorsByUid, $current, $source);
    }

    /**
     * @param array<string, array<string, mixed>> $survivorsByUid
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $source
     * @return array<int, array<string, mixed>>
     */
    private static function orderByCurrentSpine(array $survivorsByUid, array $current, array $source): array
    {
        $result = [];

        // 1. Walk current's order; emit any survivor that has the same UID.
        foreach ($current as $block) {
            $uid = $block['uid'];
            if (isset($survivorsByUid[$uid])) {
                $result[] = $survivorsByUid[$uid];
                unset($survivorsByUid[$uid]);
            }
        }

        // 2. Whatever's left is source-only (an accepted "added"). Append at end,
        //    preserving source's relative order.
        foreach ($source as $block) {
            $uid = $block['uid'];
            if (isset($survivorsByUid[$uid])) {
                $result[] = $survivorsByUid[$uid];
                unset($survivorsByUid[$uid]);
            }
        }

        return $result;
    }

    /**
     * Implemented in Task 7.
     *
     * @param array<string, array<string, mixed>> $survivorsByUid
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $source
     * @return array<int, array<string, mixed>>
     */
    private static function orderBySourceSpine(array $survivorsByUid, array $current, array $source): array
    {
        throw new \LogicException('orderBySourceSpine implemented in Task 7');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 18 tests pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/MergeServiceTest.php src/services/MergeService.php
git commit -m "feat(review-mode): order Matrix blocks under current spine (no reorder accepted)"
```

---

## Task 7: `orderMatrixBlocks()` for `acceptedReorder=true` (TDD) — anchor rule

**Goal:** Implement the harder ordering branch. Source's order is the spine; kept-current-only blocks are inserted after their most-recent both-sides anchor in current's perspective.

**Files:**
- Modify: `tests/Unit/Service/MergeServiceTest.php`
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Write failing tests including the spec's worked example**

Append to `MergeServiceTest.php`:

```php
    public function testOrderReorderUsesSourceOrderForBothSidesBlocks(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C']];
        $source = [['uid' => 'C'], ['uid' => 'B'], ['uid' => 'A']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['C', 'B', 'A'], array_column($result, 'uid'));
    }

    public function testOrderReorderInsertsSourceOnlyAddedAtSourcePosition(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'X'],
            ['uid' => 'B'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B']];
        $source = [['uid' => 'A'], ['uid' => 'X'], ['uid' => 'B']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'X', 'B'], array_column($result, 'uid'));
    }

    /**
     * Worked example from spec §6.2:
     * - Current order: A, B, C, D, E (B exists only in current)
     * - Source order: A, X, C, E, D (X is source-only; D and E reordered)
     * - User accepts: X-added, reorder. User rejects: B-removed.
     *
     * B's anchor in current is A (the most recent both-sides block before B).
     * Expected result: A, B, X, C, E, D
     */
    public function testOrderReorderAnchorRuleFromSpec(): void
    {
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B'], // current-only, kept
            ['uid' => 'C'],
            ['uid' => 'D'],
            ['uid' => 'E'],
            ['uid' => 'X'], // source-only, added
        ];
        $current = [
            ['uid' => 'A'], ['uid' => 'B'], ['uid' => 'C'], ['uid' => 'D'], ['uid' => 'E'],
        ];
        $source = [
            ['uid' => 'A'], ['uid' => 'X'], ['uid' => 'C'], ['uid' => 'E'], ['uid' => 'D'],
        ];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'B', 'X', 'C', 'E', 'D'], array_column($result, 'uid'));
    }

    public function testOrderReorderMultipleCurrentOnlyBlocksPreserveRelativeOrder(): void
    {
        // Current: A, B1, B2, C — B1 and B2 are current-only and consecutive.
        // Source: A, C — same anchor (A) for both kept blocks.
        $survivors = [
            ['uid' => 'A'],
            ['uid' => 'B1'],
            ['uid' => 'B2'],
            ['uid' => 'C'],
        ];
        $current = [['uid' => 'A'], ['uid' => 'B1'], ['uid' => 'B2'], ['uid' => 'C']];
        $source = [['uid' => 'A'], ['uid' => 'C']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        $this->assertSame(['A', 'B1', 'B2', 'C'], array_column($result, 'uid'));
    }

    public function testOrderReorderCurrentOnlyBeforeFirstAnchorGoesFirst(): void
    {
        // B is current-only and appears before any both-sides block.
        $survivors = [
            ['uid' => 'B'],
            ['uid' => 'A'],
        ];
        $current = [['uid' => 'B'], ['uid' => 'A']];
        $source = [['uid' => 'A']];

        $result = MergeService::orderMatrixBlocks($survivors, $current, $source, true);

        // No anchor before B → B goes at the very front.
        $this->assertSame(['B', 'A'], array_column($result, 'uid'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 5 new failures (currently throws "implemented in Task 7").

- [ ] **Step 3: Implement `orderBySourceSpine`**

Replace the `orderBySourceSpine` stub in `MergeService.php`:

```php
    /**
     * Order the surviving blocks under source's spine. Kept current-only blocks
     * are inserted immediately after the most-recent both-sides anchor in
     * current's order. Current-only blocks before any anchor go at the front.
     *
     * @param array<string, array<string, mixed>> $survivorsByUid
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $source
     * @return array<int, array<string, mixed>>
     */
    private static function orderBySourceSpine(array $survivorsByUid, array $current, array $source): array
    {
        // 1. Identify which surviving UIDs are in source (anchors + source-only adds).
        $sourceUids = [];
        foreach ($source as $block) {
            $sourceUids[$block['uid']] = true;
        }

        // 2. Walk current's order; for each current-only kept survivor, find its
        //    most-recent both-sides anchor (or null if none yet seen).
        //
        //    Build: anchorByCurrentOnly = ['B' => 'A', 'C' => null, ...]
        //
        //    "Both-sides" means: the UID is in source AND survives.
        $anchorByCurrentOnly = [];
        $currentOnlyOrder = [];
        $lastAnchor = null;

        foreach ($current as $block) {
            $uid = $block['uid'];
            $isSurvivor = isset($survivorsByUid[$uid]);
            $isInSource = isset($sourceUids[$uid]);

            if ($isSurvivor && $isInSource) {
                $lastAnchor = $uid;
            } elseif ($isSurvivor && !$isInSource) {
                // Current-only kept block.
                $anchorByCurrentOnly[$uid] = $lastAnchor;
                $currentOnlyOrder[] = $uid;
            }
        }

        // 3. Walk source's order, emitting source survivors. After each anchor,
        //    flush any current-only blocks that anchor to it.
        $result = [];

        // Flush current-only blocks with no anchor (lastAnchor was null) first.
        foreach ($currentOnlyOrder as $uid) {
            if ($anchorByCurrentOnly[$uid] === null) {
                $result[] = $survivorsByUid[$uid];
            }
        }

        foreach ($source as $block) {
            $uid = $block['uid'];
            if (!isset($survivorsByUid[$uid])) {
                continue; // source block didn't survive (e.g. a source-only block whose 'added' atom was rejected)
            }
            $result[] = $survivorsByUid[$uid];

            // Flush current-only blocks anchored to this UID, in their original current-order.
            foreach ($currentOnlyOrder as $coUid) {
                if ($anchorByCurrentOnly[$coUid] === $uid) {
                    $result[] = $survivorsByUid[$coUid];
                }
            }
        }

        return $result;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 23 tests pass total. **Pay particular attention to `testOrderReorderAnchorRuleFromSpec`** — the spec's worked example.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Service/MergeServiceTest.php src/services/MergeService.php
git commit -m "feat(review-mode): order Matrix blocks under source spine with anchor rule"
```

---

## Task 8: Implement `applyFieldAtoms()`

**Goal:** Copy plain-field, title, and slug values from source to draft. No new tests in this task — applying values via Craft's `setFieldValue` requires the kernel and is covered by manual QA + future integration tests.

**Files:**
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Implement `applyFieldAtoms`**

Add to `MergeService.php`:

```php
    /**
     * Apply field-kind atoms onto a draft by copying values from source.
     * Handles native attributes (title, slug) separately from field handles.
     *
     * @param string[] $fieldAtoms List of "field:<handle>" atom keys
     */
    private function applyFieldAtoms(Entry $draft, Entry $source, array $fieldAtoms): void
    {
        foreach ($fieldAtoms as $atom) {
            $parsed = self::parseAtomKey($atom);
            $handle = $parsed['handle'];

            // Native attributes (matches DiffService::compareAttributes)
            if ($handle === 'title') {
                $draft->title = $source->title;
                continue;
            }
            if ($handle === 'slug') {
                $draft->slug = $source->slug;
                continue;
            }

            // Custom field — let Craft handle serialization across all field types
            // (CKEditor, Asset, Money, etc. travel cleanly via getFieldValue/setFieldValue).
            $draft->setFieldValue($handle, $source->getFieldValue($handle));
        }
    }
```

- [ ] **Step 2: Verify the file still loads (syntax check)**

```bash
php -l src/services/MergeService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add src/services/MergeService.php
git commit -m "feat(review-mode): apply field atoms by copying source values to draft"
```

---

## Task 9: Implement `applyMatrixAtoms()` and `merge()` end-to-end

**Goal:** Wire the Matrix block-list builder, the orderer, and the field applier into the public `merge()` method. Returns a saved draft. No new unit tests in this task (kernel-dependent); manual QA covers it in Task 20.

**Files:**
- Modify: `src/services/MergeService.php`

- [ ] **Step 1: Implement `applyMatrixAtoms`**

Add to `MergeService.php`:

```php
    /**
     * Apply matrix-block and matrix-reorder atoms onto a draft for one Matrix field.
     *
     * @param array<int, array{blockUid: string, changeType: string}> $blockAtoms
     */
    private function applyMatrixAtoms(
        Entry $draft,
        Entry $source,
        string $fieldHandle,
        array $blockAtoms,
        bool $acceptedReorder,
    ): void {
        $current = $this->serializeMatrixBlocks($draft->getFieldValue($fieldHandle));
        $sourceBlocks = $this->serializeMatrixBlocks($source->getFieldValue($fieldHandle));

        $survivors = self::buildMatrixBlockList($current, $sourceBlocks, $blockAtoms);
        $ordered = self::orderMatrixBlocks($survivors, $current, $sourceBlocks, $acceptedReorder);

        // Convert back into Craft's Matrix value format. Each block is keyed by
        // its UID with the block's payload (type, fields). We rebuild the field's
        // serialized value and let setFieldValue do the rest.
        $serialized = [];
        foreach ($ordered as $block) {
            $serialized[$block['uid']] = $block['payload'];
        }

        $draft->setFieldValue($fieldHandle, $serialized);
    }

    /**
     * Serialize a Craft Matrix field value into [{uid, payload}, ...] form
     * keyed by current order. The payload is whatever Craft expects on
     * setFieldValue — for v1 that's the array shape from getSerializedFieldValues.
     *
     * @param mixed $matrixValue The result of $entry->getFieldValue($handle) for a Matrix field
     * @return array<int, array{uid: string, payload: array<string, mixed>}>
     */
    private function serializeMatrixBlocks(mixed $matrixValue): array
    {
        $result = [];
        // $matrixValue is typically a Craft\elements\db\EntryQuery (Matrix blocks
        // are entries in Craft 5). Iterating gives Block entries; each has a uid
        // and a serializeFieldValues method.
        foreach ($matrixValue as $block) {
            $result[] = [
                'uid' => $block->uid,
                'payload' => [
                    'type' => $block->type->handle,
                    'fields' => $block->getSerializedFieldValues(),
                ],
            ];
        }
        return $result;
    }
```

- [ ] **Step 2: Implement the public `merge` method**

Replace the stub `merge` method body in `MergeService.php`:

```php
    public function merge(Entry $canonical, Entry $source, array $acceptedAtoms): Entry
    {
        // 1. Re-run a fresh diff and build the available-atoms set.
        $plugin = \zeixcom\craftdelta\Delta::getInstance();
        $freshDiff = $plugin->diff->compare($canonical, $source);
        $availableAtoms = $this->collectAvailableAtoms($freshDiff);

        // 2. Validate every accepted atom is still present in the fresh diff.
        self::validateAtoms($availableAtoms, $acceptedAtoms);

        // 3. Group accepted atoms by kind / Matrix field.
        $fieldAtoms = [];
        $matrixBlockAtomsByHandle = [];
        $reorderAcceptedHandles = [];

        foreach ($acceptedAtoms as $atom) {
            $parsed = self::parseAtomKey($atom);
            switch ($parsed['kind']) {
                case 'field':
                    $fieldAtoms[] = $atom;
                    break;
                case 'matrix-block':
                    $h = $parsed['fieldHandle'];
                    $matrixBlockAtomsByHandle[$h] ??= [];
                    $matrixBlockAtomsByHandle[$h][] = [
                        'blockUid' => $parsed['blockUid'],
                        'changeType' => $parsed['changeType'],
                    ];
                    break;
                case 'matrix-reorder':
                    $reorderAcceptedHandles[$parsed['fieldHandle']] = true;
                    break;
            }
        }

        // 4. Create a draft of the canonical entry.
        $user = \Craft::$app->getUser()->getIdentity();
        $draft = \Craft::$app->getDrafts()->createDraft(
            $canonical,
            $user?->id ?? 0,
            \Craft::t('craft-delta', 'Review of {ref}', ['ref' => $this->humanRefForSource($source)]),
        );

        // 5. Apply field atoms.
        $this->applyFieldAtoms($draft, $source, $fieldAtoms);

        // 6. Apply Matrix atoms — one call per Matrix field.
        //    Include fields with reorder-only atoms (no block atoms).
        $matrixHandles = array_unique(array_merge(
            array_keys($matrixBlockAtomsByHandle),
            array_keys($reorderAcceptedHandles),
        ));
        foreach ($matrixHandles as $handle) {
            $blockAtoms = $matrixBlockAtomsByHandle[$handle] ?? [];
            $acceptedReorder = isset($reorderAcceptedHandles[$handle]);
            $this->applyMatrixAtoms($draft, $source, $handle, $blockAtoms, $acceptedReorder);
        }

        // 7. ONE save — never per field.
        if (!\Craft::$app->getElements()->saveElement($draft)) {
            $errors = $draft->getErrors();
            throw new \RuntimeException('Draft validation failed: ' . json_encode($errors));
        }

        return $draft;
    }

    /**
     * Walk the fresh DiffResult and collect the full set of atom keys it offers.
     * Mirrors the keys the client emits in data-atom-id.
     *
     * @return string[]
     */
    private function collectAvailableAtoms(\zeixcom\craftdelta\models\DiffResult $diff): array
    {
        $atoms = [];

        foreach ($diff->fieldDiffs as $fd) {
            if (!$fd->hasChanges) {
                continue;
            }

            $isMatrix = str_contains($fd->fieldType, '\\Matrix');
            if (!$isMatrix) {
                $atoms[] = 'field:' . $fd->fieldHandle;
                continue;
            }

            // Matrix field — diffHtml is JSON describing block changes.
            $changes = json_decode($fd->diffHtml, true);
            if (!is_array($changes)) {
                continue;
            }

            $hasReorder = false;
            foreach ($changes as $change) {
                $type = $change['type'] ?? null;
                if ($type === 'reordered') {
                    $hasReorder = true;
                    continue;
                }
                if (in_array($type, ['added', 'removed', 'modified'], true)
                    && !empty($change['blockUid'])
                ) {
                    $atoms[] = 'matrix-block:' . $fd->fieldHandle . ':' . $change['blockUid'] . ':' . $type;
                }
            }

            if ($hasReorder) {
                $atoms[] = 'matrix-reorder:' . $fd->fieldHandle;
            }
        }

        return $atoms;
    }

    private function humanRefForSource(Entry $source): string
    {
        if ($source->revisionNum !== null) {
            return 'Rev ' . $source->revisionNum;
        }
        $behavior = $source->getBehavior('draft');
        if ($behavior !== null) {
            return $behavior->draftName ?? 'Draft';
        }
        return 'Source';
    }
```

- [ ] **Step 2.5: Verify `MatrixDiffer` emits `blockUid` per change (prerequisite for Task 11 too)**

`collectAvailableAtoms()` consumes `change.blockUid` from the JSON `MatrixDiffer` produces. Confirm the existing differ already emits it:

```bash
grep -nE "blockUid|'uid' =>" src/differ/MatrixDiffer.php
```

If the differ does **not** emit a `blockUid` (or equivalent) on each change, extend it: at every place the differ pushes a change array (added / removed / modified / reordered), add `'blockUid' => $block->uid` (or the equivalent property holding the Matrix block's UID). Re-run the existing slideout against an entry with Matrix changes and inspect the network response — confirm each change has `blockUid` populated with a non-empty string.

If `MatrixDiffer.php` was modified, include it in this task's commit. Otherwise omit it.

- [ ] **Step 3: Verify file still parses**

```bash
php -l src/services/MergeService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Re-run all unit tests to make sure refactors didn't break earlier tests**

```bash
./vendor/bin/phpunit tests/Unit/Service/MergeServiceTest.php
```

Expected: 23 tests still pass.

- [ ] **Step 5: Commit**

```bash
git add src/services/MergeService.php src/differ/MatrixDiffer.php
git commit -m "feat(review-mode): wire merge() end-to-end with Matrix and field atoms"
```

(If `MatrixDiffer` was unchanged, omit it from `git add`.)

---

## Task 10: `DiffController::actionApply` endpoint

**Goal:** HTTP entry point for applying accepted atoms. Translates between the wire format and `MergeService::merge`. Maps exceptions to error codes.

**Files:**
- Modify: `src/controllers/DiffController.php`

- [ ] **Step 1: Add the `actionApply` method**

In `src/controllers/DiffController.php`, append a new method:

```php
    /**
     * Apply accepted review-mode atoms to a new draft of the canonical entry.
     */
    public function actionApply(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getRequiredBodyParam('entryId');
        $sourceRef = (string)$request->getRequiredBodyParam('sourceRef');
        $siteId = $request->getBodyParam('siteId') ? (int)$request->getBodyParam('siteId') : null;
        $acceptedAtoms = $request->getBodyParam('acceptedAtoms');

        if (!is_array($acceptedAtoms) || count($acceptedAtoms) === 0) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'no-changes',
                'error' => Craft::t('craft-delta', 'No changes to apply.'),
            ])->setStatusCode(422);
        }

        $plugin = Delta::getInstance();

        $canonical = $plugin->revision->getCanonical($entryId, $siteId);
        if (!$canonical instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', 'Entry not found.'),
            ])->setStatusCode(422);
        }

        $this->requireEntryAccess($canonical);

        // Permission: user must be able to create drafts on this section.
        $user = Craft::$app->getUser()->getIdentity();
        $section = $canonical->getSection();
        if (!$user || !$section || !$user->can("createEntryDrafts:{$section->uid}")) {
            throw new ForbiddenHttpException('Insufficient permissions to create a draft on this section.');
        }

        $source = $this->resolveVersion($sourceRef, $canonical, $siteId);
        if (!$source instanceof Entry) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'source-not-found',
                'error' => Craft::t('craft-delta', 'Source version not found.'),
            ])->setStatusCode(422);
        }

        try {
            $draft = $plugin->merge->merge($canonical, $source, $acceptedAtoms);

            return $this->asJson([
                'success' => true,
                'draftId' => $draft->draftId,
                'draftEditUrl' => $draft->getCpEditUrl(),
            ]);
        } catch (\zeixcom\craftdelta\services\StaleAtomException $e) {
            return $this->asJson([
                'success' => false,
                'errorCode' => 'stale-atoms',
                'error' => Craft::t('craft-delta', 'The entry has changed since you started reviewing. Please reload the diff and restart your review.'),
            ])->setStatusCode(422);
        } catch (\InvalidArgumentException $e) {
            Craft::warning("Apply rejected malformed atom: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'errorCode' => 'stale-atoms',
                'error' => Craft::t('craft-delta', 'The entry has changed since you started reviewing. Please reload the diff and restart your review.'),
            ])->setStatusCode(422);
        } catch (\Throwable $e) {
            Craft::error("Apply failed: {$e->getMessage()}", __METHOD__);
            return $this->asJson([
                'success' => false,
                'errorCode' => 'validation-failed',
                'error' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }
```

- [ ] **Step 2: Verify the controller still parses**

```bash
php -l src/controllers/DiffController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Manual smoke test via cURL**

With Craft running, hit the endpoint with an empty atom list to confirm it returns the no-changes error:

```bash
curl -sS -X POST \
     -H "Accept: application/json" \
     -H "X-Requested-With: XMLHttpRequest" \
     -H "Content-Type: application/json" \
     -b "<auth cookies>" \
     --data '{"entryId":1,"sourceRef":"current","siteId":1,"acceptedAtoms":[]}' \
     'https://your-craft.local/admin/actions/craft-delta/diff/apply'
```

Expected: `{"success":false,"errorCode":"no-changes",...}` with HTTP 422.

(If Craft auth makes this awkward, skip and rely on Task 16's UI smoke test.)

- [ ] **Step 4: Commit**

```bash
git add src/controllers/DiffController.php
git commit -m "feat(review-mode): add actionApply endpoint with error-code mapping"
```

---

## Task 11: Templates — add `data-atom-id` attributes (no behavior)

**Goal:** Mark up the existing diff DOM so JS can target accept/reject atoms. No styling, no buttons yet — purely additive markup. Diff continues to render as today.

**Files:**
- Modify: `src/templates/_field-diff.twig`
- Modify: `src/templates/_diff-content.twig`

(Task 9 Step 2.5 already verified `MatrixDiffer` emits `blockUid`. By the time you reach this task, that should be in place.)

- [ ] **Step 1: Add `data-atom-id` to the field-level wrapper**

In `src/templates/_field-diff.twig` line 3, change:

```twig
<div class="delta-field{{ not diff.hasChanges ? ' delta-field-unchanged' : '' }}" data-field-handle="{{ diff.fieldHandle }}">
```

to:

```twig
<div class="delta-field{{ not diff.hasChanges ? ' delta-field-unchanged' : '' }}"
     data-field-handle="{{ diff.fieldHandle }}"
     {% if diff.hasChanges and not (diff.fieldType ends with '\\Matrix') %}data-atom-id="field:{{ diff.fieldHandle }}"{% endif %}>
```

(Matrix fields don't get a top-level `field:` atom — their atoms are at the block level.)

- [ ] **Step 2: Add `data-atom-id` to Matrix block wrappers**

In `src/templates/_diff-content.twig`, the Matrix branch around line 16, change:

```twig
<div class="delta-block {{ blockClass[change.type] }}">
```

to:

```twig
<div class="delta-block {{ blockClass[change.type] }}"
     data-atom-id="matrix-block:{{ diff.fieldHandle }}:{{ change.blockUid }}:{{ change.type }}">
```

> **Note:** This requires `diff.fieldHandle` to be available in the partial. It's currently included via `_field-diff.twig` with a context that doesn't pass the handle in. Update the include to pass it. In `_field-diff.twig` line 23-26, change:

```twig
{% include 'craft-delta/_diff-content' with {
    diffHtml: diff.diffHtml,
    fieldType: diff.fieldType
} only %}
```

to:

```twig
{% include 'craft-delta/_diff-content' with {
    diffHtml: diff.diffHtml,
    fieldType: diff.fieldType,
    fieldHandle: diff.fieldHandle
} only %}
```

Inside `_diff-content.twig`, also forward `fieldHandle` to recursive includes (Matrix sub-fields, line 31-34):

```twig
{% include 'craft-delta/_diff-content' with {
    diffHtml: fc.diffHtml,
    fieldType: fc.fieldType ?? '',
    fieldHandle: fieldHandle
} only %}
```

- [ ] **Step 3: Add a `matrix-reorder` atom marker**

In `_diff-content.twig` line 46-49 (the reordered branch), change:

```twig
<div class="delta-block delta-block-reordered">
```

to:

```twig
<div class="delta-block delta-block-reordered" data-atom-id="matrix-reorder:{{ fieldHandle }}">
```

- [ ] **Step 4: Manually verify the diff still renders**

Open the slideout on any entry with at least two revisions. Inspect the DOM — confirm `data-atom-id` attributes appear on changed field wrappers, Matrix block wrappers, and reorder rows. Confirm no visual or functional regression.

- [ ] **Step 5: Commit**

```bash
git add src/templates/_field-diff.twig src/templates/_diff-content.twig
git commit -m "feat(review-mode): emit data-atom-id attributes on diff wrappers"
```

---

## Task 12: Templates — review-mode UI markup

**Goal:** Add the Start Review button, the stepper bar, the Apply CTA, and the per-atom Accept/Reject button markup. All gated on a `reviewMode` flag passed from the controller. No JS behavior wired yet — that's Tasks 14-17.

**Files:**
- Modify: `src/controllers/DiffController.php`
- Modify: `src/templates/_diff-slideout.twig`
- Modify: `src/templates/_field-diff.twig`
- Modify: `src/templates/_diff-content.twig`

- [ ] **Step 1: Pass `reviewMode` flag from `actionCompare`**

In `src/controllers/DiffController.php` `actionCompare` method, around line 78-83:

```php
            $result = $plugin->diff->compare($older, $newer);

            // Review mode is available when one side is canonical AND the setting is on.
            $settings = $plugin->getSettings();
            $reviewMode = $settings->enableReviewMode
                && ($older->id === $canonical->id || $newer->id === $canonical->id);

            $html = Craft::$app->getView()->renderTemplate(
                'craft-delta/_diff-slideout',
                [
                    'result' => $result,
                    'reviewMode' => $reviewMode,
                    'canonicalSide' => $newer->id === $canonical->id ? 'newer' : 'older',
                    'sourceRef' => $newer->id === $canonical->id ? $olderRef : $newerRef,
                    'entryId' => $entryId,
                    'siteId' => $siteId ?? $canonical->siteId,
                ],
            );
```

- [ ] **Step 2: Add Start Review button + stepper bar + Apply CTA to `_diff-slideout.twig`**

At the top of `src/templates/_diff-slideout.twig`, after line 19 (after the stats div), add:

```twig
{% if reviewMode is defined and reviewMode %}
    <div class="delta-review-toolbar"
         data-review-toolbar
         data-entry-id="{{ entryId }}"
         data-site-id="{{ siteId }}"
         data-source-ref="{{ sourceRef }}">
        <button type="button" class="btn delta-review-start" data-action="start-review">
            {{ 'Start Review'|t('craft-delta') }}
        </button>
    </div>

    <div class="delta-review-stepper" data-review-stepper hidden>
        <span class="delta-review-progress" data-review-progress>
            {{ '{decided} of {total} decided'|t('craft-delta', { decided: 0, total: 0 }) }}
        </span>
        <button type="button" class="btn" data-action="prev-change">
            ← {{ 'Prev'|t('craft-delta') }}
        </button>
        <button type="button" class="btn" data-action="next-change">
            {{ 'Next'|t('craft-delta') }} →
        </button>
        <button type="button" class="btn submit" data-action="apply" disabled>
            {{ 'Apply {count} accepted'|t('craft-delta', { count: 0 }) }}
        </button>
        <button type="button" class="btn delta-review-cancel" data-action="cancel-review">
            {{ 'Cancel review'|t('craft-delta') }}
        </button>
    </div>

    <div class="delta-review-banner" data-review-banner hidden></div>
{% endif %}
```

- [ ] **Step 3: Add Accept/Reject button markup to `_field-diff.twig`**

In `src/templates/_field-diff.twig` after line 14 (after the field-type span, inside the header button), this won't work because it's inside a button element. Instead, append the buttons after the `</button>` tag (before the body div). Around line 16 — change the structure to:

```twig
<div class="delta-field{{ not diff.hasChanges ? ' delta-field-unchanged' : '' }}"
     data-field-handle="{{ diff.fieldHandle }}"
     {% if diff.hasChanges and not (diff.fieldType ends with '\\Matrix') %}data-atom-id="field:{{ diff.fieldHandle }}"{% endif %}>
    <div class="delta-field-headerbar">
        <button class="delta-field-header" type="button" aria-expanded="true" aria-controls="delta-field-{{ diff.fieldHandle }}">
            <svg class="delta-field-chevron" viewBox="0 0 16 16" fill="currentColor">
                <path d="M4.427 7.427l3.396 3.396a.25.25 0 00.354 0l3.396-3.396A.25.25 0 0011.396 7H4.604a.25.25 0 00-.177.427z"/>
            </svg>
            <span class="delta-field-label">{{ diff.fieldLabel }}</span>
            {% if diff.fieldType != 'attribute' %}
                <span class="delta-field-type">{{ diff.fieldType|split('\\')|last }}</span>
            {% endif %}
        </button>
        {% if diff.hasChanges and not (diff.fieldType ends with '\\Matrix') and reviewMode is defined and reviewMode %}
            <span class="delta-atom-actions" hidden data-atom-actions>
                <button type="button" class="btn small delta-atom-accept" data-action="accept">
                    ✓ {{ 'Accept'|t('craft-delta') }}
                </button>
                <button type="button" class="btn small delta-atom-reject" data-action="reject">
                    ✗ {{ 'Reject'|t('craft-delta') }}
                </button>
            </span>
        {% endif %}
    </div>
    <div class="delta-field-body" id="delta-field-{{ diff.fieldHandle }}">
        {# rest unchanged #}
        ...
    </div>
</div>
```

The buttons start with `hidden` — JS removes the `hidden` attribute when review mode is entered. Same pattern keeps the markup consistent across reviewMode and non-reviewMode renders (markup always present, visibility toggled by JS).

`reviewMode` needs to be propagated into this include from the slideout. In `_diff-slideout.twig` lines 47-49 and 58-60, change the includes to pass it:

```twig
{% include 'craft-delta/_field-diff' with { diff: diff, reviewMode: reviewMode ?? false } %}
```

- [ ] **Step 4: Add Accept/Reject markup to Matrix blocks and reorder in `_diff-content.twig`**

In the Matrix branch of `_diff-content.twig`, after the existing block content but inside the wrapper div (around line 44, before `</div>`), add:

```twig
{% if reviewMode is defined and reviewMode %}
    <span class="delta-atom-actions" hidden data-atom-actions>
        <button type="button" class="btn small delta-atom-accept" data-action="accept">✓</button>
        <button type="button" class="btn small delta-atom-reject" data-action="reject">✗</button>
    </span>
{% endif %}
```

Same for the reorder branch (line 46-49) — add the same `<span>` block inside the wrapper.

For this to work, propagate `reviewMode` through the `_diff-content.twig` include parameters in `_field-diff.twig` (Step 2 of Task 11 already added a richer parameter set; extend it):

```twig
{% include 'craft-delta/_diff-content' with {
    diffHtml: diff.diffHtml,
    fieldType: diff.fieldType,
    fieldHandle: diff.fieldHandle,
    reviewMode: reviewMode ?? false
} only %}
```

And forward it in the recursive include inside `_diff-content.twig`:

```twig
{% include 'craft-delta/_diff-content' with {
    diffHtml: fc.diffHtml,
    fieldType: fc.fieldType ?? '',
    fieldHandle: fieldHandle,
    reviewMode: reviewMode ?? false
} only %}
```

- [ ] **Step 5: Manually verify**

Open the slideout against an entry where one side is `current`. Confirm:
- The Start Review button appears in the toolbar.
- The stepper bar exists in the DOM (hidden via the `hidden` attribute).
- The Accept/Reject button markup exists on each changed field wrapper (hidden).

When neither side is `current`, the toolbar should be absent.

- [ ] **Step 6: Commit**

```bash
git add src/controllers/DiffController.php src/templates/_diff-slideout.twig src/templates/_field-diff.twig src/templates/_diff-content.twig
git commit -m "feat(review-mode): add review-mode UI markup gated on reviewMode flag"
```

---

## Task 13: CSS — atom states, stepper bar, button styles

**Goal:** Style the new markup. No new behavior.

**Files:**
- Modify: `src/assets/diff/dist/css/delta.css`

- [ ] **Step 1: Append the new styles**

Add to the end of `src/assets/diff/dist/css/delta.css`:

```css
/* Review-mode toolbar */
.delta-review-toolbar {
    display: flex;
    justify-content: flex-end;
    padding: 8px 16px;
    border-bottom: 1px solid var(--hairline-color, #ddd);
    background: var(--gray-050, #f9f9f9);
}

/* Stepper bar (sticky top) */
.delta-review-stepper {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 8px 16px;
    background: var(--gray-050, #f9f9f9);
    border-bottom: 1px solid var(--hairline-color, #ddd);
}

.delta-review-stepper[hidden] {
    display: none !important;
}

.delta-review-progress {
    margin-right: auto;
    font-size: 13px;
    color: var(--medium-text-color, #5d6f7d);
}

/* Resume / stale banner */
.delta-review-banner {
    padding: 12px 16px;
    background: #fff8d6;
    border-bottom: 1px solid #e5d59a;
    font-size: 13px;
}

.delta-review-banner[hidden] { display: none !important; }

.delta-review-banner button {
    margin-left: 8px;
}

/* Field-header bar (now hosts header button + atom actions side by side) */
.delta-field-headerbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-right: 8px;
}

.delta-field-headerbar > .delta-field-header {
    flex: 1 1 auto;
}

/* Per-atom actions */
.delta-atom-actions {
    display: inline-flex;
    gap: 4px;
}

.delta-atom-actions[hidden] { display: none !important; }

.delta-atom-accept,
.delta-atom-reject {
    padding: 2px 8px;
    font-size: 12px;
    line-height: 1.4;
}

/* Atom decision states (applied by JS to data-atom-id wrappers) */
[data-atom-id].delta-atom-state-accepted {
    border-left: 3px solid #2ec273;
    background: rgba(46, 194, 115, 0.06);
}

[data-atom-id].delta-atom-state-rejected {
    border-left: 3px solid #d84a4a;
    background: rgba(216, 74, 74, 0.06);
}

[data-atom-id].delta-atom-state-rejected .delta-field-body,
[data-atom-id].delta-atom-state-rejected .delta-block-subfields {
    text-decoration: line-through;
    opacity: 0.7;
}

/* Stepper focus ring */
[data-atom-id].delta-atom-stepper-focus {
    outline: 2px solid #1a8eff;
    outline-offset: 2px;
}

/* Pending state: subtle dotted border */
[data-atom-id].delta-atom-state-pending {
    border-left: 3px dotted #aaa;
}
```

- [ ] **Step 2: Manually verify**

Reload the slideout. Confirm the toolbar and stepper bar are visually styled. Manually toggle a `data-atom-id` element's class to `delta-atom-state-accepted` in DevTools to confirm the green border appears.

- [ ] **Step 3: Commit**

```bash
git add src/assets/diff/dist/css/delta.css
git commit -m "feat(review-mode): style toolbar, stepper bar, and atom states"
```

---

## Task 14: JS — review-mode controller foundation

**Goal:** Implement the in-memory atom-state map, button delegation (accept/reject toggling), and debounced localStorage save/load. Stepper navigation, apply, and resume come in Tasks 15-17.

**Files:**
- Modify: `src/assets/diff/dist/js/delta.js`

- [ ] **Step 1: Add the `reviewMode` controller**

Append to `src/assets/diff/dist/js/delta.js` (inside the IIFE, before the closing `})();`):

```javascript
  /**
   * Review mode — accept/reject decisions on diff atoms, deferred apply
   * via POST to actionApply. State is mirrored to localStorage per
   * (userId, entryId, siteId, sourceRef).
   */
  Craft.Delta.reviewMode = {
    active: false,
    state: Object.create(null),         // atomId → 'accepted' | 'rejected'
    storageKey: null,                   // computed when entering review mode
    canonicalUpdatedAt: null,
    saveTimer: null,

    enter: function (toolbar) {
      const entryId = toolbar.dataset.entryId;
      const siteId = toolbar.dataset.siteId;
      const sourceRef = toolbar.dataset.sourceRef;
      const userId = (Craft.userId || '0');

      this.storageKey = 'craftdelta:review:' + userId + ':' + entryId + ':' + siteId + ':' + sourceRef;
      this.canonicalUpdatedAt = toolbar.dataset.canonicalUpdatedAt || null;
      this.active = true;
      this.state = Object.create(null);

      this.loadFromStorage();
      this.showStepper();
      this.showAllAtomActions();
      this.refreshUiFromState();
      this.bindEvents();
    },

    exit: function () {
      this.active = false;
      this.state = Object.create(null);
      this.hideStepper();
      this.hideAllAtomActions();
      this.clearAtomStateClasses();
    },

    recordDecision: function (atomId, decision) {
      if (!this.active) return;

      // Toggle off if same button pressed twice
      if (this.state[atomId] === decision) {
        delete this.state[atomId];
      } else {
        this.state[atomId] = decision;
      }

      this.refreshAtomUi(atomId);
      this.refreshProgress();
      this.scheduleSave();
    },

    showStepper: function () {
      const stepper = document.querySelector('[data-review-stepper]');
      if (stepper) stepper.removeAttribute('hidden');
    },
    hideStepper: function () {
      const stepper = document.querySelector('[data-review-stepper]');
      if (stepper) stepper.setAttribute('hidden', '');
    },
    showAllAtomActions: function () {
      document.querySelectorAll('[data-atom-actions]').forEach(function (el) {
        el.removeAttribute('hidden');
      });
    },
    hideAllAtomActions: function () {
      document.querySelectorAll('[data-atom-actions]').forEach(function (el) {
        el.setAttribute('hidden', '');
      });
    },

    refreshAtomUi: function (atomId) {
      const wrapper = document.querySelector('[data-atom-id="' + cssEscape(atomId) + '"]');
      if (!wrapper) return;
      wrapper.classList.remove('delta-atom-state-accepted', 'delta-atom-state-rejected', 'delta-atom-state-pending');
      const decision = this.state[atomId];
      if (decision === 'accepted') {
        wrapper.classList.add('delta-atom-state-accepted');
      } else if (decision === 'rejected') {
        wrapper.classList.add('delta-atom-state-rejected');
      } else {
        wrapper.classList.add('delta-atom-state-pending');
      }
    },

    clearAtomStateClasses: function () {
      document.querySelectorAll('[data-atom-id]').forEach(function (el) {
        el.classList.remove(
          'delta-atom-state-accepted',
          'delta-atom-state-rejected',
          'delta-atom-state-pending',
          'delta-atom-stepper-focus'
        );
      });
    },

    refreshUiFromState: function () {
      const self = this;
      document.querySelectorAll('[data-atom-id]').forEach(function (el) {
        self.refreshAtomUi(el.dataset.atomId);
      });
      this.refreshProgress();
    },

    refreshProgress: function () {
      const total = document.querySelectorAll('[data-atom-id]').length;
      const decided = Object.keys(this.state).length;
      const accepted = Object.values(this.state).filter(function (v) { return v === 'accepted'; }).length;

      const progressEl = document.querySelector('[data-review-progress]');
      if (progressEl) {
        progressEl.textContent = decided + ' of ' + total + ' decided';
      }

      const applyBtn = document.querySelector('[data-action="apply"]');
      if (applyBtn) {
        applyBtn.textContent = 'Apply ' + accepted + ' accepted';
        applyBtn.disabled = accepted === 0;
      }
    },

    bindEvents: function () {
      const self = this;
      const root = document.querySelector('.delta-slideout, .delta-modal-content, .delta-fullpage-root') || document.body;

      // One delegated click handler covers all per-atom buttons + stepper actions
      root.addEventListener('click', function (e) {
        const actionEl = e.target.closest('[data-action]');
        if (!actionEl) return;

        const action = actionEl.dataset.action;

        if (action === 'accept' || action === 'reject') {
          const wrapper = actionEl.closest('[data-atom-id]');
          if (!wrapper) return;
          self.recordDecision(wrapper.dataset.atomId, action === 'accept' ? 'accepted' : 'rejected');
          return;
        }

        if (action === 'cancel-review') {
          self.cancel();
          return;
        }

        // 'apply', 'next-change', 'prev-change' handled in Tasks 15-16
      });
    },

    scheduleSave: function () {
      const self = this;
      if (this.saveTimer) clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(function () { self.saveToStorage(); }, 150);
    },

    saveToStorage: function () {
      if (!this.storageKey) return;
      try {
        localStorage.setItem(this.storageKey, JSON.stringify({
          version: 1,
          canonicalUpdatedAt: this.canonicalUpdatedAt,
          decisions: this.state,
        }));
      } catch (e) { /* quota exceeded etc — silent */ }
    },

    loadFromStorage: function () {
      if (!this.storageKey) return;
      try {
        const raw = localStorage.getItem(this.storageKey);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (parsed && parsed.decisions && typeof parsed.decisions === 'object') {
          this.state = Object.assign(Object.create(null), parsed.decisions);
        }
      } catch (e) { this.state = Object.create(null); }
    },

    cancel: function () {
      const decided = Object.keys(this.state).length;
      if (decided > 0) {
        if (!confirm('Discard ' + decided + ' decisions?')) return;
      }
      try { localStorage.removeItem(this.storageKey); } catch (e) {}
      this.exit();
    },
  };

  // Helper for querySelector — atom IDs contain colons which are invalid in
  // CSS selectors unless escaped. CSS.escape may not be available in older browsers.
  function cssEscape(s) {
    return (window.CSS && window.CSS.escape) ? window.CSS.escape(s) : s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }
```

- [ ] **Step 2: Wire the Start Review button**

Find where the existing slideout binds its toolbar buttons (search for the existing `delta-compare-btn` handler in `delta.js`). Add a delegated handler for the new `[data-action="start-review"]` button alongside it. Roughly:

```javascript
  // In the existing slideout init / event-binding section, add:
  document.addEventListener('click', function (e) {
    const startBtn = e.target.closest('[data-action="start-review"]');
    if (!startBtn) return;
    const toolbar = startBtn.closest('[data-review-toolbar]');
    if (!toolbar) return;
    Craft.Delta.reviewMode.enter(toolbar);
  });
```

- [ ] **Step 3: Manual smoke test**

Open the slideout against an entry where one side is `current`. Click "Start Review". Confirm:
- Stepper bar appears
- Per-atom Accept/Reject buttons become visible
- Clicking Accept turns the wrapper green; clicking Reject turns it red; clicking the same button again clears the decision
- Reload the page, click Start Review again — your decisions are restored from localStorage
- The progress label updates ("2 of 7 decided")

- [ ] **Step 4: Commit**

```bash
git add src/assets/diff/dist/js/delta.js
git commit -m "feat(review-mode): JS controller foundation (state, decisions, localStorage)"
```

---

## Task 15: JS — stepper bar (next/prev/keyboard/IntersectionObserver)

**Goal:** Add navigation between atoms — both buttons and keyboard shortcuts. Track current focus via scroll.

**Files:**
- Modify: `src/assets/diff/dist/js/delta.js`

- [ ] **Step 1: Add stepper navigation methods**

Inside the `Craft.Delta.reviewMode` object, add:

```javascript
    focusedAtomId: null,
    intersectionObserver: null,

    next: function () {
      this.moveFocus(1);
    },
    prev: function () {
      this.moveFocus(-1);
    },

    moveFocus: function (delta) {
      const ids = this.atomIdsInDocumentOrder();
      if (ids.length === 0) return;

      let idx = this.focusedAtomId ? ids.indexOf(this.focusedAtomId) : -1;
      idx = (idx + delta + ids.length) % ids.length;
      if (idx < 0) idx = ids.length - 1;

      this.setFocus(ids[idx], true);
    },

    setFocus: function (atomId, scroll) {
      const self = this;
      // Clear previous focus
      document.querySelectorAll('.delta-atom-stepper-focus').forEach(function (el) {
        el.classList.remove('delta-atom-stepper-focus');
      });
      const wrapper = document.querySelector('[data-atom-id="' + cssEscape(atomId) + '"]');
      if (!wrapper) return;
      wrapper.classList.add('delta-atom-stepper-focus');
      this.focusedAtomId = atomId;
      if (scroll) {
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    },

    atomIdsInDocumentOrder: function () {
      return Array.from(document.querySelectorAll('[data-atom-id]')).map(function (el) {
        return el.dataset.atomId;
      });
    },

    bindKeyboardShortcuts: function () {
      const self = this;
      this.keyHandler = function (e) {
        if (!self.active) return;
        // Skip when typing in an input
        if (e.target.matches('input, textarea, [contenteditable]')) return;
        switch (e.key.toLowerCase()) {
          case 'j': self.next(); e.preventDefault(); break;
          case 'k': self.prev(); e.preventDefault(); break;
          case 'a':
            if (self.focusedAtomId) self.recordDecision(self.focusedAtomId, 'accepted');
            e.preventDefault();
            break;
          case 'r':
            if (self.focusedAtomId) self.recordDecision(self.focusedAtomId, 'rejected');
            e.preventDefault();
            break;
        }
      };
      document.addEventListener('keydown', this.keyHandler);
    },

    unbindKeyboardShortcuts: function () {
      if (this.keyHandler) {
        document.removeEventListener('keydown', this.keyHandler);
        this.keyHandler = null;
      }
    },

    bindScrollFocus: function () {
      const self = this;
      this.intersectionObserver = new IntersectionObserver(function (entries) {
        // Pick the topmost intersecting atom as the focused one
        const visible = entries.filter(function (e) { return e.isIntersecting; });
        if (visible.length === 0) return;
        visible.sort(function (a, b) {
          return a.target.getBoundingClientRect().top - b.target.getBoundingClientRect().top;
        });
        self.setFocus(visible[0].target.dataset.atomId, false);
      }, { threshold: 0.5 });

      document.querySelectorAll('[data-atom-id]').forEach(function (el) {
        self.intersectionObserver.observe(el);
      });
    },

    unbindScrollFocus: function () {
      if (this.intersectionObserver) {
        this.intersectionObserver.disconnect();
        this.intersectionObserver = null;
      }
    },
```

- [ ] **Step 2: Wire next/prev actions in the click handler**

In `bindEvents()` (added in Task 14), extend the click handler:

```javascript
        if (action === 'next-change') { self.next(); return; }
        if (action === 'prev-change') { self.prev(); return; }
```

- [ ] **Step 3: Hook keyboard + scroll into `enter()` and `exit()`**

Update `enter()` to call:

```javascript
      this.bindKeyboardShortcuts();
      this.bindScrollFocus();
      // Auto-focus the first atom
      const ids = this.atomIdsInDocumentOrder();
      if (ids.length > 0) this.setFocus(ids[0], false);
```

Update `exit()` to call:

```javascript
      this.unbindKeyboardShortcuts();
      this.unbindScrollFocus();
      this.focusedAtomId = null;
```

- [ ] **Step 4: Manual smoke test**

- Press `J` — focus moves to the next atom, smooth scroll to it.
- Press `K` — moves to previous.
- Press `A` — accepts the focused atom.
- Press `R` — rejects.
- Click "Next →" / "← Prev" buttons — same behavior.
- Scroll the slideout — the focus ring follows the topmost visible atom.

- [ ] **Step 5: Commit**

```bash
git add src/assets/diff/dist/js/delta.js
git commit -m "feat(review-mode): stepper navigation with keyboard shortcuts and scroll focus"
```

---

## Task 16: JS — apply flow (CTA, modal, response handling)

**Goal:** Wire the "Apply N accepted" button to POST to `actionApply` and handle each response shape per spec §7.6.

**Files:**
- Modify: `src/assets/diff/dist/js/delta.js`

- [ ] **Step 1: Add `apply()` method**

Add to the `reviewMode` object:

```javascript
    apply: function () {
      const self = this;
      const accepted = Object.entries(this.state)
        .filter(function (kv) { return kv[1] === 'accepted'; })
        .map(function (kv) { return kv[0]; });

      if (accepted.length === 0) return;

      const confirmed = confirm(
        'Create a new draft with ' + accepted.length + ' accepted changes? Rejected changes will not affect the entry.'
      );
      if (!confirmed) return;

      const toolbar = document.querySelector('[data-review-toolbar]');
      const entryId = toolbar.dataset.entryId;
      const siteId = toolbar.dataset.siteId;
      const sourceRef = toolbar.dataset.sourceRef;

      Craft.sendActionRequest('POST', 'craft-delta/diff/apply', {
        data: {
          entryId: parseInt(entryId, 10),
          siteId: parseInt(siteId, 10),
          sourceRef: sourceRef,
          acceptedAtoms: accepted,
        },
      }).then(function (response) {
        const data = response.data || {};
        if (data.success) {
          self.handleApplySuccess(data);
        } else {
          self.handleApplyError(data);
        }
      }).catch(function (err) {
        const data = (err && err.response && err.response.data) || {};
        self.handleApplyError(data);
      });
    },

    handleApplySuccess: function (data) {
      try { localStorage.removeItem(this.storageKey); } catch (e) {}
      this.exit();
      const goNow = confirm('Draft created. Open it now?');
      if (goNow && data.draftEditUrl) {
        window.location.href = data.draftEditUrl;
      }
    },

    handleApplyError: function (data) {
      const banner = document.querySelector('[data-review-banner]');
      switch (data.errorCode) {
        case 'stale-atoms':
          try { localStorage.removeItem(this.storageKey); } catch (e) {}
          if (banner) {
            banner.textContent = data.error || 'The entry has changed since you started reviewing; restarting.';
            banner.removeAttribute('hidden');
          }
          // Trigger a fresh diff reload — the existing slideout's reload mechanism
          // is invoked by re-running the comparison. Look for the existing
          // Craft.Delta.reload* helper in delta.js (search for "reload" or
          // "fetchDiff") and call it. If none exists, just close the slideout.
          if (typeof Craft.Delta.reload === 'function') {
            Craft.Delta.reload();
          }
          break;
        case 'validation-failed':
          // Preserve localStorage; show the error
          alert((data.error || 'Validation failed.') + '\n\nYour decisions are still saved. Adjust and try again.');
          break;
        case 'no-changes':
          // Shouldn't happen — apply button is disabled when 0 accepted
          alert(data.error || 'No changes to apply.');
          break;
        default:
          alert((data.error || 'Apply failed.') + '\n\nYour decisions are still saved.');
      }
    },
```

- [ ] **Step 2: Wire the Apply action in the click handler**

In `bindEvents()`:

```javascript
        if (action === 'apply') { self.apply(); return; }
```

- [ ] **Step 3: Manual smoke test**

- Accept at least one atom and click "Apply N accepted".
- Confirm the modal — request goes out.
- On 200: prompt "Draft created. Open it now?" — answer yes — verify the draft page loads.
- Reload the original entry diff — confirm decisions are no longer in localStorage (start fresh).
- Test the stale-atoms path: open the slideout in two tabs, save the canonical entry in tab 2, then click Apply in tab 1 — confirm the banner appears and localStorage is cleared.

- [ ] **Step 4: Commit**

```bash
git add src/assets/diff/dist/js/delta.js
git commit -m "feat(review-mode): apply flow with error-code branching"
```

---

## Task 17: JS — resume + cancel flows

**Goal:** Detect prior state on slideout open and surface a Resume banner. The cancel flow was already implemented in Task 14; this task adds the resume side.

**Files:**
- Modify: `src/assets/diff/dist/js/delta.js`
- Modify: `src/controllers/DiffController.php` (pass `canonicalUpdatedAt`)
- Modify: `src/templates/_diff-slideout.twig` (emit `data-canonical-updated-at`)

- [ ] **Step 1: Pass canonical's `dateUpdated` from controller**

In `src/controllers/DiffController.php` `actionCompare`, where `_diff-slideout.twig` is rendered (lines added in Task 12), add to the params:

```php
'canonicalUpdatedAt' => $canonical->dateUpdated?->format(\DateTimeInterface::ATOM),
```

- [ ] **Step 2: Emit it on the toolbar**

In `_diff-slideout.twig`, change the `<div class="delta-review-toolbar">` to include:

```twig
data-canonical-updated-at="{{ canonicalUpdatedAt }}"
```

- [ ] **Step 3: Add resume detection in JS**

Add to the `reviewMode` object:

```javascript
    /**
     * Look up prior state for this comparison; if found, surface a banner
     * with "Resume" / "Start fresh" options. Called when the slideout's
     * diff content has just been rendered — NOT when entering review mode.
     */
    checkForPriorState: function (toolbar) {
      const entryId = toolbar.dataset.entryId;
      const siteId = toolbar.dataset.siteId;
      const sourceRef = toolbar.dataset.sourceRef;
      const userId = (Craft.userId || '0');
      const liveUpdatedAt = toolbar.dataset.canonicalUpdatedAt;

      const key = 'craftdelta:review:' + userId + ':' + entryId + ':' + siteId + ':' + sourceRef;
      let raw;
      try { raw = localStorage.getItem(key); } catch (e) { return; }
      if (!raw) return;

      let parsed;
      try { parsed = JSON.parse(raw); } catch (e) { return; }
      if (!parsed || !parsed.decisions) return;

      const banner = document.querySelector('[data-review-banner]');
      if (!banner) return;

      // Stale check
      if (parsed.canonicalUpdatedAt && parsed.canonicalUpdatedAt !== liveUpdatedAt) {
        try { localStorage.removeItem(key); } catch (e) {}
        banner.textContent = 'The entry has changed since your last review; starting fresh.';
        banner.removeAttribute('hidden');
        return;
      }

      const total = document.querySelectorAll('[data-atom-id]').length;
      const decided = Object.keys(parsed.decisions).length;

      banner.innerHTML = '';
      const text = document.createElement('span');
      text.textContent = 'Resume previous review (' + decided + ' of ' + total + ' decided)? ';
      banner.appendChild(text);

      const resume = document.createElement('button');
      resume.type = 'button';
      resume.className = 'btn submit';
      resume.textContent = 'Resume';
      resume.addEventListener('click', function () {
        Craft.Delta.reviewMode.enter(toolbar);
        banner.setAttribute('hidden', '');
      });
      banner.appendChild(resume);

      const fresh = document.createElement('button');
      fresh.type = 'button';
      fresh.className = 'btn';
      fresh.textContent = 'Start fresh';
      fresh.addEventListener('click', function () {
        try { localStorage.removeItem(key); } catch (e) {}
        banner.setAttribute('hidden', '');
      });
      banner.appendChild(fresh);

      banner.removeAttribute('hidden');
    },
```

- [ ] **Step 4: Call `checkForPriorState` after diff render**

Find the existing place in `delta.js` where the slideout finishes rendering the diff content (after `actionCompare` resolves and HTML is injected). Add at the end of that flow:

```javascript
      const toolbar = document.querySelector('[data-review-toolbar]');
      if (toolbar) {
        Craft.Delta.reviewMode.checkForPriorState(toolbar);
      }
```

- [ ] **Step 5: Manual smoke test**

- Open the slideout, click Start Review, accept 2-3 atoms, close the tab without applying.
- Reopen the same entry's diff with the same comparison. Confirm the Resume banner appears with the correct count.
- Click Resume — decisions are restored.
- Click Start fresh — localStorage clears.
- Edit the canonical entry separately, then re-open the diff — confirm the "starting fresh" stale message.

- [ ] **Step 6: Commit**

```bash
git add src/assets/diff/dist/js/delta.js src/controllers/DiffController.php src/templates/_diff-slideout.twig
git commit -m "feat(review-mode): resume banner with stale-state detection"
```

---

## Task 18: Translations (all 8 locales)

**Goal:** Provide localized strings for every new user-facing message.

**Files:**
- Modify: `src/translations/{en,de,fr,es,it,nl,pt,pl}/craft-delta.php`

- [ ] **Step 1: Define the canonical English keys and add them**

Append to `src/translations/en/craft-delta.php` (before the closing `];`):

```php
    'Start Review' => 'Start Review',
    'Cancel review' => 'Cancel review',
    'Accept' => 'Accept',
    'Reject' => 'Reject',
    '{decided} of {total} decided' => '{decided} of {total} decided',
    'Apply {count} accepted' => 'Apply {count} accepted',
    'Prev' => 'Prev',
    'Next' => 'Next',
    'Source version not found.' => 'Source version not found.',
    'No changes to apply.' => 'No changes to apply.',
    'The entry has changed since you started reviewing. Please reload the diff and restart your review.' => 'The entry has changed since you started reviewing. Please reload the diff and restart your review.',
    'Insufficient permissions to create a draft on this section.' => 'Insufficient permissions to create a draft on this section.',
    'Review of {ref}' => 'Review of {ref}',
    'Enable Review Mode' => 'Enable Review Mode',
    'Show the "Start Review" button on the diff slideout and allow accepting/rejecting changes into a new draft.' => 'Show the "Start Review" button on the diff slideout and allow accepting/rejecting changes into a new draft.',
```

- [ ] **Step 2: Translate each key into the other 7 locales**

For each of `de`, `fr`, `es`, `it`, `nl`, `pt`, `pl`:

Append the same set of keys to `src/translations/<locale>/craft-delta.php` with translations. Use the existing translations in those files as a style/tone reference. Suggested German entries (the implementer should adapt for tone consistency with existing strings):

```php
    'Start Review' => 'Überprüfung starten',
    'Cancel review' => 'Überprüfung abbrechen',
    'Accept' => 'Übernehmen',
    'Reject' => 'Verwerfen',
    '{decided} of {total} decided' => '{decided} von {total} entschieden',
    'Apply {count} accepted' => '{count} übernommene Änderungen anwenden',
    'Prev' => 'Zurück',
    'Next' => 'Weiter',
    'Source version not found.' => 'Quellversion nicht gefunden.',
    'No changes to apply.' => 'Keine Änderungen zum Anwenden.',
    'The entry has changed since you started reviewing. Please reload the diff and restart your review.' => 'Der Eintrag hat sich geändert, seit Sie mit der Überprüfung begonnen haben. Bitte laden Sie den Vergleich neu und starten Sie die Überprüfung erneut.',
    'Insufficient permissions to create a draft on this section.' => 'Unzureichende Berechtigungen zum Erstellen eines Entwurfs in dieser Sektion.',
    'Review of {ref}' => 'Überprüfung von {ref}',
    'Enable Review Mode' => 'Review-Modus aktivieren',
    'Show the "Start Review" button on the diff slideout and allow accepting/rejecting changes into a new draft.' => 'Zeigt die Schaltfläche „Überprüfung starten" im Diff-Slideout an und ermöglicht das Übernehmen/Verwerfen von Änderungen in einem neuen Entwurf.',
```

For other locales, the implementer should produce equivalent translations or flag for native-speaker review. Where in doubt, leave the English string in place — Craft falls back to the source string if a translation is missing.

- [ ] **Step 3: Verify all eight files parse**

```bash
for locale in en de fr es it nl pt pl; do
  php -l "src/translations/$locale/craft-delta.php"
done
```

Expected: 8 lines of `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/translations/
git commit -m "feat(review-mode): add translations for review-mode strings (8 locales)"
```

---

## Task 19: README updates

**Goal:** Document the new feature.

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a Features bullet**

In the **Features** section of `README.md`, append:

```markdown
- **Review Mode** — accept or reject individual changes from inside the diff slideout, then apply all accepted changes as a new draft on the canonical entry. Per-field and per-Matrix-block granularity. Resumable across browser restarts.
```

- [ ] **Step 2: Add a Usage subsection**

After the existing Usage paragraphs, add:

```markdown
### Review Mode

When comparing a draft or revision against the **current** entry, click **Start Review** in the slideout toolbar. Each changed field gains an **✓ Accept** / **✗ Reject** button pair; Matrix blocks get the same buttons per block. Use **J / K** to step between changes, **A / R** to decide. Click **Apply N accepted** to create a new draft of the canonical entry containing your accepted changes — rejected changes are dropped.

Decisions persist in browser localStorage until you Apply or Cancel. If the canonical entry is edited mid-review, you'll be prompted to start over.
```

- [ ] **Step 3: Add the Settings row**

In the Settings table, append:

```markdown
| Enable Review Mode | On | Show the "Start Review" button. When off, the plugin behaves as a pure read-only diff tool. |
```

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs(review-mode): document review mode and kill-switch setting"
```

---

## Task 20: Manual QA pass + integration test stubs

**Goal:** Walk through every checkbox from spec §9.3, confirming behavior end-to-end. Stub out integration tests for follow-up.

**Files:**
- Create: `tests/Unit/Service/MergeServiceIntegrationTest.php` (skipped, with structure)

- [ ] **Step 1: Walk the spec §9.3 manual QA checklist**

For each item, exercise the feature and tick it off:

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

If any test fails, fix and re-run the relevant unit test loop. Only proceed to commit when all twelve pass.

- [ ] **Step 2: Stub integration test file (Craft kernel boot pending)**

Create `tests/Unit/Service/MergeServiceIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MergeService::merge end-to-end.
 *
 * These tests require Craft kernel boot, which the plugin's test setup does
 * not yet provide (matches the existing `RelationDifferTest` pattern where
 * five Asset rendering tests are also skipped pending kernel bootstrap).
 *
 * When kernel boot is added, remove the markTestSkipped() calls and fill in
 * the test bodies. The scenarios are listed in spec §9.2.
 */
class MergeServiceIntegrationTest extends TestCase
{
    public function testMergeEndToEndWithFieldAndMatrixAtoms(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeRejectsStaleAtomsAfterCanonicalEdit(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeRequiresCreateEntryDraftsPermission(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeMultisiteIsolation(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeAttributeApplyForTitleAndSlug(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot in plugin test setup.');
    }

    public function testMergeFieldTypeFidelity(): void
    {
        $this->markTestSkipped('CKEditor with embeds, Asset with focals, Money with currency, Table.');
    }
}
```

- [ ] **Step 3: Run all PHP tests one last time to confirm green**

```bash
./vendor/bin/phpunit
```

Expected: 23 unit tests pass, 6 integration tests skipped, 0 failures.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Service/MergeServiceIntegrationTest.php
git commit -m "test(review-mode): integration test stubs pending kernel boot"
```

---

## Summary

When all 20 tasks are complete:

- **23 PHP unit tests** covering atom parsing, validation, and the Matrix merge algorithm (including the spec's worked example).
- **6 skipped integration tests** as a placeholder for kernel-boot follow-up.
- **All v1 features** from the spec implemented: Start Review gating, per-field/per-block accept/reject, stepper with keyboard shortcuts, debounced localStorage persistence, resume banner, stale-state detection, apply to a new draft, kill-switch setting.
- **Translations** for all 8 supported locales.
- **README** updated.

Branch state at the end: `feature/review-mode` with ~20 commits. Ready for review/PR. Push and merge are gated on user approval.
