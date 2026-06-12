<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\i18n;

/**
 * Stable translation keys for craft-delta. Use these instead of English
 * message strings in Craft::t(), Twig |t, and Craft.t() calls.
 */
final class TranslationKeys
{
    public const APPLY_COUNT_ACCEPTED = 'review.applyCountAccepted';
    public const DECIDED_OF_TOTAL = 'review.decidedOfTotal';
    public const NEED_TWO_REVISIONS = 'diff.needTwoRevisions';
    public const CHANGED_ONLY = 'diff.changedOnly';
    public const CLOSE = 'ui.close';
    public const COMPARE_REVISIONS = 'diff.compareRevisions';
    public const COMPARING = 'diff.comparing';
    public const CURRENT_DRAFT = 'diff.currentDraft';
    public const CURRENT = 'diff.current';
    public const DRAFTS = 'diff.drafts';
    public const EXPAND = 'ui.expand';
    public const FAILED_LOAD_DIFF = 'error.failedLoadDiff';
    public const FAILED_LOAD_REVISIONS = 'error.failedLoadRevisions';
    public const LOADING_REVISIONS = 'diff.loadingRevisions';
    public const OPEN_FULL_PAGE = 'diff.openFullPage';
    public const REVISIONS = 'diff.revisions';
    public const RESUME_PREVIOUS_REVIEW = 'review.resumePrevious';
    public const RESUME = 'review.resume';
    public const START_FRESH = 'review.startFresh';
    public const ENTRY_CHANGED_SINCE_LAST_REVIEW = 'review.entryChangedSinceLastReview';
    public const ENTRY_CHANGED_SINCE_REVIEW_STARTED = 'review.entryChangedSinceReviewStarted';
    public const DISCARD_DECISIONS = 'review.discardDecisions';
    public const PUBLISH_ACCEPTED_CONFIRM = 'review.publishAcceptedConfirm';
    public const CHANGES_PUBLISHED_OPEN_ENTRY = 'review.changesPublishedOpenEntry';
    public const VALIDATION_FAILED = 'error.validationFailed';
    public const APPLY_FAILED = 'error.applyFailed';
    public const NO_CHANGES_TO_APPLY = 'review.noChangesToApply';
    public const DECISIONS_SAVED_RETRY = 'review.decisionsSavedRetry';
    public const DECISIONS_STILL_SAVED = 'review.decisionsStillSaved';
    public const SUBMIT_FOR_REVIEW = 'workflow.submitForReview';
    public const REVIEWER = 'workflow.reviewer';
    public const SUBMIT = 'ui.submit';
    public const CANCEL = 'ui.cancel';
    public const LOADING = 'ui.loading';
    public const NO_ELIGIBLE_REVIEWERS = 'workflow.noEligibleReviewers';
    public const FAILED_LOAD_REVIEWERS = 'error.failedLoadReviewers';
    public const FAILED_SUBMIT_FOR_REVIEW = 'error.failedSubmitForReview';
    public const PENDING_REVIEW = 'workflow.pendingReview';
    public const APPROVED = 'workflow.approved';
    public const APPROVED_SCHEDULED = 'workflow.approvedScheduled';
    public const REJECTED = 'workflow.rejected';
    public const APPROVE_ALL = 'workflow.approveAll';
    public const SCHEDULE_FOR = 'workflow.scheduleFor';
    public const GRANULAR_REVIEW = 'workflow.granularReview';
    public const REJECT = 'workflow.reject';
    public const APPROVE_PUBLISH_NOW_CONFIRM = 'workflow.approvePublishNowConfirm';
    public const PUBLISH_AT_PROMPT = 'workflow.publishAtPrompt';
    public const OPTIONAL_NOTE_FOR_AUTHOR = 'workflow.optionalNoteForAuthor';
    public const REJECT_DRAFT_CONFIRM = 'workflow.rejectDraftConfirm';
    public const DRAFT_APPROVED = 'workflow.draftApproved';
    public const DRAFT_SCHEDULED = 'workflow.draftScheduled';
    public const DRAFT_REJECTED = 'workflow.draftRejected';
    public const APPROVE_FAILED = 'error.approveFailed';
    public const SCHEDULE_FAILED = 'error.scheduleFailed';
    public const REJECT_FAILED = 'error.rejectFailed';
    public const REVIEW_MODE_UNAVAILABLE = 'review.modeUnavailable';
    public const SHOW_ONLY_CHANGED_FIELDS = 'diff.showOnlyChangedFields';
    public const NO_CHANGES_BETWEEN_REVISIONS = 'diff.noChangesBetweenRevisions';
    public const REV_NUM_CREATOR = 'diff.revNumCreator';
    public const UNKNOWN = 'ui.unknown';
    public const ONE_FIELD_CHANGED = 'diff.oneFieldChanged';
    public const COUNT_FIELDS_CHANGED = 'diff.countFieldsChanged';
    public const NO_CHANGES = 'diff.noChanges';
    public const DIFF_CONTEXT_LINES = 'settings.diffContextLines';
    public const DIFF_CONTEXT_LINES_INSTRUCTIONS = 'settings.diffContextLinesInstructions';
    public const MAX_FIELD_LENGTH = 'settings.maxFieldLength';
    public const MAX_FIELD_LENGTH_INSTRUCTIONS = 'settings.maxFieldLengthInstructions';
    public const SHOW_UNCHANGED_FIELDS = 'settings.showUnchangedFields';
    public const SHOW_UNCHANGED_FIELDS_INSTRUCTIONS = 'settings.showUnchangedFieldsInstructions';
    public const BLOCKS_REORDERED = 'diff.blocksReordered';
    public const ROW_ADDED = 'diff.rowAdded';
    public const ROW_REMOVED = 'diff.rowRemoved';
    public const ROW_MODIFIED = 'diff.rowModified';
    public const FIELD_TOO_LARGE = 'error.fieldTooLarge';
    public const DRAFT = 'diff.draft';
    public const UNABLE_TO_DIFF_FIELD = 'error.unableToDiffField';
    public const FAILED_GENERATE_DIFF = 'error.failedGenerateDiff';
    public const VIEW_SIDE_BY_SIDE_HINT = 'diff.viewSideBySideHint';
    public const UNABLE_PARSE_STRUCTURED_DIFF = 'error.unableParseStructuredDiff';
    public const UNABLE_PARSE_TABLE_DIFF = 'error.unableParseTableDiff';
    public const START_REVIEW = 'review.start';
    public const CANCEL_REVIEW = 'review.cancel';
    public const ACCEPT = 'review.accept';
    public const PREV = 'ui.prev';
    public const NEXT = 'ui.next';
    public const SOURCE_VERSION_NOT_FOUND = 'error.sourceVersionNotFound';
    public const INSUFFICIENT_PERMISSIONS_CREATE_DRAFT = 'error.insufficientPermissionsCreateDraft';
    public const REVIEW_OF_REF = 'review.ofRef';
    public const ENABLE_REVIEW_MODE = 'settings.enableReviewMode';
    public const ENABLE_REVIEW_MODE_INSTRUCTIONS = 'settings.enableReviewModeInstructions';
    public const ALSO_DELETE_SOURCE_DRAFT = 'review.alsoDeleteSourceDraft';
    public const PERMISSION_SUBMIT_DRAFTS = 'permission.submitDrafts';
    public const PERMISSION_REVIEW_DRAFTS = 'permission.reviewDrafts';
    public const PERMISSION_APPLY_REVIEW = 'permission.applyReview';
    public const PLUGIN_NAME = 'plugin.name';
    public const NO_PERMISSION_APPLY_REVIEW = 'error.noPermissionApplyReview';
    public const WORKFLOW = 'workflow.label';
    public const GENERAL_SETTINGS = 'settings.general';
    public const ENABLE_REVIEW_WORKFLOW = 'settings.enableReviewWorkflow';
    public const ENABLE_REVIEW_WORKFLOW_INSTRUCTIONS = 'settings.enableReviewWorkflowInstructions';
    public const REVIEW_KEYBOARD_HINT = 'review.keyboardHint';
    public const JUMP_TO_SECTION = 'diff.jumpToSection';
    public const TAB = 'diff.tab';
    public const BLOCK = 'diff.block';
    public const NOT_AUTHORIZED = 'error.notAuthorized';
    public const ENTRY_NOT_FOUND = 'error.entryNotFound';
    public const VERSION_NOT_FOUND = 'error.versionNotFound';
    public const NO_PERMISSION_PUBLISH = 'error.noPermissionPublish';
    public const NO_PERMISSION_DELETE_SOURCE_DRAFT = 'error.noPermissionDeleteSourceDraft';
    public const ONLY_ASSIGNED_REVIEWER_MAY_APPLY = 'error.onlyAssignedReviewerMayApply';
    public const COULD_NOT_APPLY_CHANGES = 'error.couldNotApplyChanges';
    public const REVIEW_WORKFLOW_DISABLED = 'error.reviewWorkflowDisabled';
    public const DRAFT_NOT_FOUND = 'error.draftNotFound';
    public const NO_PERMISSION_SUBMIT_SECTION = 'error.noPermissionSubmitSection';
    public const WORKFLOW_NOT_FOUND = 'error.workflowNotFound';
    public const NOT_ASSIGNED_REVIEWER = 'error.notAssignedReviewer';
    public const REV_NUM = 'diff.revNum';
    public const SOURCE = 'diff.source';
    public const EMAIL_DRAFT_AWAITING_REVIEW = 'email.draftAwaitingReview';
    public const EMAIL_DRAFT_APPROVED = 'email.draftApproved';
    public const EMAIL_DRAFT_REJECTED = 'email.draftRejected';
    public const EMAIL_HI_NAME = 'email.hiName';
    public const EMAIL_AUTHOR_SUBMITTED = 'email.authorSubmitted';
    public const EMAIL_OPEN_TO_REVIEW = 'email.openToReview';
    public const EMAIL_SIGNATURE = 'email.signature';
    public const EMAIL_REVIEWER_APPROVED = 'email.reviewerApproved';
    public const EMAIL_SCHEDULED_PUBLISH_AT = 'email.scheduledPublishAt';
    public const EMAIL_CHANGES_APPLIED = 'email.changesApplied';
    public const EMAIL_REVIEWER_REJECTED = 'email.reviewerRejected';
    public const EMAIL_REVIEWER_NOTE = 'email.reviewerNote';
    public const EMAIL_DRAFT_PRESERVED = 'email.draftPreserved';
    public const REVIEW_IN_REVIEW = 'review.inReview';
    public const REVIEW_CHANGES_REQUESTED = 'review.changesRequested';
    public const REVIEW_DECLINED = 'review.declined';
    public const REVIEW_WITHDRAWN = 'review.withdrawn';
    public const REVIEW_PUBLISHED = 'review.published';
    public const ONLY_APPROVED_REVIEW_CAN_PUBLISH = 'error.onlyApprovedReviewCanPublish';
    public const REVIEW_ALREADY_MOVED_ON = 'error.reviewAlreadyMovedOn';
    public const NOT_REVIEWER_FOR_DRAFT = 'error.notReviewerForDraft';
    public const ONLY_AUTHOR_CAN_DO_THAT = 'error.onlyAuthorCanDoThat';
    public const REVIEW_NO_LONGER_OPEN = 'error.reviewNoLongerOpen';
    public const EMAIL_DRAFT_RESUBMITTED = 'email.draftResubmitted';
    public const EMAIL_CHANGES_REQUESTED_ON_DRAFT = 'email.changesRequestedOnDraft';
    public const EMAIL_DRAFT_DECLINED = 'email.draftDeclined';
    public const EMAIL_DRAFT_APPROVED_SCHEDULED = 'email.draftApprovedScheduled';
    public const EMAIL_DRAFT_PUBLISHED = 'email.draftPublished';
    public const EMAIL_BODY_RESUBMITTED = 'email.bodyResubmitted';
    public const EMAIL_BODY_CHANGES_REQUESTED = 'email.bodyChangesRequested';
    public const EMAIL_BODY_REREQUEST_HINT = 'email.bodyReRequestHint';
    public const EMAIL_BODY_DECLINED = 'email.bodyDeclined';
    public const EMAIL_BODY_REVIEW_CLOSED = 'email.bodyReviewClosed';
    public const EMAIL_BODY_PUBLISHED = 'email.bodyPublished';
    // Review-request toolbar / JS (Phase 1). JS-facing strings are rendered into
    // data-* attributes in Twig, so none of these need jsMessageKeys/jsPropertyMap.
    public const WORKFLOW_APPROVE = 'workflow.approve';
    public const WORKFLOW_REQUEST_CHANGES = 'workflow.requestChanges';
    public const WORKFLOW_DECLINE = 'workflow.decline';
    public const WORKFLOW_RE_REQUEST = 'workflow.reRequest';
    public const WORKFLOW_WITHDRAW = 'workflow.withdraw';
    public const WORKFLOW_PUBLISH = 'workflow.publish';
    public const WORKFLOW_REVIEWERS = 'workflow.reviewers';
    public const WORKFLOW_ROUND = 'workflow.round';
    public const WORKFLOW_AWAITING = 'workflow.awaiting';
    public const WORKFLOW_REQUEST_CHANGES_PROMPT = 'workflow.requestChangesPrompt';
    public const WORKFLOW_DECLINE_CONFIRM = 'workflow.declineConfirm';
    public const WORKFLOW_WITHDRAW_CONFIRM = 'workflow.withdrawConfirm';
    public const WORKFLOW_RE_REQUEST_CONFIRM = 'workflow.reRequestConfirm';
    public const WORKFLOW_PUBLISH_CONFIRM = 'workflow.publishConfirm';
    public const WORKFLOW_DONE_APPROVED = 'workflow.doneApproved';
    public const WORKFLOW_DONE_CHANGES_REQUESTED = 'workflow.doneChangesRequested';
    public const WORKFLOW_DONE_DECLINED = 'workflow.doneDeclined';
    public const WORKFLOW_DONE_WITHDRAWN = 'workflow.doneWithdrawn';
    public const WORKFLOW_DONE_RE_REQUESTED = 'workflow.doneReRequested';
    public const WORKFLOW_DONE_PUBLISHED = 'workflow.donePublished';
    public const WORKFLOW_ACTION_FAILED = 'workflow.actionFailed';
    // Reviews dashboard (CP nav landing page).
    public const WORKFLOW_REVIEWS_TITLE = 'workflow.reviewsTitle';
    public const WORKFLOW_ASSIGNED_TO_ME = 'workflow.assignedToMe';
    public const WORKFLOW_MY_SUBMISSIONS = 'workflow.mySubmissions';
    public const WORKFLOW_ALL_REVIEWS = 'workflow.allReviews';
    public const WORKFLOW_NO_REVIEWS = 'workflow.noReviews';
    public const WORKFLOW_COL_ENTRY = 'workflow.colEntry';
    public const WORKFLOW_COL_STATUS = 'workflow.colStatus';
    public const WORKFLOW_COL_ROUND = 'workflow.colRound';
    public const WORKFLOW_COMMENT_FAILED = 'workflow.commentFailed';

