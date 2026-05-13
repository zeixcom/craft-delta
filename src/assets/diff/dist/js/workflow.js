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
})();
