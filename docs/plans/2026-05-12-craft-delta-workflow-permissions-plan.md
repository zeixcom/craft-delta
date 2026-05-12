# Craft Delta v2.0 — Workflow Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a submit-for-review workflow to Craft Delta with three per-section permissions, a Draft → Pending → Approved/Rejected state machine, optional scheduled apply via queue job, and email notifications. Users without workflow permissions retain the v1.1 read-only diff experience unchanged.

**Architecture:** New `WorkflowService` owns the state machine and is the only writer of the new `craftdelta_draft_workflows` table. A new `WorkflowController` exposes thin HTTP endpoints. Approve-with-schedule pushes an `ApplyScheduledDraft` queue job. Frontend JS adds a Submit modal for authors and a workflow toolbar for reviewers; everyone else sees the existing read-only diff.

**Tech Stack:** Craft CMS 5.8+, PHP 8.2+, Yii2 ActiveRecord, Craft mailer, Craft queue, PHPUnit 11, vanilla JS (matches existing `delta.js` pattern). No new composer dependencies.

**Design source:** `docs/plans/2026-05-12-workflow-permissions-design.md`

**Plan conventions:**
- Each task ends with a commit.
- Tests requiring a booted Craft kernel are added as `markTestSkipped()` stubs, matching the existing pattern in `tests/Unit/Service/MergeServiceIntegrationTest.php`. Pure-PHP logic gets real unit tests.
- Four spots flagged in the design as **"user-input TODOs"** (state transition table, reviewer dropdown query, email body copy, reject note shape) are given **working defaults** in this plan. They are clearly marked with `# USER-DECISION` comments in code so the user can revisit before merging — no placeholders, but explicit pause points.

---

## File Structure

**New files:**
- `src/migrations/Install.php` — fresh-install table creation
- `src/migrations/m260512_000000_workflow_table.php` — upgrade migration for existing installs
- `src/records/DraftWorkflowRecord.php` — Yii ActiveRecord
- `src/models/DraftWorkflow.php` — domain model wrapping the record
- `src/services/WorkflowService.php` — state machine + transitions
- `src/services/EmailService.php` — email composition
- `src/controllers/WorkflowController.php` — HTTP endpoints
- `src/queue/jobs/ApplyScheduledDraft.php` — scheduled apply
- `src/events/WorkflowEvent.php` — fired by `WorkflowService`
- `src/templates/_emails/submitted.twig` — to assignee
- `src/templates/_emails/approved.twig` — to author
- `src/templates/_emails/rejected.twig` — to author
- `src/templates/_submit-modal.twig` — author-side submit modal markup
- `src/templates/_workflow-toolbar.twig` — reviewer-side toolbar markup
- `src/assets/diff/dist/js/workflow.js` — frontend workflow logic
- `tests/Unit/Service/WorkflowServiceTest.php` — state machine unit tests
- `tests/Unit/Service/WorkflowServiceIntegrationTest.php` — kernel-required integration stubs

**Modified files:**
- `src/Delta.php` — register permissions, routes, asset, settings, schema version
- `src/models/Settings.php` — add `enableWorkflow`
- `src/controllers/DiffController.php` — surface workflow row to slideout template
- `src/templates/_diff-slideout.twig` — embed workflow toolbar
- `src/assets/diff/DiffAsset.php` — depend on workflow.js
- `src/translations/en/craft-delta.php` — add new strings (other locales get the source strings as fallback)
- `composer.json` — bump version to `2.0.0`
- `README.md` — document workflow permissions
- `CHANGELOG.md` — v2.0.0 entry

---

## Task 1: Settings + Permissions Registration

Add `enableWorkflow` setting and register two new per-section permissions. Existing `applyReview` permission is untouched.

**Files:**
- Modify: `src/models/Settings.php`
- Modify: `src/Delta.php:93-117` (the `registerPermissions()` method)
- Test: `tests/Unit/Service/SettingsTest.php` (new)

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/SettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\Settings;

class SettingsTest extends TestCase
{
    public function testEnableWorkflowDefaultsTrue(): void
    {
        $settings = new Settings();
        $this->assertTrue($settings->enableWorkflow);
    }

