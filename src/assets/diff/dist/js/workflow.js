/**
 * Craft Delta workflow client. Provides:
 *   - Submit-for-review modal (author side)
 *   - Workflow toolbar buttons (reviewer side, mounted by delta.js — Task 8)
 *
 * Designed to be a thin layer over Craft.postActionRequest.
 */
(function() {
    'use strict';

    if (!window.Craft) return;

    Craft.Delta = Craft.Delta || {};

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

    /**
     * Mount click handlers on a workflow toolbar inside the diff slideout.
     * Called by delta.js after slideout HTML loads.
     */
    Craft.Delta.mountWorkflowToolbar = function($toolbar) {
        var workflowId = $toolbar.data('workflow-id');

        $toolbar.find('.delta-approve-now').on('click', function() {
            if (!confirm(Craft.t('craft-delta', 'Approve and publish this draft now?'))) return;
            Craft.postActionRequest(
                'craft-delta/workflow/approve',
                { workflowId: workflowId, mode: 'wholesale' },
                function(resp, status) {
                    if (status === 'success' && resp.success) {
                        Craft.cp.displayNotice(Craft.t('craft-delta', 'Draft approved.'));
                        if (resp.redirectUrl) {
                            window.location.href = resp.redirectUrl;
                        } else {
                            location.reload();
                        }
                    } else {
                        Craft.cp.displayError(Craft.t('craft-delta', 'Approve failed.'));
                    }
                }
            );
        });

        $toolbar.find('.delta-approve-schedule').on('click', function() {
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

        $toolbar.find('.delta-granular-review').on('click', function() {
            if (window.Craft.Delta.startGranularReview) {
                Craft.Delta.startGranularReview({ workflowId: workflowId });
            } else {
                // Fall back to the legacy v1.1 "Start Review" button if present.
                var $legacy = $('#delta-start-review, .delta-start-review-btn').first();
                if ($legacy.length) {
                    $legacy.click();
                }
            }
        });
    };
})();