    /** @var array<string, string> English source text keyed by translation key */
    private const ENGLISH = [
        self::APPLY_COUNT_ACCEPTED => 'Apply {count} accepted',
        self::DECIDED_OF_TOTAL => '{decided} of {total} decided',
        self::NEED_TWO_REVISIONS => 'At least two revisions are needed to compare.',
        self::CHANGED_ONLY => 'Changed only',
        self::CLOSE => 'Close',
        self::COMPARE_REVISIONS => 'Compare Revisions',
        self::COMPARING => 'Comparing…',
        self::CURRENT_DRAFT => 'Current Draft',
        self::CURRENT => 'Current',
        self::DRAFTS => 'Drafts',
        self::EXPAND => 'Expand',
        self::FAILED_LOAD_DIFF => 'Failed to load diff.',
        self::FAILED_LOAD_REVISIONS => 'Failed to load revisions.',
        self::LOADING_REVISIONS => 'Loading revisions…',
        self::OPEN_FULL_PAGE => 'Open full page',
        self::REVISIONS => 'Revisions',
        self::RESUME_PREVIOUS_REVIEW => 'Resume previous review ({decided} of {total} decided)?',
        self::RESUME => 'Resume',
        self::START_FRESH => 'Start fresh',
        self::ENTRY_CHANGED_SINCE_LAST_REVIEW => 'The entry has changed since your last review; starting fresh.',
        self::ENTRY_CHANGED_SINCE_REVIEW_STARTED => 'The entry has changed since you started reviewing. Please reload the diff and restart your review.',
        self::DISCARD_DECISIONS => 'Discard {decided} decisions?',
        self::PUBLISH_ACCEPTED_CONFIRM => 'Publish {count} accepted changes to this entry? This creates a new revision. Rejected changes will not affect the entry.',
        self::CHANGES_PUBLISHED_OPEN_ENTRY => 'Changes published. Open the entry?',
        self::VALIDATION_FAILED => 'Validation failed.',
        self::APPLY_FAILED => 'Apply failed.',
        self::NO_CHANGES_TO_APPLY => 'No changes to apply.',
        self::DECISIONS_SAVED_RETRY => 'Your decisions are still saved. Adjust and try again.',
        self::DECISIONS_STILL_SAVED => 'Your decisions are still saved.',
        self::SUBMIT_FOR_REVIEW => 'Submit for review',
        self::REVIEWER => 'Reviewer',
        self::SUBMIT => 'Submit',
        self::CANCEL => 'Cancel',
        self::LOADING => 'Loading…',
        self::NO_ELIGIBLE_REVIEWERS => 'No eligible reviewers',
        self::FAILED_LOAD_REVIEWERS => 'Failed to load reviewers.',
        self::FAILED_SUBMIT_FOR_REVIEW => 'Failed to submit for review.',
        self::PENDING_REVIEW => 'Pending review',
        self::APPROVED => 'Approved',
        self::APPROVED_SCHEDULED => 'Approved — scheduled',
        self::REJECTED => 'Rejected',
        self::APPROVE_ALL => 'Approve all',
        self::SCHEDULE_FOR => 'Schedule for…',
        self::GRANULAR_REVIEW => 'Granular review',
        self::REJECT => 'Reject',
        self::APPROVE_PUBLISH_NOW_CONFIRM => 'Approve and publish this draft now?',
        self::PUBLISH_AT_PROMPT => 'Publish at (YYYY-MM-DD HH:MM):',
        self::OPTIONAL_NOTE_FOR_AUTHOR => 'Optional note for the author:',
        self::REJECT_DRAFT_CONFIRM => 'Reject this draft? Rejection is final.',
        self::DRAFT_APPROVED => 'Draft approved.',
        self::DRAFT_SCHEDULED => 'Draft scheduled.',
        self::DRAFT_REJECTED => 'Draft rejected.',
        self::APPROVE_FAILED => 'Approve failed.',
        self::SCHEDULE_FAILED => 'Schedule failed.',
        self::REJECT_FAILED => 'Reject failed.',
        self::REVIEW_MODE_UNAVAILABLE => 'Review mode is not available for this comparison.',
        self::SHOW_ONLY_CHANGED_FIELDS => 'Show only changed fields',
        self::NO_CHANGES_BETWEEN_REVISIONS => 'No changes between these revisions.',
        self::REV_NUM_CREATOR => 'Rev {num} — {creator}',
        self::UNKNOWN => 'Unknown',
        self::ONE_FIELD_CHANGED => '1 field changed',
        self::COUNT_FIELDS_CHANGED => '{count} fields changed',
        self::NO_CHANGES => 'No changes',
        self::DIFF_CONTEXT_LINES => 'Diff Context Lines',
        self::DIFF_CONTEXT_LINES_INSTRUCTIONS => 'Number of unchanged lines to show around changes.',
        self::MAX_FIELD_LENGTH => 'Max Field Length',
        self::MAX_FIELD_LENGTH_INSTRUCTIONS => 'Maximum characters before showing a simplified diff. Prevents performance issues on very large fields.',
        self::SHOW_UNCHANGED_FIELDS => 'Show Unchanged Fields',
        self::SHOW_UNCHANGED_FIELDS_INSTRUCTIONS => 'Show unchanged fields by default in the diff view.',
        self::BLOCKS_REORDERED => 'Blocks were reordered',
        self::ROW_ADDED => 'Row {row} added',
        self::ROW_REMOVED => 'Row {row} removed',
        self::ROW_MODIFIED => 'Row {row} modified',
        self::FIELD_TOO_LARGE => 'Field content too large to diff ({length} chars).',
        self::DRAFT => 'Draft',
        self::UNABLE_TO_DIFF_FIELD => 'Unable to diff this field.',
        self::FAILED_GENERATE_DIFF => 'Failed to generate diff.',
        self::VIEW_SIDE_BY_SIDE_HINT => 'View a side-by-side diff of changes between revisions.',
        self::UNABLE_PARSE_STRUCTURED_DIFF => 'Unable to parse structured diff.',
        self::UNABLE_PARSE_TABLE_DIFF => 'Unable to parse table diff.',
        self::START_REVIEW => 'Start Review',
        self::CANCEL_REVIEW => 'Cancel review',
        self::ACCEPT => 'Accept',
        self::PREV => 'Prev',
        self::NEXT => 'Next',
        self::SOURCE_VERSION_NOT_FOUND => 'Source version not found.',
        self::INSUFFICIENT_PERMISSIONS_CREATE_DRAFT => 'Insufficient permissions to create a draft on this section.',
        self::REVIEW_OF_REF => 'Review of {ref}',
        self::ENABLE_REVIEW_MODE => 'Enable Review Mode',
        self::ENABLE_REVIEW_MODE_INSTRUCTIONS => 'Show the "Start Review" button on the diff slideout and allow accepting/rejecting changes into a new draft.',
        self::ALSO_DELETE_SOURCE_DRAFT => 'Also delete source draft',
        self::PERMISSION_SUBMIT_DRAFTS => 'Submit drafts for review',
        self::PERMISSION_REVIEW_DRAFTS => 'Review submitted drafts',
        self::PERMISSION_APPLY_REVIEW => 'Apply review-mode changes',
        self::PLUGIN_NAME => 'Craft Delta',
        self::NO_PERMISSION_APPLY_REVIEW => 'You do not have permission to apply review-mode changes.',
        self::WORKFLOW => 'Workflow',
        self::GENERAL_SETTINGS => 'General Settings',
        self::ENABLE_REVIEW_WORKFLOW => 'Enable Review Workflow',
        self::ENABLE_REVIEW_WORKFLOW_INSTRUCTIONS => 'Show the Submit-for-review / approve / reject workflow. When off, the plugin behaves as a read-only diff tool and the workflow endpoints are disabled.',
        self::REVIEW_KEYBOARD_HINT => 'Use J / K to navigate, A / R to decide',
        self::JUMP_TO_SECTION => 'Jump to section',
        self::TAB => 'Tab',
        self::BLOCK => 'Block',
        self::NOT_AUTHORIZED => 'Not authorized.',
        self::ENTRY_NOT_FOUND => 'Entry not found.',
        self::VERSION_NOT_FOUND => 'Version not found.',
        self::NO_PERMISSION_PUBLISH => 'You do not have permission to publish changes to this entry.',
        self::NO_PERMISSION_DELETE_SOURCE_DRAFT => 'You do not have permission to delete the source draft.',
        self::ONLY_ASSIGNED_REVIEWER_MAY_APPLY => 'Only the assigned reviewer may apply this submitted draft.',
        self::COULD_NOT_APPLY_CHANGES => 'Could not apply the changes. Please reload the diff and try again.',
        self::REVIEW_WORKFLOW_DISABLED => 'The review workflow is disabled.',
        self::DRAFT_NOT_FOUND => 'Draft not found.',
        self::NO_PERMISSION_SUBMIT_SECTION => 'You do not have permission to submit drafts for this section.',
        self::WORKFLOW_NOT_FOUND => 'Workflow not found.',
        self::NOT_ASSIGNED_REVIEWER => 'You are not the assigned reviewer for this draft.',
        self::REV_NUM => 'Rev {num}',
        self::SOURCE => 'Source',
        self::EMAIL_DRAFT_AWAITING_REVIEW => 'Draft awaiting your review: {title}',
        self::EMAIL_DRAFT_APPROVED => 'Your draft was approved: {title}',
        self::EMAIL_DRAFT_REJECTED => 'Your draft was rejected: {title}',
        self::EMAIL_HI_NAME => 'Hi {name},',
        self::EMAIL_AUTHOR_SUBMITTED => '{author} has submitted a draft for your review:',
        self::EMAIL_OPEN_TO_REVIEW => 'Open the entry to review the changes and approve, schedule, or reject.',
        self::EMAIL_SIGNATURE => '— Craft Delta',
        self::EMAIL_REVIEWER_APPROVED => '{reviewer} has approved your draft:',
        self::EMAIL_SCHEDULED_PUBLISH_AT => 'Scheduled to publish at: {when}',
        self::EMAIL_CHANGES_APPLIED => 'The changes have been applied to the entry.',
        self::EMAIL_REVIEWER_REJECTED => '{reviewer} has rejected your draft:',
        self::EMAIL_REVIEWER_NOTE => 'Reviewer note:',
        self::EMAIL_DRAFT_PRESERVED => 'The draft is preserved. If you want to revise and resubmit, duplicate the draft and submit the copy.',
        self::REVIEW_IN_REVIEW => 'In review',
        self::REVIEW_CHANGES_REQUESTED => 'Changes requested',
        self::REVIEW_DECLINED => 'Declined',
        self::REVIEW_WITHDRAWN => 'Withdrawn',
        self::REVIEW_PUBLISHED => 'Published',
        self::ONLY_APPROVED_REVIEW_CAN_PUBLISH => 'Only an approved review can be published.',
        self::REVIEW_ALREADY_MOVED_ON => 'This review has already moved on; reload and try again.',
        self::NOT_REVIEWER_FOR_DRAFT => 'You are not a reviewer for this draft.',
        self::ONLY_AUTHOR_CAN_DO_THAT => 'Only the author can do that.',
        self::REVIEW_NO_LONGER_OPEN => 'This review is no longer open.',
        self::EMAIL_DRAFT_RESUBMITTED => 'Draft re-submitted for review: {title}',
        self::EMAIL_CHANGES_REQUESTED_ON_DRAFT => 'Changes requested on your draft: {title}',
        self::EMAIL_DRAFT_DECLINED => 'Your draft was declined: {title}',
        self::EMAIL_DRAFT_APPROVED_SCHEDULED => 'Your draft was approved and scheduled: {title}',
        self::EMAIL_DRAFT_PUBLISHED => 'Your draft was published: {title}',
        self::EMAIL_BODY_RESUBMITTED => '{author} has revised and re-requested your review (round {round}):',
        self::EMAIL_BODY_CHANGES_REQUESTED => 'A reviewer requested changes on your draft:',
        self::EMAIL_BODY_REREQUEST_HINT => 'Make your changes, then re-request review from the entry.',
        self::EMAIL_BODY_DECLINED => 'Your draft was declined:',
        self::EMAIL_BODY_REVIEW_CLOSED => 'The draft is preserved, but this review is closed.',
        self::EMAIL_BODY_PUBLISHED => 'Your draft has been published:',
        self::WORKFLOW_APPROVE => 'Approve',
        self::WORKFLOW_REQUEST_CHANGES => 'Request changes',
        self::WORKFLOW_DECLINE => 'Decline',
        self::WORKFLOW_RE_REQUEST => 'Re-request review',
        self::WORKFLOW_WITHDRAW => 'Withdraw',
        self::WORKFLOW_PUBLISH => 'Publish',
        self::WORKFLOW_REVIEWERS => 'Reviewers',
        self::WORKFLOW_ROUND => 'Round {round}',
        self::WORKFLOW_AWAITING => 'Awaiting',
        self::WORKFLOW_REQUEST_CHANGES_PROMPT => 'What needs to change? (note to the author)',
        self::WORKFLOW_DECLINE_CONFIRM => 'Decline this draft? This closes the review.',
        self::WORKFLOW_WITHDRAW_CONFIRM => 'Withdraw this review request?',
        self::WORKFLOW_RE_REQUEST_CONFIRM => 'Re-request review from the same reviewers?',
        self::WORKFLOW_PUBLISH_CONFIRM => 'Publish this approved draft now?',
        self::WORKFLOW_DONE_APPROVED => 'Approval recorded.',
        self::WORKFLOW_DONE_CHANGES_REQUESTED => 'Changes requested.',
        self::WORKFLOW_DONE_DECLINED => 'Draft declined.',
        self::WORKFLOW_DONE_WITHDRAWN => 'Review withdrawn.',
        self::WORKFLOW_DONE_RE_REQUESTED => 'Review re-requested.',
        self::WORKFLOW_DONE_PUBLISHED => 'Draft published.',
        self::WORKFLOW_ACTION_FAILED => 'Action failed.',
        self::WORKFLOW_REVIEWS_TITLE => 'Reviews',
        self::WORKFLOW_ASSIGNED_TO_ME => 'Awaiting your review',
        self::WORKFLOW_MY_SUBMISSIONS => 'Your submissions',
        self::WORKFLOW_ALL_REVIEWS => 'All reviews',
        self::WORKFLOW_NO_REVIEWS => 'No reviews yet.',
        self::WORKFLOW_COL_ENTRY => 'Entry',
        self::WORKFLOW_COL_STATUS => 'Status',
        self::WORKFLOW_COL_ROUND => 'Round',
        self::WORKFLOW_COMMENT_FAILED => 'Could not save your comment.',
    ];