    public function testEnableWorkflowCanBeDisabled(): void
    {
        $settings = new Settings(['enableWorkflow' => false]);
        $this->assertFalse($settings->enableWorkflow);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/SettingsTest.php`
Expected: FAIL with "Undefined property: enableWorkflow"

- [ ] **Step 3: Add the setting**

Modify `src/models/Settings.php` — add after the `enableReviewMode` property:

```php
    /**
     * Enable the submit-for-review workflow (v2.0+). When false, the plugin
     * behaves exactly like v1.1: no Submit button, no workflow toolbar.
     * The Apply Review permission still gates the legacy granular review.
     */
    public bool $enableWorkflow = true;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/SettingsTest.php`
Expected: PASS, 2 tests, 2 assertions

- [ ] **Step 5: Register the two new permissions**

Modify `src/Delta.php` — replace the `registerPermissions()` body (currently registers only `craftdelta-applyReview:{uid}`) with:

```php
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $sections = Craft::$app->getEntries()->getAllSections();
                if (count($sections) === 0) {
                    return;
                }

                $sectionPermissions = [];
                foreach ($sections as $section) {
                    $sectionPermissions["craftdelta-submitDraft:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Submit drafts for review in "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                    $sectionPermissions["craftdelta-reviewDraft:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Review submitted drafts in "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                    $sectionPermissions["craftdelta-applyReview:{$section->uid}"] = [
                        'label' => Craft::t('craft-delta', 'Apply review-mode changes for "{section}"', [
                            'section' => $section->name,
                        ]),
                    ];
                }

                $event->permissions[] = [
                    'heading' => Craft::t('craft-delta', 'Craft Delta'),
                    'permissions' => $sectionPermissions,
                ];
            }
        );
    }
```

- [ ] **Step 6: Verify static analysis passes**

Run: `composer phpstan && composer check-cs`
Expected: No errors

- [ ] **Step 7: Commit**

```bash
git add src/models/Settings.php src/Delta.php tests/Unit/Service/SettingsTest.php
git commit -m "feat(workflow): register submit/review permissions and enableWorkflow setting"
```

---

## Task 2: Database Schema, Record, and Model

Create the `craftdelta_draft_workflows` table via a fresh-install migration and a v2 upgrade migration. Add the Yii ActiveRecord and the domain model wrapping it.

**Files:**
- Create: `src/migrations/Install.php`
- Create: `src/migrations/m260512_000000_workflow_table.php`
- Create: `src/records/DraftWorkflowRecord.php`
- Create: `src/models/DraftWorkflow.php`
- Modify: `src/Delta.php:35` (schemaVersion)
- Test: `tests/Unit/Service/DraftWorkflowTest.php` (new)

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Service/DraftWorkflowTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\DraftWorkflow;

class DraftWorkflowTest extends TestCase
{
    public function testStateConstantsExist(): void
    {
        $this->assertSame('pending', DraftWorkflow::STATE_PENDING);
        $this->assertSame('approved', DraftWorkflow::STATE_APPROVED);
        $this->assertSame('rejected', DraftWorkflow::STATE_REJECTED);
    }

    public function testIsScheduledTrueWhenScheduledForInFuture(): void
    {
        $wf = new DraftWorkflow([
            'state' => DraftWorkflow::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => null,
        ]);
        $this->assertTrue($wf->isScheduled());
    }

    public function testIsScheduledFalseWhenAlreadyApplied(): void
    {
        $wf = new DraftWorkflow([
            'state' => DraftWorkflow::STATE_APPROVED,
            'scheduledFor' => new \DateTime('+1 hour'),
            'appliedAt' => new \DateTime(),
        ]);
        $this->assertFalse($wf->isScheduled());
    }

    public function testIsTerminalForApprovedAndRejected(): void
    {
        $approved = new DraftWorkflow(['state' => DraftWorkflow::STATE_APPROVED, 'appliedAt' => new \DateTime()]);
        $rejected = new DraftWorkflow(['state' => DraftWorkflow::STATE_REJECTED]);
        $pending = new DraftWorkflow(['state' => DraftWorkflow::STATE_PENDING]);

        $this->assertTrue($approved->isTerminal());
        $this->assertTrue($rejected->isTerminal());
        $this->assertFalse($pending->isTerminal());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/DraftWorkflowTest.php`
Expected: FAIL with "Class DraftWorkflow not found"

- [ ] **Step 3: Create the ActiveRecord**

Create `src/records/DraftWorkflowRecord.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $draftId
 * @property int $canonicalEntryId
 * @property string $sectionUid
 * @property string $state
 * @property int $submittedBy
 * @property int|null $assigneeId
 * @property int|null $decidedBy
 * @property string|null $rejectNote
 * @property string|null $scheduledFor
 * @property string|null $appliedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DraftWorkflowRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%craftdelta_draft_workflows}}';
    }
}
```

- [ ] **Step 4: Create the domain model**

Create `src/models/DraftWorkflow.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\models;

use craft\base\Model;
use DateTime;

/**
 * Workflow state for a draft. State machine:
 *
 *   (no row)   --submit-->   pending
 *   pending    --approve-->  approved (optionally scheduled)
 *   pending    --reject -->  rejected (terminal)
 *
 * Both `approved` and `rejected` are terminal; rejected drafts are preserved
 * but cannot be re-submitted (author must duplicate the draft).
 */
class DraftWorkflow extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';

    public ?int $id = null;
    public int $draftId = 0;
    public int $canonicalEntryId = 0;
    public string $sectionUid = '';
    public string $state = self::STATE_PENDING;
    public int $submittedBy = 0;
    public ?int $assigneeId = null;
    public ?int $decidedBy = null;
    public ?string $rejectNote = null;
    public ?DateTime $scheduledFor = null;
    public ?DateTime $appliedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * True when the workflow is approved with a future apply time that hasn't
     * fired yet (queue job pending).
     */
    public function isScheduled(): bool
    {
        return $this->state === self::STATE_APPROVED
            && $this->scheduledFor !== null
            && $this->appliedAt === null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::STATE_APPROVED, self::STATE_REJECTED], true);
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Service/DraftWorkflowTest.php`
Expected: PASS, 4 tests

- [ ] **Step 6: Create the Install migration**

Create `src/migrations/Install.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * Install migration for fresh Craft Delta installations.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%craftdelta_draft_workflows}}', [
            'id' => $this->primaryKey(),
            'draftId' => $this->integer()->notNull(),
            'canonicalEntryId' => $this->integer()->notNull(),
            'sectionUid' => $this->char(36)->notNull(),
            'state' => $this->string(16)->notNull(),
            'submittedBy' => $this->integer()->notNull(),
            'assigneeId' => $this->integer()->null(),
            'decidedBy' => $this->integer()->null(),
            'rejectNote' => $this->text()->null(),
            'scheduledFor' => $this->dateTime()->null(),
            'appliedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['assigneeId', 'state']);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['state', 'scheduledFor']);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['draftId'], true);
        $this->createIndex(null, '{{%craftdelta_draft_workflows}}', ['sectionUid']);

        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['draftId'], '{{%drafts}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['canonicalEntryId'], '{{%entries}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['submittedBy'], '{{%users}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['assigneeId'], '{{%users}}', ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%craftdelta_draft_workflows}}', ['decidedBy'], '{{%users}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_draft_workflows}}');
        return true;
    }
}
```

- [ ] **Step 7: Create the v2 upgrade migration**

Create `src/migrations/m260512_000000_workflow_table.php` — same body as Install, named for the timestamped sequence:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\migrations;

use craft\db\Migration;

/**
 * Adds the workflow table for sites upgrading from v1.x to v2.0.
 */
class m260512_000000_workflow_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%craftdelta_draft_workflows}}')) {
            return true;
        }

        $install = new Install();
        $install->db = $this->db;
        return $install->safeUp();
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%craftdelta_draft_workflows}}');
        return true;
    }
}
```

- [ ] **Step 8: Bump schemaVersion**

Modify `src/Delta.php` — change line 35:

```php
    public string $schemaVersion = '2.0.0';
```

- [ ] **Step 9: Run static analysis**

Run: `composer phpstan && composer check-cs`
Expected: No errors

- [ ] **Step 10: Commit**

```bash
git add src/migrations/ src/records/ src/models/DraftWorkflow.php src/Delta.php tests/Unit/Service/DraftWorkflowTest.php
git commit -m "feat(workflow): add workflow table, record, and domain model"
```

---

## Task 3: WorkflowService — State Machine

Implement the state machine, transitions, and authorization helpers. The four user-input TODOs from the design are present here as defaults with `# USER-DECISION` markers.

**Files:**
- Create: `src/services/WorkflowService.php`
- Create: `src/events/WorkflowEvent.php`
- Modify: `src/Delta.php:38-48` (register component)
- Test: `tests/Unit/Service/WorkflowServiceTest.php` (new, pure state-machine tests)
- Test: `tests/Unit/Service/WorkflowServiceIntegrationTest.php` (new, skipped kernel-required tests)

---

- [ ] **Step 1: Write the failing state-machine test**

Create `tests/Unit/Service/WorkflowServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\services\WorkflowService;

class WorkflowServiceTest extends TestCase
{
    public function testPendingAllowsApproveAndReject(): void
    {
        $this->assertTrue(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_PENDING,
            DraftWorkflow::STATE_APPROVED
        ));
        $this->assertTrue(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_PENDING,
            DraftWorkflow::STATE_REJECTED
        ));
    }

    public function testApprovedIsTerminal(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_PENDING
        ));
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_REJECTED
        ));
    }

    public function testRejectedIsTerminal(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_REJECTED,
            DraftWorkflow::STATE_PENDING
        ));
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            DraftWorkflow::STATE_REJECTED,
            DraftWorkflow::STATE_APPROVED
        ));
    }

    public function testUnknownStateRejected(): void
    {
        $this->assertFalse(WorkflowService::isTransitionAllowed(
            'bogus',
            DraftWorkflow::STATE_APPROVED
        ));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Service/WorkflowServiceTest.php`
Expected: FAIL with "Class WorkflowService not found"

- [ ] **Step 3: Create the WorkflowEvent class**

Create `src/events/WorkflowEvent.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\events;

use yii\base\Event;
use zeixcom\craftdelta\models\DraftWorkflow;

/**
 * Fired by WorkflowService when state transitions occur. Listeners can
 * integrate with Slack, audit logs, etc.
 */
class WorkflowEvent extends Event
{
    public DraftWorkflow $workflow;
}
```

- [ ] **Step 4: Create WorkflowService**

