/**
 * Craft Delta workflow client. Provides:
 *   - Submit-for-review modal (author side, multi-reviewer)
 *   - Review toolbar buttons (reviewer + author side, mounted by delta.js)
 *
 * Localized strings for the toolbar are rendered into data-* attributes by
 * _diff-slideout.twig, so this layer reads them from the DOM rather than via
 * Craft.t() — no extra JS message registration is needed for the toolbar.
 *
 * Designed to be a thin layer over Craft.sendActionRequest.
 */
(function() {
    'use strict';

    if (!window.Craft) return;

    Craft.Delta = Craft.Delta || {};

    Craft.Delta.openSubmitModal = function(draftId, sectionUid, onSuccess) {
        var $modal = $(
            '<div class="modal delta-submit-modal">' +
                '<div class="body">' +
                    '<h2>' + Craft.t('craft-delta', Craft.Delta._keys.submitForReview) + '</h2>' +
                    '<label>' + Craft.t('craft-delta', Craft.Delta._keys.reviewer) + '</label>' +
                    '<select class="delta-assignee fullwidth" multiple size="6"><option>' + Craft.t('craft-delta', Craft.Delta._keys.loading) + '</option></select>' +
                '</div>' +
                '<div class="footer">' +
                    '<div class="buttons right">' +
                        '<button type="button" class="btn cancel">' + Craft.t('craft-delta', Craft.Delta._keys.cancel) + '</button>' +
                        '<button type="button" class="btn submit disabled">' + Craft.t('craft-delta', Craft.Delta._keys.submit) + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        ).appendTo(document.body);

        var modal = new Garnish.Modal($modal, { autoShow: true });

        // Fully tear down on close — hide() alone leaks the modal element and
        // shade into <body> on every open/cancel cycle.
        function closeModal() {
            modal.hide();
            modal.destroy();
            $modal.remove();
        }

        // $.getJSON (not $.get) so the request sends `Accept: application/json`;
        // the assignees action calls requireAcceptsJson() and 400s otherwise.
        $.getJSON(Craft.getActionUrl('craft-delta/workflow/assignees'), { sectionUid: sectionUid })
            .done(function(resp) {
                var $select = $modal.find('.delta-assignee').empty();
                if (!resp.assignees.length) {
                    $select.append('<option>' + Craft.t('craft-delta', Craft.Delta._keys.noEligibleReviewers) + '</option>');
                    return;
                }
                resp.assignees.forEach(function(u) {
                    // Build via .val()/.text() — never concatenate the user's
                    // name into an HTML string (it would be a stored-XSS vector
                    // since names are user-controlled profile data).
                    $select.append($('<option></option>').val(u.id).text(u.name));
                });
                $modal.find('.btn.submit').removeClass('disabled');
            })
            .fail(function() {
                $modal.find('.delta-assignee').empty().append('<option>' + Craft.t('craft-delta', Craft.Delta._keys.failedLoadReviewers) + '</option>');
            });

        $modal.find('.btn.cancel').on('click', closeModal);

        $modal.find('.btn.submit').on('click', function() {
            var $btn = $(this);
            // 'loading' doubles as the in-flight guard: a double-click must not
            // fire a second POST (the duplicate would 422 as "already exists").
            if ($btn.hasClass('disabled') || $btn.hasClass('loading')) return;
            // A multiple <select> returns an array of selected ids (or null).
            var reviewerIds = $modal.find('.delta-assignee').val();
            if (!reviewerIds || !reviewerIds.length) return;
            $btn.addClass('loading');
            Craft.sendActionRequest('POST', 'craft-delta/workflow/submit', {
                data: { draftId: draftId, reviewerIds: reviewerIds },
            }).then(function(response) {
                closeModal();
                if (typeof onSuccess === 'function') onSuccess(response.data.review);
            }).catch(function() {
                $btn.removeClass('loading');
                Craft.cp.displayError(Craft.t('craft-delta', Craft.Delta._keys.failedSubmitForReview));
            });
        });
    };

    /**
     * Mount click handlers on a review toolbar inside the diff slideout.
     * Called by delta.js after slideout HTML loads.
     */
    Craft.Delta.mountWorkflowToolbar = function($toolbar) {
        // Runs on every diff load; guard so a re-rendered toolbar isn't wired
        // twice (which would fire duplicate POSTs).
        if ($toolbar.data('delta-mounted')) { return; }
        $toolbar.data('delta-mounted', true);

        var el = $toolbar[0];
        var ds = el.dataset;
        var reviewId = ds.reviewId;

        function post(endpoint, params, doneMsg, redirect) {
            Craft.sendActionRequest('POST', 'craft-delta/workflow/' + endpoint, { data: params })
                .then(function(resp) {
                    if (doneMsg) Craft.cp.displayNotice(doneMsg);
                    if (redirect && resp.data.redirectUrl) {
                        window.location.href = resp.data.redirectUrl;
                    } else {
                        location.reload();
                    }
                })
                .catch(function() {
                    Craft.cp.displayError(ds.actionFailed);
                });
        }

        // action -> behavior. confirm/notePrompt/schedulePrompt strings come
        // from the toolbar's data-* attributes (localized server-side).
        var actions = {
            'approve':         { endpoint: 'approve',         done: ds.doneApprove },
            'request-changes': { endpoint: 'request-changes', done: ds.doneRequestChanges, notePrompt: ds.rcPrompt, noteRequired: true },
            'decline':         { endpoint: 'decline',         done: ds.doneDecline,  confirm: ds.confirmDecline, notePrompt: ds.notePrompt },
            'withdraw':        { endpoint: 'withdraw',        done: ds.doneWithdraw, confirm: ds.confirmWithdraw },
            're-request':      { endpoint: 're-request',      done: ds.doneRerequest, confirm: ds.confirmRerequest },
            'publish':         { endpoint: 'publish',         done: ds.donePublish,  confirm: ds.confirmPublish, redirect: true },
            'schedule':        { endpoint: 'publish',         done: ds.donePublish,  schedulePrompt: ds.publishAtPrompt, redirect: true }
        };

        $toolbar.find('[data-wf-action]').on('click', function() {
            var cfg = actions[this.getAttribute('data-wf-action')];
            if (!cfg) return;

            var params = { reviewId: reviewId };

            if (cfg.confirm && !confirm(cfg.confirm)) return;

            if (cfg.schedulePrompt) {
                var when = prompt(cfg.schedulePrompt);
                if (!when) return;
                params.scheduledFor = when;
            }

            if (cfg.notePrompt) {
                var note = prompt(cfg.notePrompt);
                // request-changes: a cancelled prompt aborts. decline: already
                // confirmed, so a cancelled/empty note still proceeds.
                if (cfg.noteRequired && note === null) return;
                params.note = note || '';
            }

            post(cfg.endpoint, params, cfg.done, cfg.redirect);
        });

        $toolbar.find('.delta-granular-review').on('click', function() {
            // "Granular review" enters Review Mode on the diff already rendered in
            // this slideout. Applying the accepted atoms publishes them and closes
            // THIS review server-side (DiffController::actionApply →
            // WorkflowService::resolveByReview), so no workflow POST is needed.
            var reviewToolbar = document.querySelector('[data-review-toolbar]');
            if (reviewToolbar && Craft.Delta.reviewMode) {
                Craft.Delta.reviewMode.enter(reviewToolbar);
            } else {
                Craft.cp.displayError(Craft.t('craft-delta', Craft.Delta._keys.reviewModeUnavailable));
            }
        });
    };
})();