    /** Keys registered for Craft.t() in the control panel. */
    public static function jsMessageKeys(): array
    {
        return [
            self::APPLY_COUNT_ACCEPTED,
            self::DECIDED_OF_TOTAL,
            self::NEED_TWO_REVISIONS,
            self::CHANGED_ONLY,
            self::CLOSE,
            self::COMPARE_REVISIONS,
            self::COMPARING,
            self::CURRENT_DRAFT,
            self::CURRENT,
            self::DRAFTS,
            self::EXPAND,
            self::FAILED_LOAD_DIFF,
            self::FAILED_LOAD_REVISIONS,
            self::LOADING_REVISIONS,
            self::OPEN_FULL_PAGE,
            self::REVISIONS,
            self::RESUME_PREVIOUS_REVIEW,
            self::RESUME,
            self::START_FRESH,
            self::ENTRY_CHANGED_SINCE_LAST_REVIEW,
            self::ENTRY_CHANGED_SINCE_REVIEW_STARTED,
            self::DISCARD_DECISIONS,
            self::PUBLISH_ACCEPTED_CONFIRM,
            self::CHANGES_PUBLISHED_OPEN_ENTRY,
            self::VALIDATION_FAILED,
            self::APPLY_FAILED,
            self::NO_CHANGES_TO_APPLY,
            self::DECISIONS_SAVED_RETRY,
            self::DECISIONS_STILL_SAVED,
            self::SUBMIT_FOR_REVIEW,
            self::REVIEWER,
            self::SUBMIT,
            self::CANCEL,
            self::LOADING,
            self::NO_ELIGIBLE_REVIEWERS,
            self::FAILED_LOAD_REVIEWERS,
            self::FAILED_SUBMIT_FOR_REVIEW,
            self::PENDING_REVIEW,
            self::APPROVED,
            self::APPROVED_SCHEDULED,
            self::REJECTED,
            self::APPROVE_ALL,
            self::SCHEDULE_FOR,
            self::GRANULAR_REVIEW,
            self::REJECT,
            self::APPROVE_PUBLISH_NOW_CONFIRM,
            self::PUBLISH_AT_PROMPT,
            self::OPTIONAL_NOTE_FOR_AUTHOR,
            self::REJECT_DRAFT_CONFIRM,
            self::DRAFT_APPROVED,
            self::DRAFT_SCHEDULED,
            self::DRAFT_REJECTED,
            self::APPROVE_FAILED,
            self::SCHEDULE_FAILED,
            self::REJECT_FAILED,
            self::REVIEW_MODE_UNAVAILABLE,
        ];
    }