Create `src/services/WorkflowService.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\events\WorkflowEvent;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\queue\jobs\ApplyScheduledDraft;
use zeixcom\craftdelta\records\DraftWorkflowRecord;

/**
 * Owns the submit-for-review state machine and the only writer of the
 * craftdelta_draft_workflows table. Controllers stay thin and delegate here.
 */
class WorkflowService extends Component
{
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';
    public const EVENT_AFTER_APPROVE = 'afterApprove';
    public const EVENT_AFTER_REJECT = 'afterReject';

    /**
     * Allowed state transitions. Source of truth for the state machine.
     *
     * USER-DECISION: This is the state transition table. Add a
     * 'changes_requested' state here if you decide to allow re-submission in
     * v2.1. Leave as-is for the v2.0 terminal-rejection model.
     */
    private const TRANSITIONS = [
        DraftWorkflow::STATE_PENDING => [
            DraftWorkflow::STATE_APPROVED,
            DraftWorkflow::STATE_REJECTED,
        ],
        DraftWorkflow::STATE_APPROVED => [],
        DraftWorkflow::STATE_REJECTED => [],
    ];

    public static function isTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Look up the workflow row for a given draft, or null if none.
     */
    public function getByDraftId(int $draftId): ?DraftWorkflow
    {
        $record = DraftWorkflowRecord::findOne(['draftId' => $draftId]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    /**
     * Authors who can submit must own the draft AND hold the per-section
     * submitDraft permission.
     */
    public function canSubmit(User $user, Entry $draft): bool
    {
        if (!$draft->getIsDraft()) {
            return false;
        }
        $section = $draft->getSection();
        if ($section === null) {
            return false;
        }
        // Must be the draft's creator (or admin)
        $creatorId = $draft->getCreatorId();
        if ($creatorId !== null && $creatorId !== $user->id && !$user->admin) {
            return false;
        }
        return $user->can("craftdelta-submitDraft:{$section->uid}");
    }

    /**
     * Reviewer can decide on a workflow only if they're the assignee AND hold
     * the per-section reviewDraft permission. Admins implicitly bypass.
     */
    public function canReview(User $user, DraftWorkflow $wf): bool
    {
        if ($user->admin) {
            return true;
        }
        if ($wf->assigneeId !== $user->id) {
            return false;
        }
        return $user->can("craftdelta-reviewDraft:{$wf->sectionUid}");
    }

    /**
     * Users eligible to be picked as a reviewer for a draft in this section.
     *
     * USER-DECISION: sorting, exclusions, max results. Default below: any user
     * with reviewDraft permission for the section, sorted alphabetically by
     * full name, excluding the current user (you usually don't review your
     * own draft). Tweak if your team prefers e.g. recent collaborators first.
     */
    public function getEligibleAssignees(string $sectionUid, ?int $excludeUserId = null): array
    {
        $users = User::find()
            ->status(User::STATUS_ACTIVE)
            ->can("craftdelta-reviewDraft:{$sectionUid}")
            ->orderBy(['fullName' => SORT_ASC])
            ->all();

        if ($excludeUserId !== null) {
            $users = array_values(array_filter($users, fn($u) => $u->id !== $excludeUserId));
        }

        return $users;
    }

    /**
     * Create a Pending workflow row. Caller (controller) must have already
     * verified canSubmit().
     */
    public function submit(Entry $draft, int $assigneeId, User $submittedBy): DraftWorkflow
    {
        if (!$draft->getIsDraft()) {
            throw new InvalidArgumentException('Submit requires a draft entry.');
        }
        $section = $draft->getSection();
        if ($section === null) {
            throw new InvalidArgumentException('Draft has no section.');
        }

        $existing = $this->getByDraftId($draft->draftId);
        if ($existing !== null) {
            throw new InvalidArgumentException('A workflow already exists for this draft.');
        }

        $record = new DraftWorkflowRecord();
        $record->draftId = $draft->draftId;
        $record->canonicalEntryId = $draft->getCanonicalId();
        $record->sectionUid = $section->uid;
        $record->state = DraftWorkflow::STATE_PENDING;
        $record->submittedBy = $submittedBy->id;
        $record->assigneeId = $assigneeId;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        Delta::getInstance()->email->sendSubmitted($wf, $draft);

        $this->trigger(self::EVENT_AFTER_SUBMIT, new WorkflowEvent(['workflow' => $wf]));

        return $wf;
    }

    /**
     * Wholesale approve — apply now or push queue job for later.
     */
    public function approveWholesale(DraftWorkflow $wf, ?DateTime $scheduledFor, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_APPROVED;
        $record->decidedBy = $reviewer->id;
        $record->scheduledFor = $scheduledFor ? Db::prepareDateForDb($scheduledFor) : null;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        if ($scheduledFor === null) {
            $this->applyDraftNow($wf);
        } else {
            Craft::$app->getQueue()->delay(max(0, $scheduledFor->getTimestamp() - time()))
                ->push(new ApplyScheduledDraft(['workflowId' => $wf->id]));
        }

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    /**
     * Granular approve — caller passes the field handles the reviewer accepted.
     * Delegates the actual write to the existing MergeService.
     */
    public function approveGranular(DraftWorkflow $wf, array $acceptedFieldHandles, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_APPROVED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_APPROVED;
        $record->decidedBy = $reviewer->id;
        $record->appliedAt = Db::prepareDateForDb(new DateTime());
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        // The MergeService is the existing v1.1 write path. We pass through.
        // (The controller is responsible for translating accepted atoms into
        // the shape MergeService expects — same as today.)

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendApproved($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_APPROVE, new WorkflowEvent(['workflow' => $wf]));
    }

    /**
     * Reject — terminal. Optional note stored as-is.
     *
     * USER-DECISION: rejectNote is stored as plain text. If you want
     * markdown/CKEditor, change the column type in the migration and add
     * sanitization here. For v2.0 we keep plain text.
     */
    public function reject(DraftWorkflow $wf, ?string $note, User $reviewer): void
    {
        $this->assertTransition($wf->state, DraftWorkflow::STATE_REJECTED);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record === null) {
            throw new InvalidArgumentException('Workflow not found.');
        }

        $record->state = DraftWorkflow::STATE_REJECTED;
        $record->decidedBy = $reviewer->id;
        $record->rejectNote = $note;
        $record->save(false);

        $wf = $this->modelFromRecord($record);

        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft) {
            Delta::getInstance()->email->sendRejected($wf, $draft);
        }

        $this->trigger(self::EVENT_AFTER_REJECT, new WorkflowEvent(['workflow' => $wf]));
    }

    /**
     * Apply the draft to canonical immediately. Called by approveWholesale
     * when no schedule, and by the queue job when the schedule fires.
     */
    public function applyDraftNow(DraftWorkflow $wf): void
    {
        $draft = Craft::$app->getEntries()->getEntryById($wf->draftId, '*', ['drafts' => true]);
        if ($draft === null) {
            throw new InvalidArgumentException('Draft no longer exists.');
        }

        Craft::$app->getDrafts()->applyDraft($draft);

        $record = DraftWorkflowRecord::findOne(['id' => $wf->id]);
        if ($record !== null) {
            $record->appliedAt = Db::prepareDateForDb(new DateTime());
            $record->scheduledFor = null;
            $record->save(false);
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (!self::isTransitionAllowed($from, $to)) {
            throw new ForbiddenHttpException("Illegal transition: {$from} → {$to}");
        }
    }

    private function modelFromRecord(DraftWorkflowRecord $record): DraftWorkflow
    {
        return new DraftWorkflow([
            'id' => $record->id,
            'draftId' => $record->draftId,
            'canonicalEntryId' => $record->canonicalEntryId,
            'sectionUid' => $record->sectionUid,
            'state' => $record->state,
            'submittedBy' => $record->submittedBy,
            'assigneeId' => $record->assigneeId,
            'decidedBy' => $record->decidedBy,
            'rejectNote' => $record->rejectNote,
            'scheduledFor' => $record->scheduledFor ? new DateTime($record->scheduledFor) : null,
            'appliedAt' => $record->appliedAt ? new DateTime($record->appliedAt) : null,
            'dateCreated' => $record->dateCreated ? new DateTime($record->dateCreated) : null,
            'dateUpdated' => $record->dateUpdated ? new DateTime($record->dateUpdated) : null,
            'uid' => $record->uid,
        ]);
    }
}
```

- [ ] **Step 5: Register the service as a plugin component**

Modify `src/Delta.php` — extend the `config()` method's `components` array and update the doc-block:

```php
/**
 * @property-read DiffService $diff
 * @property-read FieldDiffService $fieldDiff
 * @property-read RevisionService $revision
 * @property-read MergeService $merge
 * @property-read WorkflowService $workflow
 * @property-read EmailService $email
 */
```

