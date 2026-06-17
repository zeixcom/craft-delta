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
    public const APPROVED = 'workflow.approved';
    public const APPROVED_SCHEDULED = 'workflow.approvedScheduled';
    public const SCHEDULE_FOR = 'workflow.scheduleFor';
    public const REJECT = 'workflow.reject';
    public const PUBLISH_AT_PROMPT = 'workflow.publishAtPrompt';
    public const OPTIONAL_NOTE_FOR_AUTHOR = 'workflow.optionalNoteForAuthor';
    public const SCHEDULE_FAILED = 'error.scheduleFailed';
    public const REVIEW_MODE_UNAVAILABLE = 'review.modeUnavailable';
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
    public const SOURCE_VERSION_NOT_FOUND = 'error.sourceVersionNotFound';
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
    public const REV_NUM = 'diff.revNum';
    public const SOURCE = 'diff.source';
    public const EMAIL_DRAFT_AWAITING_REVIEW = 'email.draftAwaitingReview';
    public const EMAIL_HI_NAME = 'email.hiName';
    public const EMAIL_AUTHOR_SUBMITTED = 'email.authorSubmitted';
    public const EMAIL_OPEN_TO_REVIEW = 'email.openToReview';
    public const EMAIL_SIGNATURE = 'email.signature';
    public const EMAIL_SCHEDULED_PUBLISH_AT = 'email.scheduledPublishAt';
    public const EMAIL_REVIEWER_NOTE = 'email.reviewerNote';
    public const REVIEW_IN_REVIEW = 'review.inReview';
    public const REVIEW_DECLINED = 'review.declined';
    public const REVIEW_WITHDRAWN = 'review.withdrawn';
    public const REVIEW_PUBLISHED = 'review.published';
    public const ONLY_APPROVED_REVIEW_CAN_PUBLISH = 'error.onlyApprovedReviewCanPublish';
    public const REVIEW_ALREADY_MOVED_ON = 'error.reviewAlreadyMovedOn';
    public const NOT_REVIEWER_FOR_DRAFT = 'error.notReviewerForDraft';
    public const ONLY_AUTHOR_CAN_DO_THAT = 'error.onlyAuthorCanDoThat';
    public const REVIEW_NO_LONGER_OPEN = 'error.reviewNoLongerOpen';
    public const EMAIL_DRAFT_DECLINED = 'email.draftDeclined';
    public const EMAIL_DRAFT_APPROVED_SCHEDULED = 'email.draftApprovedScheduled';
    public const EMAIL_DRAFT_PUBLISHED = 'email.draftPublished';
    public const EMAIL_BODY_DECLINED = 'email.bodyDeclined';
    public const EMAIL_BODY_REVIEW_CLOSED = 'email.bodyReviewClosed';
    public const EMAIL_BODY_PUBLISHED = 'email.bodyPublished';
    public const WORKFLOW_APPROVE = 'workflow.approve';
    public const WORKFLOW_DECLINE = 'workflow.decline';
    public const WORKFLOW_WITHDRAW = 'workflow.withdraw';
    public const WORKFLOW_PUBLISH = 'workflow.publish';
    public const WORKFLOW_REVIEWERS = 'workflow.reviewers';
    public const WORKFLOW_ROUND = 'workflow.round';
    public const WORKFLOW_AWAITING = 'workflow.awaiting';
    public const WORKFLOW_DECLINE_CONFIRM = 'workflow.declineConfirm';
    public const WORKFLOW_WITHDRAW_CONFIRM = 'workflow.withdrawConfirm';
    public const WORKFLOW_PUBLISH_CONFIRM = 'workflow.publishConfirm';
    public const WORKFLOW_DONE_APPROVED = 'workflow.doneApproved';
    public const WORKFLOW_DONE_DECLINED = 'workflow.doneDeclined';
    public const WORKFLOW_DONE_WITHDRAWN = 'workflow.doneWithdrawn';
    public const WORKFLOW_DONE_PUBLISHED = 'workflow.donePublished';
    public const WORKFLOW_ACTION_FAILED = 'workflow.actionFailed';
    public const WORKFLOW_REVIEWS_TITLE = 'workflow.reviewsTitle';
    public const WORKFLOW_ASSIGNED_TO_ME = 'workflow.assignedToMe';
    public const WORKFLOW_MY_SUBMISSIONS = 'workflow.mySubmissions';
    public const WORKFLOW_ALL_REVIEWS = 'workflow.allReviews';
    public const WORKFLOW_NO_REVIEWS = 'workflow.noReviews';
    public const WORKFLOW_COL_ENTRY = 'workflow.colEntry';
    public const WORKFLOW_COL_STATUS = 'workflow.colStatus';
    public const WORKFLOW_COL_ROUND = 'workflow.colRound';
    public const WORKFLOW_COMMENT_FAILED = 'workflow.commentFailed';
    public const WORKFLOW_GENERAL_DISCUSSION = 'workflow.generalDiscussion';
    public const WORKFLOW_OPEN_REVIEW = 'workflow.openReview';
    public const WORKFLOW_POST_COMMENT = 'workflow.postComment';
    public const WORKFLOW_COMMENT_PLACEHOLDER = 'workflow.commentPlaceholder';
    public const WORKFLOW_REPLY = 'workflow.reply';
    public const WORKFLOW_RESOLVE = 'workflow.resolve';
    public const WORKFLOW_UNRESOLVE = 'workflow.unresolve';
    public const WORKFLOW_REPLY_PLACEHOLDER = 'workflow.replyPlaceholder';
    public const WORKFLOW_COMMENTS = 'workflow.comments';
    public const WORKFLOW_NO_COMMENTS = 'workflow.noComments';
    public const WORKFLOW_OUTDATED = 'workflow.outdated';

    /**
     * Keys registered for Craft.t() in the control panel.
     *
     * @return list<string>
     */
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
            self::REVIEW_MODE_UNAVAILABLE,
            self::WORKFLOW_GENERAL_DISCUSSION,
            self::WORKFLOW_POST_COMMENT,
            self::WORKFLOW_COMMENT_PLACEHOLDER,
            self::WORKFLOW_COMMENT_FAILED,
            self::WORKFLOW_REPLY,
            self::WORKFLOW_RESOLVE,
            self::WORKFLOW_UNRESOLVE,
            self::WORKFLOW_REPLY_PLACEHOLDER,
            self::WORKFLOW_COMMENTS,
            self::WORKFLOW_NO_COMMENTS,
            self::WORKFLOW_OUTDATED,
            self::WORKFLOW_ROUND,
        ];
    }

    /**
     * Property names => keys, injected into Craft.Delta._keys for JS.
     *
     * @return array<string, string>
     */
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
            'reviewModeUnavailable' => self::REVIEW_MODE_UNAVAILABLE,
            'generalDiscussion' => self::WORKFLOW_GENERAL_DISCUSSION,
            'postComment' => self::WORKFLOW_POST_COMMENT,
            'commentPlaceholder' => self::WORKFLOW_COMMENT_PLACEHOLDER,
            'commentFailed' => self::WORKFLOW_COMMENT_FAILED,
            'reply' => self::WORKFLOW_REPLY,
            'resolve' => self::WORKFLOW_RESOLVE,
            'unresolve' => self::WORKFLOW_UNRESOLVE,
            'replyPlaceholder' => self::WORKFLOW_REPLY_PLACEHOLDER,
            'comments' => self::WORKFLOW_COMMENTS,
            'noComments' => self::WORKFLOW_NO_COMMENTS,
            'outdated' => self::WORKFLOW_OUTDATED,
            'roundLabel' => self::WORKFLOW_ROUND,
        ];
    }

    /**
     * camelCase property names => keys, for Twig globals and Craft.Delta._keys.
     *
     * @return array<string, string>
     */
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