    /** Property names => keys, injected into Craft.Delta._keys for JS. */
    public static function jsPropertyMap(): array
    {
        return [
            'applyCountAccepted' => self::APPLY_COUNT_ACCEPTED,
            'decidedOfTotal' => self::DECIDED_OF_TOTAL,
            'needTwoRevisions' => self::NEED_TWO_REVISIONS,
            'changedOnly' => self::CHANGED_ONLY,
            'close' => self::CLOSE,
            'compareRevisions' => self::COMPARE_REVISIONS,
            'comparing' => self::COMPARING,
            'currentDraft' => self::CURRENT_DRAFT,
            'current' => self::CURRENT,
            'drafts' => self::DRAFTS,
            'expand' => self::EXPAND,
            'failedLoadDiff' => self::FAILED_LOAD_DIFF,
            'failedLoadRevisions' => self::FAILED_LOAD_REVISIONS,
            'loadingRevisions' => self::LOADING_REVISIONS,
            'openFullPage' => self::OPEN_FULL_PAGE,
            'revisions' => self::REVISIONS,
            'resumePreviousReview' => self::RESUME_PREVIOUS_REVIEW,
            'resume' => self::RESUME,
            'startFresh' => self::START_FRESH,
            'entryChangedSinceLastReview' => self::ENTRY_CHANGED_SINCE_LAST_REVIEW,
            'entryChangedSinceReviewStarted' => self::ENTRY_CHANGED_SINCE_REVIEW_STARTED,
            'discardDecisions' => self::DISCARD_DECISIONS,
            'publishAcceptedConfirm' => self::PUBLISH_ACCEPTED_CONFIRM,
            'changesPublishedOpenEntry' => self::CHANGES_PUBLISHED_OPEN_ENTRY,
            'validationFailed' => self::VALIDATION_FAILED,
            'applyFailed' => self::APPLY_FAILED,
            'noChangesToApply' => self::NO_CHANGES_TO_APPLY,
            'decisionsSavedRetry' => self::DECISIONS_SAVED_RETRY,
            'decisionsStillSaved' => self::DECISIONS_STILL_SAVED,
            'submitForReview' => self::SUBMIT_FOR_REVIEW,
            'reviewer' => self::REVIEWER,
            'submit' => self::SUBMIT,
            'cancel' => self::CANCEL,
            'loading' => self::LOADING,
            'noEligibleReviewers' => self::NO_ELIGIBLE_REVIEWERS,
            'failedLoadReviewers' => self::FAILED_LOAD_REVIEWERS,
            'failedSubmitForReview' => self::FAILED_SUBMIT_FOR_REVIEW,
            'approvePublishNowConfirm' => self::APPROVE_PUBLISH_NOW_CONFIRM,
            'publishAtPrompt' => self::PUBLISH_AT_PROMPT,
            'optionalNoteForAuthor' => self::OPTIONAL_NOTE_FOR_AUTHOR,
            'rejectDraftConfirm' => self::REJECT_DRAFT_CONFIRM,
            'draftApproved' => self::DRAFT_APPROVED,
            'draftScheduled' => self::DRAFT_SCHEDULED,
            'draftRejected' => self::DRAFT_REJECTED,
            'approveFailed' => self::APPROVE_FAILED,
            'scheduleFailed' => self::SCHEDULE_FAILED,
            'rejectFailed' => self::REJECT_FAILED,
            'reviewModeUnavailable' => self::REVIEW_MODE_UNAVAILABLE,
        ];
    }