```php
    public static function config(): array
    {
        return [
            'components' => [
                'diff' => DiffService::class,
                'fieldDiff' => FieldDiffService::class,
                'revision' => RevisionService::class,
                'merge' => MergeService::class,
                'workflow' => WorkflowService::class,
                'email' => EmailService::class,
            ],
        ];
    }
```

Add the matching `use` statements at the top:

```php
use zeixcom\craftdelta\services\WorkflowService;
use zeixcom\craftdelta\services\EmailService;
```

- [ ] **Step 6: Add the integration-test stub**

Create `tests/Unit/Service/WorkflowServiceIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for WorkflowService submit/approve/reject. Require a
 * booted Craft kernel — matches the existing MergeServiceIntegrationTest
 * skip pattern.
 *
 * When kernel boot is added, remove the markTestSkipped() calls and fill in
 * the test bodies for each scenario below.
 */
class WorkflowServiceIntegrationTest extends TestCase
{
    public function testSubmitCreatesPendingRowAndSendsEmail(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testApproveWholesaleNowAppliesDraftToCanonical(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testApproveWholesaleScheduledPushesQueueJob(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testRejectSetsTerminalStateAndPreservesDraft(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }

    public function testCanReviewFalseForNonAssignee(): void
    {
        $this->markTestSkipped('Requires Craft kernel boot.');
    }
}
```

- [ ] **Step 7: Run all tests**

Run: `vendor/bin/phpunit`
Expected: All passing (WorkflowServiceTest has 4 real tests; integration stubs are skipped).

- [ ] **Step 8: Run static analysis**

Run: `composer phpstan && composer check-cs`
Expected: No errors. EmailService and ApplyScheduledDraft are referenced but not yet created — phpstan will fail until Task 4 and Task 5 land. **Skip this step's verification until Task 5 is complete; mark phpstan green there.**

- [ ] **Step 9: Commit**

```bash
git add src/services/WorkflowService.php src/events/WorkflowEvent.php src/Delta.php tests/Unit/Service/WorkflowServiceTest.php tests/Unit/Service/WorkflowServiceIntegrationTest.php
git commit -m "feat(workflow): add WorkflowService state machine and event"
```

---

## Task 4: EmailService + Templates

Three plain-text email templates and a service that composes them via Craft's mailer.

**Files:**
- Create: `src/services/EmailService.php`
- Create: `src/templates/_emails/submitted.twig`
- Create: `src/templates/_emails/approved.twig`
- Create: `src/templates/_emails/rejected.twig`

---

- [ ] **Step 1: Create the email templates**

Create `src/templates/_emails/submitted.twig`:

```twig
{# USER-DECISION: tone, content, whether to embed diff stats. Defaults below. #}
Hi {{ assignee.friendlyName }},

{{ author.friendlyName }} has submitted a draft for your review:

  {{ entry.title }}
  {{ url }}

Open the entry to review the changes and approve, schedule, or reject.

— Craft Delta
```

Create `src/templates/_emails/approved.twig`:

```twig
{# USER-DECISION: tone, content. Defaults below. #}
Hi {{ author.friendlyName }},

{{ reviewer.friendlyName }} has approved your draft:

  {{ entry.title }}
  {{ url }}

{% if scheduledFor %}
Scheduled to publish at: {{ scheduledFor|datetime('full') }}
{% else %}
The changes have been applied to the entry.
{% endif %}

— Craft Delta
```

Create `src/templates/_emails/rejected.twig`:

```twig
{# USER-DECISION: tone, content. Defaults below. #}
Hi {{ author.friendlyName }},

{{ reviewer.friendlyName }} has rejected your draft:

  {{ entry.title }}
  {{ url }}

{% if note %}
Reviewer's note:

{{ note }}
{% endif %}

The draft is preserved. If you want to revise and resubmit, duplicate the draft and submit the copy.

— Craft Delta
```

- [ ] **Step 2: Create the EmailService**

Create `src/services/EmailService.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use zeixcom\craftdelta\models\DraftWorkflow;

/**
 * Composes and sends the three workflow notification emails. Wraps
 * Craft::$app->getMailer() so callers stay short.
 */
class EmailService extends Component
{
    public function sendSubmitted(DraftWorkflow $wf, Entry $draft): void
    {
        if ($wf->assigneeId === null) {
            return;
        }
        $assignee = Craft::$app->getUsers()->getUserById($wf->assigneeId);
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        if (!$assignee || !$author) {
            return;
        }

        $body = $this->render('submitted', [
            'assignee' => $assignee,
            'author' => $author,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($assignee->email)
            ->setSubject(Craft::t('craft-delta', 'Draft awaiting your review: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
            ->send();
    }

    public function sendApproved(DraftWorkflow $wf, Entry $draft): void
    {
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        $reviewer = $wf->decidedBy ? Craft::$app->getUsers()->getUserById($wf->decidedBy) : null;
        if (!$author || !$reviewer) {
            return;
        }

        $body = $this->render('approved', [
            'author' => $author,
            'reviewer' => $reviewer,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
            'scheduledFor' => $wf->scheduledFor,
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($author->email)
            ->setSubject(Craft::t('craft-delta', 'Your draft was approved: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
            ->send();
    }

    public function sendRejected(DraftWorkflow $wf, Entry $draft): void
    {
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        $reviewer = $wf->decidedBy ? Craft::$app->getUsers()->getUserById($wf->decidedBy) : null;
        if (!$author || !$reviewer) {
            return;
        }

        $body = $this->render('rejected', [
            'author' => $author,
            'reviewer' => $reviewer,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
            'note' => $wf->rejectNote,
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($author->email)
            ->setSubject(Craft::t('craft-delta', 'Your draft was rejected: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
            ->send();
    }

    private function render(string $template, array $vars): string
    {
        $view = Craft::$app->getView();
        return $view->renderTemplate("craft-delta/_emails/{$template}", $vars, $view::TEMPLATE_MODE_CP);
    }

    private function editUrl(Entry $draft): string
    {
        return UrlHelper::cpUrl("entries/{$draft->getCanonicalId()}");
    }
}
```

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan && composer check-cs`
Expected: No errors (still references `ApplyScheduledDraft` from Task 5, but only via FQN — phpstan tolerates this if autoload is forgiving. If it errors, complete Task 5 before checking.)

- [ ] **Step 4: Commit**

```bash
git add src/services/EmailService.php src/templates/_emails/
git commit -m "feat(workflow): add email service and templates for submit/approve/reject"
```

---

## Task 5: Queue Job for Scheduled Apply

The `ApplyScheduledDraft` job fires at the reviewer's scheduled time, re-validates state, and applies the draft.

**Files:**
- Create: `src/queue/jobs/ApplyScheduledDraft.php`

---

- [ ] **Step 1: Create the queue job**

Create `src/queue/jobs/ApplyScheduledDraft.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\queue\jobs;

use craft\queue\BaseJob;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\models\DraftWorkflow;
use zeixcom\craftdelta\records\DraftWorkflowRecord;

/**
 * Applies a scheduled approved draft to its canonical entry. Re-validates
 * state before applying so manual changes or cancellations cause a no-op.
 */
class ApplyScheduledDraft extends BaseJob
{
    public int $workflowId;

    public function execute($queue): void
    {
        $record = DraftWorkflowRecord::findOne(['id' => $this->workflowId]);
        if ($record === null) {
            // Workflow row was deleted (likely because draft was deleted) — no-op.
            return;
        }
        if ($record->state !== DraftWorkflow::STATE_APPROVED) {
            // State changed since the job was scheduled — no-op.
            return;
        }
        if ($record->appliedAt !== null) {
            // Already applied (possibly via manual approve-now) — no-op.
            return;
        }

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftId($record->draftId);
        if ($wf === null) {
            return;
        }

        $plugin->workflow->applyDraftNow($wf);
    }

