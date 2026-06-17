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
                    '<div class="delta-assignee" data-assignee-list><p class="delta-assignee-loading">' + Craft.t('craft-delta', Craft.Delta._keys.loading) + '</p></div>' +
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
                var $list = $modal.find('.delta-assignee').empty();
                if (!resp.assignees.length) {
                    $list.append($('<p class="delta-assignee-empty"></p>').text(Craft.t('craft-delta', Craft.Delta._keys.noEligibleReviewers)));
                    return;
                }
                resp.assignees.forEach(function(u) {
                    // Build via .val()/.text() — never concatenate the user's
                    // name into an HTML string (it would be a stored-XSS vector
                    // since names are user-controlled profile data).
                    var $opt = $('<label class="delta-assignee-option"></label>');
                    $opt.append($('<input type="checkbox">').val(u.id));
                    $opt.append($('<span></span>').text(u.name));
                    $list.append($opt);
                });
                // Submit enables only once at least one reviewer is checked.
                $list.on('change', 'input[type="checkbox"]', function() {
                    var any = $list.find('input[type="checkbox"]:checked').length > 0;
                    $modal.find('.btn.submit').toggleClass('disabled', !any);
                });
            })
            .fail(function() {
                $modal.find('.delta-assignee').empty().append($('<p class="delta-assignee-empty"></p>').text(Craft.t('craft-delta', Craft.Delta._keys.failedLoadReviewers)));
            });

        $modal.find('.btn.cancel').on('click', closeModal);

        $modal.find('.btn.submit').on('click', function() {
            var $btn = $(this);
            // 'loading' doubles as the in-flight guard: a double-click must not
            // fire a second POST (the duplicate would 422 as "already exists").
            if ($btn.hasClass('disabled') || $btn.hasClass('loading')) return;
            // Collect the checked reviewer checkboxes as an array of ids.
            var reviewerIds = $modal.find('.delta-assignee input[type="checkbox"]:checked').map(function() {
                return this.value;
            }).get();
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
            'decline':         { endpoint: 'decline',         done: ds.doneDecline,  confirm: ds.confirmDecline, notePrompt: ds.notePrompt },
            'withdraw':        { endpoint: 'withdraw',        done: ds.doneWithdraw, confirm: ds.confirmWithdraw },
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
                // decline: optional note to the author; already confirmed, so a
                // cancelled or empty prompt still proceeds.
                var note = prompt(cfg.notePrompt);
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

        if (Craft.Delta.reviewComments) {
            Craft.Delta.reviewComments.mount($toolbar);
        }
    };

    function deltaT(keyProp, params) {
        var key = Craft.Delta._keys[keyProp];
        return key ? Craft.t('craft-delta', key, params || {}) : '';
    }

    /**
     * PR-style review comments: per-atom threads, general discussion, outdated
     * collapse. Fetches workflow/thread on slideout load and hydrates the diff.
     */
    Craft.Delta.reviewComments = {
        reviewId: null,
        $root: null,
        $toolbar: null,
        comments: [],
        openPanelAtomId: null,

        mount: function($toolbar) {
            if ($toolbar.data('delta-comments-mounted')) {
                return;
            }
            $toolbar.data('delta-comments-mounted', true);

            this.$toolbar = $toolbar;
            this.reviewId = $toolbar[0].dataset.reviewId;
            this.$root = $toolbar.closest('.delta-slideout, .delta-modal-body, .delta-fullpage, .delta-review-page');
            if (!this.$root.length) {
                this.$root = $toolbar.parent();
            }

            this.closePanel();

            // Resolve from the page root, not the toolbar: on the dedicated review
            // page the discussion section lives below the diff, outside the toolbar.
            var $section = this.$root.find('[data-review-comments]');
            if (!$section.length) {
                return;
            }
            $section.prop('hidden', false);
            // Closed reviews render read-only: the backend rejects posts/replies
            // on an inactive review ("review no longer open"), so don't offer them.
            this.isActive = $section[0].dataset.reviewActive !== '0';

            var self = this;
            this.bindDelegatedHandlers();

            $section.find('[data-general-comment-post]').on('click', function() {
                var body = $section.find('[data-general-comment-input]').val();
                self.postComment(null, body, null, function() {
                    $section.find('[data-general-comment-input]').val('');
                });
            });

            $.getJSON(Craft.getActionUrl('craft-delta/workflow/thread'), { reviewId: this.reviewId })
                .done(function(resp) {
                    if (!resp.success) {
                        return;
                    }
                    self.comments = resp.comments || [];
                    self.render();
                });
        },

        bindDelegatedHandlers: function() {
            if (this._delegated) {
                return;
            }
            this._delegated = true;
            var self = this;

            document.addEventListener('click', function(e) {
                if (!self.$toolbar || !self.$toolbar.length) {
                    return;
                }
                var root = self.$root && self.$root[0];
                if (!root || !root.contains(e.target)) {
                    return;
                }

                var trigger = e.target.closest('[data-comment-trigger]');
                if (trigger) {
                    e.preventDefault();
                    self.togglePanel(trigger.dataset.commentTrigger);
                    return;
                }

                var resolveBtn = e.target.closest('[data-comment-resolve]');
                if (resolveBtn) {
                    e.preventDefault();
                    self.resolveComment(parseInt(resolveBtn.dataset.commentResolve, 10), resolveBtn.dataset.resolved !== '1');
                    return;
                }

                var addToggle = e.target.closest('[data-comment-add-toggle]');
                if (addToggle) {
                    e.preventDefault();
                    var addWrap = addToggle.closest('.delta-comment-add');
                    var addForm = addWrap && addWrap.querySelector('[data-comment-add-form]');
                    if (addForm) {
                        addForm.hidden = !addForm.hidden;
                        if (!addForm.hidden) {
                            addForm.querySelector('textarea').focus();
                        }
                    }
                    return;
                }

                var replyBtn = e.target.closest('[data-comment-reply-toggle]');
                if (replyBtn) {
                    e.preventDefault();
                    var item = replyBtn.closest('[data-comment-id]');
                    if (item) {
                        var form = item.querySelector('[data-comment-reply-form]');
                        if (form) {
                            form.hidden = !form.hidden;
                            if (!form.hidden) {
                                form.querySelector('textarea').focus();
                            }
                        }
                    }
                    return;
                }

                var postReply = e.target.closest('[data-comment-reply-post]');
                if (postReply) {
                    e.preventDefault();
                    var parentItem = postReply.closest('[data-comment-id]');
                    if (!parentItem) {
                        return;
                    }
                    var parentId = parseInt(parentItem.dataset.commentId, 10);
                    var formEl = parentItem.querySelector('[data-comment-reply-form]');
                    var replyBody = formEl ? formEl.querySelector('textarea').value : '';
                    var atomId = postReply.closest('[data-comment-panel]')
                        ? postReply.closest('[data-comment-panel]').dataset.commentPanel
                        : null;
                    if (atomId === 'general') {
                        atomId = null;
                    }
                    self.postComment(atomId, replyBody, parentId, function() {
                        if (formEl) {
                            formEl.querySelector('textarea').value = '';
                            formEl.hidden = true;
                        }
                    });
                    return;
                }

                var postAtom = e.target.closest('[data-comment-atom-post]');
                if (postAtom) {
                    e.preventDefault();
                    var panel = postAtom.closest('[data-comment-panel]');
                    if (!panel) {
                        return;
                    }
                    var atom = panel.dataset.commentPanel;
                    var text = panel.querySelector('[data-comment-atom-input]').value;
                    self.postComment(atom, text, null, function() {
                        panel.querySelector('[data-comment-atom-input]').value = '';
                    });
                }
            });
        },

        partition: function() {
            var general = [];
            var byAtom = {};
            var outdated = [];

            this.comments.forEach(function(c) {
                if (c.outdated) {
                    outdated.push(c);
                    return;
                }
                if (!c.atomId || c.anchorType === 'general') {
                    general.push(c);
                    return;
                }
                if (!byAtom[c.atomId]) {
                    byAtom[c.atomId] = [];
                }
                byAtom[c.atomId].push(c);
            });

            return { general: general, byAtom: byAtom, outdated: outdated };
        },

        render: function() {
            var parts = this.partition();
            var $section = this.$root.find('[data-review-comments]');

            this.renderList($section.find('[data-general-comment-list]'), parts.general, this.isActive);

            var $outdatedWrap = $section.find('[data-outdated-comments]');
            if (parts.outdated.length) {
                $section.find('[data-outdated-summary]').text(
                    deltaT('outdated', { count: parts.outdated.length })
                );
                this.renderList($section.find('[data-outdated-comment-list]'), parts.outdated, false);
                $outdatedWrap.prop('hidden', false);
            } else {
                $outdatedWrap.prop('hidden', true);
            }

            // Review page: anchored threads render inline + always-visible under
            // each change. Slideout keeps the compact toggle-panel model.
            if (this.$root.hasClass('delta-review-page')) {
                this.renderInlineThreads(parts.byAtom, this.isActive);
            } else {
                this.mountAtomTriggers(parts.byAtom);
            }
        },

        mountAtomTriggers: function(byAtom) {
            var self = this;
            var root = this.$root[0];
            if (!root) {
                return;
            }

            root.querySelectorAll('[data-atom-id]').forEach(function(el) {
                var atomId = el.dataset.atomId;
                var count = (byAtom[atomId] || []).length;
                var existing = el.querySelector('[data-comment-trigger]');
                if (existing) {
                    var badge = existing.querySelector('.delta-comment-badge');
                    if (count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'delta-comment-badge';
                            existing.appendChild(badge);
                        }
                        badge.textContent = String(count);
                    } else if (badge) {
                        badge.remove();
                    }
                    return;
                }

                var trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = 'btn small delta-comment-trigger';
                trigger.dataset.commentTrigger = atomId;
                trigger.title = deltaT('comments');
                trigger.innerHTML = '<span class="delta-comment-trigger-icon" aria-hidden="true">&#128172;</span>';
                if (count > 0) {
                    var badge = document.createElement('span');
                    badge.className = 'delta-comment-badge';
                    badge.textContent = String(count);
                    trigger.appendChild(badge);
                }

                var host = el.querySelector('.delta-field-headerbar')
                    || el.querySelector('.delta-block-toggle')
                    || el;
                if (host.classList.contains('delta-field-headerbar')) {
                    host.appendChild(trigger);
                } else if (host === el) {
                    el.appendChild(trigger);
                } else {
                    host.parentElement.insertBefore(trigger, host.nextSibling);
                }
            });

            // Refresh open panel if still valid
            if (this.openPanelAtomId) {
                this.openPanel(this.openPanelAtomId);
            }
        },

        // GitHub-style inline threads for the dedicated review page: each anchored
        // atom gets an always-visible thread plus a collapsed "add comment" box,
        // inserted directly after its diff. Wrapped in [data-comment-panel] so the
        // existing reply/post handlers inherit the atom id. Re-rendered wholesale
        // on every render() (post/resolve refetch the thread), so it's idempotent.
        renderInlineThreads: function(byAtom, interactive) {
            var self = this;
            var root = this.$root[0];
            if (!root) {
                return;
            }

            root.querySelectorAll('[data-comment-inline]').forEach(function(b) {
                b.remove();
            });

            root.querySelectorAll('[data-atom-id]').forEach(function(el) {
                var atomId = el.dataset.atomId;
                var threads = byAtom[atomId] || [];

                // On a closed review with no comments there's nothing to show and
                // nothing to add — skip the block entirely.
                if (!threads.length && !interactive) {
                    return;
                }

                var block = document.createElement('div');
                block.className = 'delta-comment-inline';
                block.dataset.commentInline = atomId;
                block.dataset.commentPanel = atomId;

                if (threads.length) {
                    var list = document.createElement('div');
                    list.className = 'delta-comment-list';
                    self.renderList($(list), threads, interactive);
                    block.appendChild(list);
                }

                if (interactive) {
                    var add = document.createElement('div');
                    add.className = 'delta-comment-add';
                    add.innerHTML =
                        '<button type="button" class="btn small delta-comment-add-toggle" data-comment-add-toggle>'
                        + escHtml(deltaT('addComment')) + '</button>'
                        + '<div class="delta-comment-compose" data-comment-add-form hidden>'
                        + '<textarea class="text fullwidth" rows="2" data-comment-atom-input maxlength="10000"'
                        + ' placeholder="' + escAttr(deltaT('commentPlaceholder')) + '"></textarea>'
                        + '<button type="button" class="btn" data-comment-atom-post>'
                        + escHtml(deltaT('postComment')) + '</button>'
                        + '</div>';
                    block.appendChild(add);
                }

                el.insertAdjacentElement('afterend', block);
            });
        },

        togglePanel: function(atomId) {
            if (this.openPanelAtomId === atomId) {
                this.closePanel();
                return;
            }
            this.openPanel(atomId);
        },

        openPanel: function(atomId) {
            this.closePanel();
            this.openPanelAtomId = atomId;

            var root = this.$root[0];
            var el = root.querySelector('[data-atom-id="' + cssEscape(atomId) + '"]');
            if (!el) {
                return;
            }

            var threads = this.partition().byAtom[atomId] || [];
            var panel = document.createElement('div');
            panel.className = 'delta-comment-panel';
            panel.dataset.commentPanel = atomId;

            var list = document.createElement('div');
            list.className = 'delta-comment-list';
            this.renderList($(list), threads, true);
            panel.appendChild(list);

            var compose = document.createElement('div');
            compose.className = 'delta-comment-compose';
            compose.innerHTML =
                '<textarea class="text fullwidth" rows="2" data-comment-atom-input maxlength="10000"'
                + ' placeholder="' + escAttr(deltaT('commentPlaceholder')) + '"></textarea>'
                + '<button type="button" class="btn" data-comment-atom-post>'
                + escHtml(deltaT('postComment')) + '</button>';
            panel.appendChild(compose);

            el.insertAdjacentElement('afterend', panel);

            root.querySelectorAll('[data-comment-trigger]').forEach(function(btn) {
                btn.classList.toggle('is-open', btn.dataset.commentTrigger === atomId);
            });
        },

        closePanel: function() {
            var root = this.$root && this.$root[0];
            if (!root) {
                this.openPanelAtomId = null;
                return;
            }
            root.querySelectorAll('.delta-comment-panel').forEach(function(p) {
                p.remove();
            });
            root.querySelectorAll('[data-comment-trigger].is-open').forEach(function(btn) {
                btn.classList.remove('is-open');
            });
            this.openPanelAtomId = null;
        },

        renderList: function($container, comments, interactive) {
            $container.empty();
            if (!comments.length) {
                $container.append(
                    $('<p class="delta-comment-empty"></p>').text(deltaT('noComments'))
                );
                return;
            }

            var self = this;
            comments.forEach(function(c) {
                $container.append(self.renderComment(c, interactive));
            });
        },

        renderComment: function(c, interactive) {
            var $item = $('<div class="delta-comment-item"></div>');
            if (c.resolved) {
                $item.addClass('delta-comment-item--resolved');
            }
            if (c.outdated) {
                $item.addClass('delta-comment-item--outdated');
            }
            $item.attr('data-comment-id', c.id);

            var meta = $('<div class="delta-comment-meta"></div>');
            meta.append($('<strong></strong>').text(c.authorName || ''));
            meta.append($('<span class="delta-comment-round"></span>').text(
                deltaT('roundLabel', { round: c.round })
            ));
            $item.append(meta);
            $item.append($('<div class="delta-comment-body"></div>').text(c.body));

            if (interactive) {
                // The backend allows a single level of nesting: a reply may not
                // itself be replied to (ReviewCommentService::addComment throws
                // "Only one level of replies is supported"). So the reply button +
                // form must only render on top-level comments — otherwise the UI
                // offers an action the server always rejects.
                var canReply = c.parentId == null;

                var actions = $('<div class="delta-comment-actions"></div>');
                if (canReply) {
                    actions.append(
                        $('<button type="button" class="btn small" data-comment-reply-toggle></button>')
                            .text(deltaT('reply'))
                    );
                }
                actions.append(
                    $('<button type="button" class="btn small" data-comment-resolve></button>')
                        .attr('data-comment-resolve', c.id)
                        .attr('data-resolved', c.resolved ? '1' : '0')
                        .text(c.resolved ? deltaT('unresolve') : deltaT('resolve'))
                );
                $item.append(actions);

                if (canReply) {
                    var $replyForm = $(
                        '<div class="delta-comment-reply-form" data-comment-reply-form hidden>'
                        + '<textarea class="text fullwidth" rows="2" maxlength="10000"'
                        + ' placeholder="' + escAttr(deltaT('replyPlaceholder')) + '"></textarea>'
                        + '<button type="button" class="btn small" data-comment-reply-post>'
                        + escHtml(deltaT('reply')) + '</button>'
                        + '</div>'
                    );
                    $item.append($replyForm);
                }
            }

            if (c.replies && c.replies.length) {
                var $replies = $('<div class="delta-comment-replies"></div>');
                var self = this;
                c.replies.forEach(function(r) {
                    $replies.append(self.renderComment(r, interactive));
                });
                $item.append($replies);
            }

            return $item;
        },

        postComment: function(atomId, body, parentId, onSuccess) {
            body = (body || '').trim();
            if (!body) {
                return;
            }

            var self = this;
            var data = { reviewId: this.reviewId, body: body };
            if (atomId) {
                data.atomId = atomId;
            }
            if (parentId) {
                data.parentId = parentId;
            }

            Craft.sendActionRequest('POST', 'craft-delta/workflow/comment', { data: data })
                .then(function() {
                    return $.getJSON(Craft.getActionUrl('craft-delta/workflow/thread'), { reviewId: self.reviewId });
                })
                .then(function(resp) {
                    if (resp.success) {
                        self.comments = resp.comments || [];
                        var openAtom = self.openPanelAtomId;
                        self.render();
                        if (openAtom) {
                            self.openPanel(openAtom);
                        }
                        if (typeof onSuccess === 'function') {
                            onSuccess();
                        }
                    }
                })
                .catch(function() {
                    Craft.cp.displayError(deltaT('commentFailed'));
                });
        },

        resolveComment: function(commentId, resolved) {
            var self = this;
            Craft.sendActionRequest('POST', 'craft-delta/workflow/resolve-comment', {
                data: { commentId: commentId, resolved: resolved ? 1 : 0 },
            })
                .then(function() {
                    return $.getJSON(Craft.getActionUrl('craft-delta/workflow/thread'), { reviewId: self.reviewId });
                })
                .then(function(resp) {
                    if (resp.success) {
                        self.comments = resp.comments || [];
                        var openAtom = self.openPanelAtomId;
                        self.render();
                        if (openAtom) {
                            self.openPanel(openAtom);
                        }
                    }
                })
                .catch(function() {
                    Craft.cp.displayError(deltaT('commentFailed'));
                });
        },
    };

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escAttr(s) {
        return escHtml(s).replace(/"/g, '&quot;');
    }

    function cssEscape(s) {
        return (window.CSS && window.CSS.escape) ? window.CSS.escape(s) : String(s).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }
})();