    /** @return array<string, string> */
    public static function englishCatalog(): array
    {
        return self::ENGLISH;
    }

    /**
     * Remap a legacy locale file (English sentence keys) to symbolic keys.
     *
     * @param array<string, string> $legacy
     * @return array<string, string>
     */
    public static function remapLegacyLocale(array $legacy): array
    {
        $englishToKey = array_flip(self::ENGLISH);
        $mapped = [];

        foreach ($legacy as $oldKey => $translation) {
            $newKey = $englishToKey[$oldKey] ?? $oldKey;
            $mapped[$newKey] = $translation;
        }

        foreach (self::ENGLISH as $key => $english) {
            if (!isset($mapped[$key])) {
                $mapped[$key] = $english;
            }
        }

        return $mapped;
    }

    /** camelCase property names => keys, for Twig globals and Craft.Delta._keys. */
    public static function propertyMap(): array
    {
        $map = [];
        $ref = new \ReflectionClass(self::class);

        foreach ($ref->getConstants() as $name => $value) {
            if (!is_string($value) || !str_contains($value, '.')) {
                continue;
            }
            $map[self::constToCamel($name)] = $value;
        }

        return $map;
    }

    private static function constToCamel(string $name): string
    {
        return lcfirst(str_replace('_', '', ucwords(strtolower($name), '_')));
    }
}