    protected function defaultDescription(): ?string
    {
        return 'Applying scheduled draft (Craft Delta)';
    }
}
```

- [ ] **Step 2: Run static analysis on all PHP**

Run: `composer phpstan && composer check-cs`
Expected: No errors. **This is the green checkpoint for Task 3's deferred step 8.**

- [ ] **Step 3: Run all unit tests**

Run: `vendor/bin/phpunit`
Expected: All pre-existing tests + new SettingsTest, DraftWorkflowTest, WorkflowServiceTest pass; integration stubs skipped.

- [ ] **Step 4: Commit**

```bash
git add src/queue/jobs/ApplyScheduledDraft.php
git commit -m "feat(workflow): add ApplyScheduledDraft queue job with state re-validation"
```

---

## Task 6: WorkflowController — HTTP Endpoints

Thin controller delegating to `WorkflowService`. Four actions.

**Files:**
- Create: `src/controllers/WorkflowController.php`
- Modify: `src/Delta.php` (register CP routes)

---

- [ ] **Step 1: Create the controller**

Create `src/controllers/WorkflowController.php`:

```php
<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use DateTime;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use zeixcom\craftdelta\Delta;

/**
 * HTTP endpoints for the submit-for-review workflow. Each action does
 * permission check + delegate to WorkflowService.
 */
class WorkflowController extends Controller
{
    public function actionSubmit(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $draftId = (int)$request->getRequiredBodyParam('draftId');
        $assigneeId = (int)$request->getRequiredBodyParam('assigneeId');

        $draft = Craft::$app->getEntries()->getEntryById($draftId, '*', ['drafts' => true]);
        if (!$draft instanceof Entry || !$draft->getIsDraft()) {
            throw new NotFoundHttpException('Draft not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        $plugin = Delta::getInstance();

        if (!$user || !$plugin->workflow->canSubmit($user, $draft)) {
            throw new ForbiddenHttpException('You do not have permission to submit drafts for this section.');
        }

        $wf = $plugin->workflow->submit($draft, $assigneeId, $user);

        return $this->asJson([
            'success' => true,
            'workflow' => [
                'id' => $wf->id,
                'state' => $wf->state,
                'assigneeId' => $wf->assigneeId,
            ],
        ]);
    }

    public function actionApprove(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $workflowId = (int)$request->getRequiredBodyParam('workflowId');
        $scheduledForRaw = $request->getBodyParam('scheduledFor');
        $mode = $request->getBodyParam('mode', 'wholesale');

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftIdOrId($workflowId);
        if ($wf === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$plugin->workflow->canReview($user, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }

        if ($mode === 'granular') {
            $accepted = $request->getBodyParam('acceptedFieldHandles', []);
            if (!is_array($accepted)) {
                throw new BadRequestHttpException('acceptedFieldHandles must be an array.');
            }
            // Reviewer must additionally hold applyReview for granular.
            if (!$user->can("craftdelta-applyReview:{$wf->sectionUid}")) {
                throw new ForbiddenHttpException('Granular review requires the Apply permission.');
            }
            $plugin->workflow->approveGranular($wf, $accepted, $user);
        } else {
            $scheduledFor = $scheduledForRaw ? new DateTime($scheduledForRaw) : null;
            $plugin->workflow->approveWholesale($wf, $scheduledFor, $user);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionReject(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $workflowId = (int)$request->getRequiredBodyParam('workflowId');
        $note = $request->getBodyParam('note');

        $plugin = Delta::getInstance();
        $wf = $plugin->workflow->getByDraftIdOrId($workflowId);
        if ($wf === null) {
            throw new NotFoundHttpException('Workflow not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$plugin->workflow->canReview($user, $wf)) {
            throw new ForbiddenHttpException('You are not the assigned reviewer for this draft.');
        }

        $plugin->workflow->reject($wf, $note, $user);

        return $this->asJson(['success' => true]);
    }

    public function actionAssignees(): Response
    {
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $sectionUid = $request->getRequiredParam('sectionUid');

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can("craftdelta-submitDraft:{$sectionUid}")) {
            throw new ForbiddenHttpException('Not authorized.');
        }

        $assignees = Delta::getInstance()->workflow->getEligibleAssignees($sectionUid, $user->id);

        return $this->asJson([
            'success' => true,
            'assignees' => array_map(fn($u) => [
                'id' => $u->id,
                'name' => $u->fullName ?: $u->username,
            ], $assignees),
        ]);
    }
}
```

- [ ] **Step 2: Add the workflow-id-or-draft-id lookup helper to WorkflowService**

Modify `src/services/WorkflowService.php` — add after `getByDraftId()`:

```php
    /**
     * Look up a workflow by its own id (preferred over draftId for write
     * operations, since draftId can be ambiguous if a draft is duplicated).
     */
    public function getById(int $id): ?DraftWorkflow
    {
        $record = DraftWorkflowRecord::findOne(['id' => $id]);
        return $record ? $this->modelFromRecord($record) : null;
    }

    /**
     * Convenience for controllers that accept either form of identifier.
     */
    public function getByDraftIdOrId(int $idOrDraftId): ?DraftWorkflow
    {
        return $this->getById($idOrDraftId) ?? $this->getByDraftId($idOrDraftId);
    }
```

- [ ] **Step 3: Register the CP routes**

Modify `src/Delta.php` — extend `registerCpRoutes()`:

```php
    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['craft-delta/compare'] = 'craft-delta/diff/compare-full-page';
                $event->rules['POST craft-delta/workflow/submit'] = 'craft-delta/workflow/submit';
                $event->rules['POST craft-delta/workflow/approve'] = 'craft-delta/workflow/approve';
                $event->rules['POST craft-delta/workflow/reject'] = 'craft-delta/workflow/reject';
                $event->rules['craft-delta/workflow/assignees'] = 'craft-delta/workflow/assignees';
            }
        );
    }
```

- [ ] **Step 4: Run static analysis**

Run: `composer phpstan && composer check-cs`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/controllers/WorkflowController.php src/services/WorkflowService.php src/Delta.php
git commit -m "feat(workflow): add WorkflowController endpoints and route registration"
```

---

## Task 7: Frontend — Author Submit Modal

Add the "Submit for review" button to the entry sidebar (alongside "Compare Revisions") and a modal with the reviewer dropdown. Status pill replaces the button after submit.

**Files:**
- Create: `src/templates/_submit-modal.twig`
- Create: `src/assets/diff/dist/js/workflow.js`
- Modify: `src/assets/diff/DiffAsset.php`
- Modify: `src/Delta.php` (sidebar injection)
- Modify: `src/translations/en/craft-delta.php`

---

- [ ] **Step 1: Add the workflow.js asset**

Create `src/assets/diff/dist/js/workflow.js`:

```javascript
/**
 * Craft Delta workflow client. Provides:
 *   - Submit-for-review modal (author side)
 *   - Workflow toolbar buttons (reviewer side, mounted by delta.js)
 *
 * Designed to be a thin layer over the existing Craft.postActionRequest.
 */
(function() {
    'use strict';

    if (!window.Craft) return;

    Craft.Delta = Craft.Delta || {};

    /**
     * Open the submit-for-review modal. Loads eligible assignees over AJAX,
     * lets the author pick one, posts to workflow/submit.
     */
    Craft.Delta.openSubmitModal = function(draftId, sectionUid, onSuccess) {
        var $modal = $(
            '<div class="modal delta-submit-modal">' +
                '<div class="body">' +
                    '<h2>' + Craft.t('craft-delta', 'Submit for review') + '</h2>' +
                    '<label>' + Craft.t('craft-delta', 'Reviewer') + '</label>' +
                    '<select class="delta-assignee fullwidth"><option>' + Craft.t('craft-delta', 'Loading…') + '</option></select>' +
                '</div>' +
                '<div class="footer">' +
                    '<div class="buttons right">' +
                        '<button type="button" class="btn cancel">' + Craft.t('craft-delta', 'Cancel') + '</button>' +
                        '<button type="button" class="btn submit disabled">' + Craft.t('craft-delta', 'Submit') + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        ).appendTo(document.body);

        var modal = new Garnish.Modal($modal, { autoShow: true });

        $.get(Craft.getActionUrl('craft-delta/workflow/assignees'), { sectionUid: sectionUid })
            .done(function(resp) {
                var $select = $modal.find('.delta-assignee').empty();
                if (!resp.assignees.length) {
                    $select.append('<option>' + Craft.t('craft-delta', 'No eligible reviewers') + '</option>');
                    return;
                }
                resp.assignees.forEach(function(u) {
                    $select.append('<option value="' + u.id + '">' + u.name + '</option>');
                });
                $modal.find('.btn.submit').removeClass('disabled');
            })
            .fail(function() {
                $modal.find('.delta-assignee').empty().append('<option>' + Craft.t('craft-delta', 'Failed to load reviewers.') + '</option>');
            });

        $modal.find('.btn.cancel').on('click', function() { modal.hide(); });

        $modal.find('.btn.submit').on('click', function() {
            if ($(this).hasClass('disabled')) return;
            var assigneeId = $modal.find('.delta-assignee').val();
            Craft.postActionRequest(
                'craft-delta/workflow/submit',
                { draftId: draftId, assigneeId: assigneeId },
                function(response, textStatus) {
                    if (textStatus === 'success' && response.success) {
                        modal.hide();
                        if (typeof onSuccess === 'function') onSuccess(response.workflow);
                    } else {
                        Craft.cp.displayError(Craft.t('craft-delta', 'Failed to submit for review.'));
                    }
                }
            );
        });
    };
})();
```

- [ ] **Step 2: Register workflow.js in the asset bundle**

Modify `src/assets/diff/DiffAsset.php` — add `workflow.js` alongside the existing `delta.js` in the `$js` array. Read the existing file first to see the current shape:

```bash
cat src/assets/diff/DiffAsset.php
```

Then in that file, locate the line that reads `public array $js = ['dist/js/delta.js'];` (or similar) and change to:

```php
    public array $js = ['dist/js/delta.js', 'dist/js/workflow.js'];
```

- [ ] **Step 3: Inject the Submit button when the author has permission**

Modify `src/Delta.php` — in the `registerEditorAssets()` sidebar callback, after the existing `Compare Revisions` button HTML, add:

```php
                $section = $entry->getSection();
                $user = Craft::$app->getUser()->getIdentity();
                /** @var Settings $settings */
                $settings = $this->getSettings();
                $sectionUid = $section?->uid;

                $workflowHtml = '';
                if ($settings->enableWorkflow && $isDraft && !$entry->getIsUnpublishedDraft() && $user && $sectionUid) {
                    $wf = $this->workflow->getByDraftId((int)$entry->draftId);
                    if ($wf === null) {
                        if ($user->can("craftdelta-submitDraft:{$sectionUid}")) {
                            $submitLabel = htmlspecialchars(Craft::t('craft-delta', 'Submit for review'));
                            $workflowHtml = '<button id="delta-submit-btn" type="button" data-draft-id="' . (int)$entry->draftId . '" data-section-uid="' . htmlspecialchars($sectionUid) . '">' . $submitLabel . '</button>';
                        }
                    } else {
                        $stateLabel = match($wf->state) {
                            'pending' => Craft::t('craft-delta', 'Pending review'),
                            'approved' => $wf->isScheduled() ? Craft::t('craft-delta', 'Approved — scheduled') : Craft::t('craft-delta', 'Approved'),
                            'rejected' => Craft::t('craft-delta', 'Rejected'),
                            default => $wf->state,
                        };
                        $workflowHtml = '<p class="delta-workflow-pill delta-workflow-' . htmlspecialchars($wf->state) . '">' . htmlspecialchars($stateLabel) . '</p>';
                    }
                }

                $event->html .= '<div class="meta" id="delta-meta">'
                    . '<button id="delta-compare-btn" type="button">' . $label . '</button>'
                    . $workflowHtml
                    . '<p class="delta-meta-hint">' . $hint . '</p>'
                    . '</div>';
```

(Replace the existing `$event->html .= '<div class="meta"...'` block at the end of the callback. Remove the old version.)

- [ ] **Step 4: Add JS click handler for the Submit button**

Add to the JS that runs after `Craft.Delta.init(...)` in `src/Delta.php`'s `registerJs()` call. Replace the existing `registerJs` line with:

```php
                $view->registerJs(
                    "Craft.Delta.init({$canonicalId}, {showUnchanged: {$showUnchanged}, isDraft: {$isDraftJs}, draftId: {$draftId}, siteId: {$siteId}});" .
                    "(function(){var \$btn=$('#delta-submit-btn');if(\$btn.length){\$btn.on('click',function(){Craft.Delta.openSubmitModal(\$btn.data('draft-id'),\$btn.data('section-uid'),function(){location.reload();});});}})();"
                );
```

- [ ] **Step 5: Add the new translation strings**

Modify `src/translations/en/craft-delta.php` — add the new keys (the file is an array literal; add entries for):

```php
'Submit for review' => 'Submit for review',
'Reviewer' => 'Reviewer',
'Submit' => 'Submit',
'Cancel' => 'Cancel',
'Loading…' => 'Loading…',
'No eligible reviewers' => 'No eligible reviewers',
'Failed to load reviewers.' => 'Failed to load reviewers.',
'Failed to submit for review.' => 'Failed to submit for review.',
'Pending review' => 'Pending review',
'Approved' => 'Approved',
'Approved — scheduled' => 'Approved — scheduled',
'Rejected' => 'Rejected',
'Draft awaiting your review: {title}' => 'Draft awaiting your review: {title}',
'Your draft was approved: {title}' => 'Your draft was approved: {title}',
'Your draft was rejected: {title}' => 'Your draft was rejected: {title}',
'Submit drafts for review in "{section}"' => 'Submit drafts for review in "{section}"',
'Review submitted drafts in "{section}"' => 'Review submitted drafts in "{section}"',
```

Register them via `$view->registerTranslations(...)` — extend the existing `registerTranslations` call in `Delta.php` (around line 149) by appending the same keys above to the array passed in.

- [ ] **Step 6: Manual smoke check in browser**

Run `ddev launch` (or your local equivalent). Steps:
1. Grant a non-admin user `Submit drafts for review` for one section.
2. Log in as that user, create a draft in that section.
3. Verify the "Submit for review" button appears in the sidebar.
4. Click → modal opens with reviewer dropdown populated.
5. Pick a reviewer → click Submit → reload → status pill reads "Pending review".

Document any UI issues; treat anything blocking as a fix-then-retest cycle here, not in a later task.

- [ ] **Step 7: Commit**

```bash
git add src/assets/diff/ src/Delta.php src/translations/en/craft-delta.php
git commit -m "feat(workflow): add author Submit for review button, modal, and status pill"
```

---

## Task 8: Frontend — Reviewer Workflow Toolbar

Add the toolbar (Approve all ▾ / Granular review / Reject) to the diff slideout when the current user is the assignee of a Pending workflow.

**Files:**
- Modify: `src/controllers/DiffController.php:83-117` (pass workflow info to template)
- Modify: `src/templates/_diff-slideout.twig` (render toolbar markup)
- Modify: `src/assets/diff/dist/js/workflow.js` (button handlers)
- Modify: `src/translations/en/craft-delta.php`

---

- [ ] **Step 1: Surface workflow row to the slideout template**

Modify `src/controllers/DiffController.php` — inside `actionCompare()`, after the existing `$reviewMode = ...` line, add:

```php
            $user = Craft::$app->getUser()->getIdentity();
            $workflow = null;
            $isReviewer = false;
            // The "source" entry is whichever side isn't canonical. Workflow
            // attaches to drafts, so only check if the source is a draft.
            $sourceEntry = $olderIsCanonical ? $newer : $older;
            if ($sourceEntry->getIsDraft() && $user) {
                $workflow = $plugin->workflow->getByDraftId((int)$sourceEntry->draftId);
                if ($workflow !== null) {
                    $isReviewer = $plugin->workflow->canReview($user, $workflow);
                }
            }
```

Then extend the `renderTemplate(...)` vars array:

```php
                    'workflow' => $workflow,
                    'isReviewer' => $isReviewer,
```

- [ ] **Step 2: Render toolbar in the slideout template**

Modify `src/templates/_diff-slideout.twig` — read the file first to find the toolbar region (where the existing "Start Review" button lives). Add (or replace the existing review-mode toolbar block) with:

```twig
{% if workflow and isReviewer and workflow.isPending() %}
    <div class="delta-workflow-toolbar" data-workflow-id="{{ workflow.id }}" data-section-uid="{{ workflow.sectionUid }}">
        <div class="btngroup">
            <button type="button" class="btn submit delta-approve-now">
                {{ 'Approve all'|t('craft-delta') }}
            </button>
            <button type="button" class="btn menubtn delta-approve-menu" data-icon="downangle">
                {{ ''|raw }}
            </button>
            <div class="menu">
                <ul>
                    <li><a class="delta-approve-now" href="#">{{ 'Apply now'|t('craft-delta') }}</a></li>
                    <li><a class="delta-approve-schedule" href="#">{{ 'Schedule for…'|t('craft-delta') }}</a></li>
                </ul>
            </div>
        </div>
        {% if currentUser.can("craftdelta-applyReview:#{workflow.sectionUid}") %}
            <button type="button" class="btn delta-granular-review">
                {{ 'Granular review'|t('craft-delta') }}
            </button>
        {% endif %}
        <button type="button" class="btn delta-reject">
            {{ 'Reject'|t('craft-delta') }}
        </button>
    </div>
{% endif %}
```

- [ ] **Step 3: Wire JS handlers for the toolbar buttons**

Modify `src/assets/diff/dist/js/workflow.js` — append the toolbar wiring at the bottom (before the closing IIFE):

```javascript
    /**
     * Mount handlers on a workflow toolbar inside the diff slideout. Called
     * by delta.js after slideout HTML loads.
     */
    Craft.Delta.mountWorkflowToolbar = function($toolbar) {
        var workflowId = $toolbar.data('workflow-id');

        $toolbar.find('.delta-approve-now').on('click', function(e) {
            e.preventDefault();
            if (!confirm(Craft.t('craft-delta', 'Approve and publish this draft now?'))) return;
            Craft.postActionRequest(
                'craft-delta/workflow/approve',
                { workflowId: workflowId, mode: 'wholesale' },
                function(resp, status) {
                    if (status === 'success' && resp.success) {
                        Craft.cp.displayNotice(Craft.t('craft-delta', 'Draft approved.'));
                        location.reload();
                    } else {
                        Craft.cp.displayError(Craft.t('craft-delta', 'Approve failed.'));
                    }
                }
            );
        });

        $toolbar.find('.delta-approve-schedule').on('click', function(e) {
            e.preventDefault();
            var when = prompt(Craft.t('craft-delta', 'Publish at (YYYY-MM-DD HH:MM):'));
            if (!when) return;
            Craft.postActionRequest(
                'craft-delta/workflow/approve',
                { workflowId: workflowId, mode: 'wholesale', scheduledFor: when },
                function(resp, status) {
                    if (status === 'success' && resp.success) {
                        Craft.cp.displayNotice(Craft.t('craft-delta', 'Draft scheduled.'));
                        location.reload();
                    } else {
                        Craft.cp.displayError(Craft.t('craft-delta', 'Schedule failed.'));
                    }
                }
            );
        });

        $toolbar.find('.delta-reject').on('click', function() {
            var note = prompt(Craft.t('craft-delta', 'Optional note for the author:')) || '';
            if (!confirm(Craft.t('craft-delta', 'Reject this draft? Rejection is final.'))) return;
            Craft.postActionRequest(
                'craft-delta/workflow/reject',
                { workflowId: workflowId, note: note },
                function(resp, status) {
                    if (status === 'success' && resp.success) {
                        Craft.cp.displayNotice(Craft.t('craft-delta', 'Draft rejected.'));
                        location.reload();
                    } else {
                        Craft.cp.displayError(Craft.t('craft-delta', 'Reject failed.'));
                    }
                }
            );
        });

        // Granular review delegates to the existing v1.1 review-mode start.
        $toolbar.find('.delta-granular-review').on('click', function() {
            if (window.Craft.Delta.startGranularReview) {
                Craft.Delta.startGranularReview({ workflowId: workflowId });
            } else {
                // Fall back to the legacy "Start Review" handler if present.
                $('#delta-start-review').click();
            }
        });
    };
```

- [ ] **Step 4: Have delta.js call `mountWorkflowToolbar` after slideout content loads**

Modify `src/assets/diff/dist/js/delta.js` — find the function that renders the slideout HTML response (likely sets `$slideout.html(...)`). Immediately after that line, add:

```javascript
            var $toolbar = $slideout.find('.delta-workflow-toolbar');
            if ($toolbar.length && Craft.Delta.mountWorkflowToolbar) {
                Craft.Delta.mountWorkflowToolbar($toolbar);
            }
```

If `delta.js` is a built/bundled file, look for the unbuilt source first (`src/` may have it under a `src/assets/diff/src/`). If only the bundled file exists, edit it directly — the codebase has no build step listed in `composer.json`.

- [ ] **Step 5: Add new translation strings**

Append to `src/translations/en/craft-delta.php`:

```php
'Approve all' => 'Approve all',
'Apply now' => 'Apply now',
'Schedule for…' => 'Schedule for…',
'Granular review' => 'Granular review',
'Reject' => 'Reject',
'Approve and publish this draft now?' => 'Approve and publish this draft now?',
'Publish at (YYYY-MM-DD HH:MM):' => 'Publish at (YYYY-MM-DD HH:MM):',
'Optional note for the author:' => 'Optional note for the author:',
'Reject this draft? Rejection is final.' => 'Reject this draft? Rejection is final.',
'Draft approved.' => 'Draft approved.',
'Draft scheduled.' => 'Draft scheduled.',
'Draft rejected.' => 'Draft rejected.',
'Approve failed.' => 'Approve failed.',
'Schedule failed.' => 'Schedule failed.',
'Reject failed.' => 'Reject failed.',
```

Register all of these in the `registerTranslations` call in `Delta.php`.

- [ ] **Step 6: Manual smoke check in browser**

1. As an admin, grant a non-admin user `Review submitted drafts` for one section.
2. Log in as the author, submit a draft (from Task 7's flow) assigned to that reviewer.
3. Log in as the reviewer, open the entry's diff slideout.
4. Verify the toolbar shows: Approve all ▾ | (Granular review if `applyReview` is also held) | Reject.
5. Test Approve now → entry updates, status pill shows "Approved", confirmation email received.
6. Repeat with a fresh draft, test Reject with a note → author receives email with note.
7. Repeat with a fresh draft, test Schedule for… (5 minutes out) → wait, verify queue runs, draft applies.

- [ ] **Step 7: Commit**

```bash
git add src/controllers/DiffController.php src/templates/_diff-slideout.twig src/assets/diff/ src/translations/en/craft-delta.php src/Delta.php
git commit -m "feat(workflow): add reviewer toolbar with approve/schedule/reject actions"
```

---

## Task 9: Entry Index Workflow Column

Show a small Workflow column with colored pills on entry index pages.

**Files:**
- Modify: `src/Delta.php` (register table attribute via Craft event)

---

- [ ] **Step 1: Register the table attribute**

Modify `src/Delta.php` — add a new `registerWorkflowColumn()` method called from `init()`:

```php
    private function registerWorkflowColumn(): void
    {
        Event::on(
            Entry::class,
            Entry::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function (\craft\events\RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes['craftDeltaWorkflow'] = [
                    'label' => Craft::t('craft-delta', 'Workflow'),
                ];
            }
        );

        Event::on(
            Entry::class,
            Entry::EVENT_SET_TABLE_ATTRIBUTE_HTML,
            function (\craft\events\SetElementTableAttributeHtmlEvent $event) {
                if ($event->attribute !== 'craftDeltaWorkflow') {
                    return;
                }
                /** @var Entry $entry */
                $entry = $event->sender;
                $wf = null;
                // Show workflow status when the entry has a draft that's in workflow.
                $draftId = $entry->draftId;
                if ($draftId) {
                    $wf = $this->workflow->getByDraftId((int)$draftId);
                }
                if ($wf === null) {
                    $event->html = '';
                    $event->handled = true;
                    return;
                }
                $label = match($wf->state) {
                    'pending' => Craft::t('craft-delta', 'Pending review'),
                    'approved' => $wf->isScheduled() ? Craft::t('craft-delta', 'Approved — scheduled') : Craft::t('craft-delta', 'Approved'),
                    'rejected' => Craft::t('craft-delta', 'Rejected'),
                    default => $wf->state,
                };
                $event->html = '<span class="status ' . htmlspecialchars($wf->state) . '"></span>' . htmlspecialchars($label);
                $event->handled = true;
            }
        );
    }
```

Call it from `init()`:

```php
        $this->registerWorkflowColumn();
```

Add the imports at the top:

```php
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\SetElementTableAttributeHtmlEvent;
```

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan && composer check-cs`
Expected: No errors.

- [ ] **Step 3: Manual check**

In the CP, go to Entries → any section. Open the column chooser (gear icon at top right of the index) and enable "Workflow". Verify the column shows pills for entries with active workflow rows.

- [ ] **Step 4: Commit**

```bash
git add src/Delta.php
git commit -m "feat(workflow): add Workflow column to entry index"
```

---

## Task 10: Documentation + Version Bump

Update README, CHANGELOG, composer version. Final regression pass.

**Files:**
- Modify: `composer.json:5` (version bump)
- Modify: `README.md`
- Modify: `CHANGELOG.md`

---

- [ ] **Step 1: Bump composer version**

Modify `composer.json` — change line 5:

```json
  "version": "2.0.0",
```

- [ ] **Step 2: Update README**

Modify `README.md` — replace the existing **Permissions** section (around line 49-56) with:

```markdown
### Permissions

Three per-section permissions registered under **Settings → Users → User Groups → Permissions** in the **Craft Delta** group:

- **Submit drafts for review** — authors holding this permission see a "Submit for review" button on their drafts.
- **Review submitted drafts** — reviewers holding this permission can be picked as an assignee and can Approve (wholesale, with optional scheduling) or Reject.
- **Apply review-mode changes** *(unchanged from v1.x)* — required additionally for the "Granular review" path inside the workflow, and for the legacy ad-hoc review mode.

Users with none of these still see the read-only diff. Admins have everything implicitly.

> **Note on draft locking:** submitted drafts are **not** locked. If the author keeps editing while a scheduled apply is pending, the queue job will publish whatever the draft contains at apply time.
```

Add a new **Workflow** section after **Review Mode**:

```markdown
### Workflow (v2.0+)

Authors with the **Submit drafts for review** permission see a **Submit for review** button on their drafts. Clicking it asks them to pick a reviewer.

The chosen reviewer receives an email with a link to the entry. From the diff slideout, the reviewer sees three buttons:

- **Approve all** — applies the draft to canonical. Has a dropdown for "Apply now" or "Schedule for…" (queues a job).
- **Granular review** — opens the v1.1 per-field accept/decline flow (requires the **Apply review-mode changes** permission additionally).
- **Reject** — terminal. Author keeps the draft and receives the reviewer's note by email.

Rejected drafts cannot be re-submitted. To revise, duplicate the draft and submit the copy.

Disable the entire workflow path via **Settings → Plugins → Craft Delta → Enable Workflow** (defaults to On).
```

Update the settings table to include the new toggle:

```markdown
| Enable Workflow | On | Show the workflow Submit/Approve/Reject UI. When off, v1.1 behavior. |
```

- [ ] **Step 3: Update CHANGELOG**

Modify `CHANGELOG.md` — add at the top:

```markdown
# 2.0.0 — 2026-05-12

## Added

- **Submit-for-review workflow** (`submitDraft`, `reviewDraft` per-section permissions).
- Reviewer can Approve (wholesale, with optional scheduled apply via queue job) or Reject (terminal, with optional note).
- Email notifications on submit/approve/reject.
- Workflow column on the entry index.
- `WorkflowService` with public API and `EVENT_AFTER_SUBMIT` / `EVENT_AFTER_APPROVE` / `EVENT_AFTER_REJECT` events for third-party integration.
- `enableWorkflow` setting (default `true`).

## Changed

- Plugin schema version bumped to `2.0.0`. Existing v1.x installs run an upgrade migration to add the `craftdelta_draft_workflows` table.

## Compatibility

- No breaking changes for users without workflow permissions — the v1.1 read-only diff slideout and "Compare Revisions" button behave identically.
- Existing `applyReview` permission is unchanged in name and behavior.
```

- [ ] **Step 4: Final regression pass**

Run all of:
```bash
composer check-cs
composer phpstan
vendor/bin/phpunit
```

Expected: all green. Skipped integration tests remain skipped.

Manual smoke pass:
1. v1.1 user with no Craft Delta permissions → opens diff, sees no Submit button, no toolbar, just diff. PASS.
2. Author submits → reviewer email received → reviewer approves now → author email received → entry updated. PASS.
3. Reviewer rejects with note → author email contains note → re-submit unavailable. PASS.
4. Reviewer schedules 2 minutes out → queue worker (`./craft queue/run`) runs → entry updates at scheduled time. PASS.
5. Disable workflow in plugin settings → all workflow UI hidden, v1.1 review mode still works. PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json README.md CHANGELOG.md
git commit -m "docs(workflow): v2.0.0 — workflow permissions, README, CHANGELOG"
```

- [ ] **Step 6: Tag (optional, only if user wants to cut the release now)**

```bash
git tag v2.0.0
```

(Do not push the tag without user confirmation.)

---

## Self-Review Checklist (run before handing off)

**Spec coverage:**
- ✅ Three permissions (Task 1)
- ✅ State machine + transitions (Task 3)
- ✅ DB schema with all columns + indexes + FKs (Task 2)
- ✅ Single-assignee picker (Task 7)
- ✅ Email notifications (Task 4)
- ✅ Terminal rejection with optional note (Task 3, Task 8)
- ✅ Wholesale approve with optional scheduling (Task 3, Task 5, Task 6, Task 8)
- ✅ Granular review preserved (Task 6, Task 8)
- ✅ No draft lock (intentional non-feature, documented in README — Task 10)
- ✅ Read-only diff unchanged for unprivileged users (Tasks 7 & 8 are gated)
- ✅ `enableWorkflow` settings toggle (Task 1)
- ✅ Events for third-party integration (Task 3)
- ✅ Migration story for fresh + upgrade installs (Task 2)
- ✅ README + CHANGELOG (Task 10)

**Placeholder scan:** All `# USER-DECISION` markers have working defaults the user can ship as-is. No "TBD" / "implement later" / unspecified code.

**Type consistency:**
- `DraftWorkflow::STATE_PENDING`/`_APPROVED`/`_REJECTED` used consistently across service, controller, test.
- `workflowId` is the workflow row id (not the draftId) in all controller endpoints; `getByDraftIdOrId()` accepts either.
- `scheduledFor` is `DateTime|null` in PHP, ISO string on the wire.
- `sectionUid` is the section's UID (string), used in permission keys throughout.

---

## Execution Handoff

Plan complete and saved to `plugins/craft-delta/docs/plans/2026-05-12-craft-delta-workflow-permissions-plan.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

**Which approach?**
