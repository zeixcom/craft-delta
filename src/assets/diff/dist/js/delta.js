/**
 * Craft Delta — CP Integration
 *
 * Three container modes: slideout → modal → full page
 */
(function () {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  Craft.Delta = {
    entryId: null,
    options: {},
    mode: 'slideout',       // slideout | modal | fullpage
    slideout: null,
    modalOverlay: null,
    $wrapper: null,          // the .delta-slideout wrapper
    $resultContainer: null,
    $older: null,
    $newer: null,
    $statsSlot: null,

    init: function (entryId, options) {
      this.entryId = entryId;
      this.options = options || {};
      this.bindCompareButton();
      this.openFromHash();
    },

    openFromHash: function () {
      if (window.location.hash !== '#delta-compare') {
        return;
      }
      var self = this;
      window.setTimeout(function () {
        if (document.getElementById('delta-compare-btn')) {
          self.openSlideout();
        }
      }, 300);
    },

    bindCompareButton: function () {
      var btn = document.getElementById('delta-compare-btn');
      if (!btn) { return; }

      // Remove previous listener if any (prevents duplicates)
      if (this._boundOpenSlideout) {
        btn.removeEventListener('click', this._boundOpenSlideout);
      }
      this._boundOpenSlideout = this.openSlideout.bind(this);
      btn.addEventListener('click', this._boundOpenSlideout);
    },

    openSlideout: function () {
      this.mode = 'slideout';
      var self = this;

      var $loading = $('<div class="delta-slideout">' +
        '<div class="delta-loading">' +
        '<div class="spinner"></div>' +
        Craft.t('craft-delta', Craft.Delta._keys.loadingRevisions) +
        '</div></div>');

      var slideout = new Craft.Slideout($loading);
      slideout.open();
      this.slideout = slideout;

      this.fetchRevisionsAndBuild(slideout.$container);
    },

    openModal: function () {
      this.mode = 'modal';
      var self = this;

      // Close slideout if open
      if (this.slideout) {
        this.slideout.close();
        this.slideout = null;
      }

      var overlay = document.createElement('div');
      overlay.className = 'delta-modal-overlay';
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { self.closeModal(); }
      });

      var modal = document.createElement('div');
      modal.className = 'delta-modal';
      overlay.appendChild(modal);

      document.body.appendChild(overlay);
      this.modalOverlay = overlay;
      this._previousFocus = document.activeElement;

      // Escape key to close
      this._escHandler = function (e) {
        if (e.key === 'Escape') { self.closeModal(); }
      };
      document.addEventListener('keydown', this._escHandler);

      this.fetchRevisionsAndBuild($(modal));

      // Focus the first focusable element within the modal
      var firstFocusable = modal.querySelector('button, select, input');
      if (firstFocusable) { firstFocusable.focus(); }

      // Trap focus within the modal
      this._trapFocusHandler = function (e) {
        if (e.key !== 'Tab') return;
        var focusable = modal.querySelectorAll('button, select, input, [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      };
      modal.addEventListener('keydown', this._trapFocusHandler);
    },

    closeModal: function () {
      if (this.modalOverlay) {
        this.modalOverlay.remove();
        this.modalOverlay = null;
      }
      if (this._escHandler) {
        document.removeEventListener('keydown', this._escHandler);
        this._escHandler = null;
      }
      if (this._previousFocus) {
        this._previousFocus.focus();
        this._previousFocus = null;
      }
    },

    openFullPage: function () {
      this.closeModal();
      // 'delta-compare', not 'craft-delta/compare': handle-prefixed CP URLs
      // require the accessPlugin-craft-delta permission, which plain editors
      // don't have.
      var url = Craft.getCpUrl('delta-compare', { entryId: this.entryId, siteId: this.options.siteId });
      window.location.href = url;
    },

    fetchRevisionsAndBuild: function ($container) {
      var self = this;

      Craft.sendActionRequest('GET', 'craft-delta/diff/revisions', {
        params: { entryId: this.entryId, siteId: this.options.siteId },
      })
        .then(function (response) {
          var revisions = response.data.revisions;
          var drafts = response.data.drafts || [];
          var hasCurrent = response.data.hasCurrent;

          if (revisions.length < 1 && drafts.length < 1 && !(self.options.isDraft && hasCurrent)) {
            $container.html(
              '<div class="delta-slideout"><div class="delta-empty"><p>' +
              Craft.t('craft-delta', Craft.Delta._keys.needTwoRevisions) +
              '</p></div></div>'
            );
            return;
          }

          self.buildUI($container, revisions, drafts);
        })
        .catch(function () {
          $container.html(
            '<div class="delta-slideout"><div class="delta-empty"><p>' +
            Craft.t('craft-delta', Craft.Delta._keys.failedLoadRevisions) +
            '</p></div></div>'
          );
        });
    },

    buildUI: function ($container, revisions, drafts) {
      var self = this;
      drafts = drafts || [];

      // If editing a draft that isn't in the fetched list, add it
      if (this.options.isDraft && this.options.draftId) {
        var currentDraftRef = 'draft:' + this.options.draftId;
        var found = drafts.some(function (d) { return d.id === currentDraftRef; });
        if (!found) {
          drafts.unshift({
            id: currentDraftRef,
            label: Craft.t('craft-delta', Craft.Delta._keys.currentDraft),
            date: '',
          });
        }
      }

      var $toolbar = $('<div class="delta-toolbar"></div>');

      // Top row: title + actions
      var $topRow = $('<div class="delta-toolbar-top"></div>');
      var $title = $('<span class="delta-toolbar-title">' + Craft.t('craft-delta', Craft.Delta._keys.compareRevisions) + '</span>');
      var $actions = $('<div class="delta-toolbar-actions"></div>');

      // Expand button: slideout → modal (only in slideout mode)
      if (this.mode === 'slideout') {
        var $expandBtn = $('<button type="button" class="delta-toolbar-btn" title="' + Craft.t('craft-delta', Craft.Delta._keys.expand) + '"></button>');
        $expandBtn.html('<svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2h2.5a.75.75 0 010 1.5H4.56l2.72 2.72a.75.75 0 01-1.06 1.06L3.5 4.56v1.69a.75.75 0 01-1.5 0v-2.5A1.75 1.75 0 013.75 2zm8.5 0h-2.5a.75.75 0 000 1.5h1.69L8.72 6.22a.75.75 0 001.06 1.06l2.72-2.72v1.69a.75.75 0 001.5 0v-2.5A1.75 1.75 0 0012.25 2zM3.5 9.75a.75.75 0 00-1.5 0v2.5c0 .966.784 1.75 1.75 1.75h2.5a.75.75 0 000-1.5H4.56l2.72-2.72a.75.75 0 00-1.06-1.06L3.5 11.44V9.75zm9 0a.75.75 0 011.5 0v2.5A1.75 1.75 0 0112.25 14h-2.5a.75.75 0 010-1.5h1.69l-2.72-2.72a.75.75 0 011.06-1.06l2.72 2.72V9.75z"/></svg>');
        $expandBtn.on('click', function () { self.openModal(); });
        $actions.append($expandBtn);
      }

      // Full page button (shown in slideout and modal, not in fullpage)
      if (this.mode !== 'fullpage') {
        var $fullPageBtn = $('<button type="button" class="delta-toolbar-btn" title="' + Craft.t('craft-delta', Craft.Delta._keys.openFullPage) + '"></button>');
        $fullPageBtn.html('<svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2A1.75 1.75 0 002 3.75v8.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 12.25v-3.5a.75.75 0 00-1.5 0v3.5a.25.25 0 01-.25.25h-8.5a.25.25 0 01-.25-.25v-8.5a.25.25 0 01.25-.25h3.5a.75.75 0 000-1.5h-3.5zm6.75 0a.75.75 0 000 1.5h1.19L8.22 6.97a.75.75 0 001.06 1.06l3.5-3.5v1.22a.75.75 0 001.5 0v-3A.75.75 0 0013.53 2h-3z"/></svg>');
        $fullPageBtn.on('click', function () { self.openFullPage(); });
        $actions.append($fullPageBtn);
      }

      // Close button (not shown in fullpage mode)
      if (this.mode !== 'fullpage') {
        var $closeBtn = $('<button type="button" class="delta-toolbar-btn" title="' + Craft.t('craft-delta', Craft.Delta._keys.close) + '"></button>');
        $closeBtn.html('<svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.72 3.72a.75.75 0 011.06 0L8 6.94l3.22-3.22a.75.75 0 111.06 1.06L9.06 8l3.22 3.22a.75.75 0 11-1.06 1.06L8 9.06l-3.22 3.22a.75.75 0 01-1.06-1.06L6.94 8 3.72 4.78a.75.75 0 010-1.06z"/></svg>');
        $closeBtn.on('click', function () {
          if (self.mode === 'slideout' && self.slideout) {
            self.slideout.close();
          } else if (self.mode === 'modal') {
            self.closeModal();
          }
        });
        $actions.append($closeBtn);
      }

      $topRow.append($title).append($actions);
      $toolbar.append($topRow);

      // Selectors row
      var buildSelect = function () {
        var $select = $('<select class="text"></select>');

        // "Current" option (always first)
        var currentOpt = document.createElement('option');
        currentOpt.value = 'current';
        currentOpt.textContent = Craft.t('craft-delta', Craft.Delta._keys.current);
        $select.append(currentOpt);

        // Drafts group
        if (drafts.length > 0) {
          var $draftGroup = $('<optgroup></optgroup>');
          $draftGroup.attr('label', Craft.t('craft-delta', Craft.Delta._keys.drafts));
          drafts.forEach(function (d) {
            var opt = document.createElement('option');
            opt.value = d.id;
            var text = d.label;
            if (d.date) { text += ' \u2014 ' + d.date; }
            opt.textContent = text;
            $draftGroup.append(opt);
          });
          $select.append($draftGroup);
        }

        // Revisions group
        if (revisions.length > 0) {
          var $revGroup = $('<optgroup></optgroup>');
          $revGroup.attr('label', Craft.t('craft-delta', Craft.Delta._keys.revisions));
          revisions.forEach(function (rev) {
            var opt = document.createElement('option');
            opt.value = rev.id;
            var text = rev.label;
            if (rev.date) { text += ' \u2014 ' + rev.date; }
            opt.textContent = text;
            $revGroup.append(opt);
          });
          $select.append($revGroup);
        }

        return $select;
      };

      var $older = buildSelect();
      var $newer = buildSelect();
      this.$older = $older;
      this.$newer = $newer;

      // Smart defaults
      if (this.options.isDraft && this.options.draftId) {
        $older.val('current');
        $newer.val('draft:' + this.options.draftId);
      } else if (drafts.length > 0) {
        $older.val('current');
        $newer.val(drafts[0].id);
      } else if (revisions.length >= 2) {
        $older.val(revisions[1].id);
        $newer.val('current');
      } else if (revisions.length === 1) {
        $older.val(revisions[0].id);
        $newer.val('current');
      }

      var $arrow = $('<span class="delta-selectors-arrow" aria-hidden="true">\u2192</span>');

      var $selectors = $('<div class="delta-selectors"></div>');
      $selectors.append($older).append($arrow).append($newer);
      $toolbar.append($selectors);

      // Bottom row: stats + filter
      var $bottomRow = $('<div class="delta-toolbar-bottom"></div>');

      var $statsSlot = $('<div class="delta-stats"></div>');
      this.$statsSlot = $statsSlot;
      $bottomRow.append($statsSlot);

      // Filter checkbox
      var filterId = 'delta-filter-changed';
      var $filter = $('<label class="delta-filter-toggle"></label>');
      $filter.attr('for', filterId);
      var $checkbox = $('<input type="checkbox">');
      $checkbox.attr('id', filterId);
      var changedOnly = !self.options.showUnchanged;
      $checkbox.prop('checked', changedOnly);
      $checkbox.on('change', function () {
        self.applyFilter();
      });
      self.$filterCheckbox = $checkbox;
      $filter.append($checkbox).append(Craft.t('craft-delta', Craft.Delta._keys.changedOnly));
      $bottomRow.append($filter);

      $toolbar.append($bottomRow);

      var $result = $('<div class="delta-result"></div>');
      this.$resultContainer = $result;

      var wrapperClass = 'delta-slideout';
      if (changedOnly) { wrapperClass += ' delta-changed-only'; }
      var $wrapper = $('<div class="' + wrapperClass + '"></div>');
      $wrapper.append($toolbar).append($result);
      this.$wrapper = $wrapper;

      // Bind events
      var onSelectionChange = function () {
        self.loadDiff($older.val(), $newer.val());
      };
      $older.on('change', onSelectionChange);
      $newer.on('change', onSelectionChange);

      $container.empty().append($wrapper);

      if (this._resizeHandler) {
        window.removeEventListener('resize', this._resizeHandler);
      }
      this._resizeHandler = function () { self.updateToolbarOffset(); };
      window.addEventListener('resize', this._resizeHandler);

      this.loadDiff($older.val(), $newer.val());
    },


    _collapsedFields: {},
    _debounceTimer: null,
    _loadId: 0,

    loadDiff: function (olderId, newerId) {
      var self = this;
      clearTimeout(this._debounceTimer);

      // Show loading indicator immediately for responsiveness
      if (this.$resultContainer && this.$resultContainer.length) {
        this.$resultContainer.html(
          '<div class="delta-loading">' +
          '<div class="spinner"></div>' +
          Craft.t('craft-delta', Craft.Delta._keys.comparing) +
          '</div>'
        );
      }

      this._debounceTimer = setTimeout(function () {
        self._doLoadDiff(olderId, newerId);
      }, 300);
    },

    // Re-run the current comparison. Used by review mode to recover from a
    // stale-atoms apply failure (the diff on screen is out of date).
    reload: function () {
      if (this.$older && this.$older.length && this.$newer && this.$newer.length) {
        this.loadDiff(this.$older.val(), this.$newer.val());
      }
    },

    _doLoadDiff: function (olderId, newerId) {
      var self = this;
      var $result = this.$resultContainer;

      if (!$result || !$result.length) { return; }

      // Increment request ID to ignore stale responses
      var requestId = ++this._loadId;

      Craft.sendActionRequest('POST', 'craft-delta/diff/compare', {
        data: {
          entryId: this.entryId,
          older: olderId,
          newer: newerId,
          siteId: this.options.siteId,
        },
      })
        .then(function (response) {
          // Ignore stale responses from earlier requests
          if (requestId !== self._loadId) { return; }

          // Replacing the diff DOM invalidates any in-flight review session
          // (atom ids, storage key, key handler, observers) — close it first
          // or decisions keep recording against the old comparison's state.
          if (Craft.Delta.reviewMode.active) {
            Craft.Delta.reviewMode.exit();
          }
          if (Craft.Delta.reviewComments) {
            Craft.Delta.reviewComments.closePanel();
          }

          if (!response.data.success) {
            $result.empty().append(
              $('<div class="delta-empty"></div>').append(
                $('<p></p>').text(response.data.error || Craft.t('craft-delta', Craft.Delta._keys.failedLoadDiff))
              )
            );
            return;
          }

          $result.html(response.data.html);

          // Move the stats bar from the response into the toolbar slot
          var $inlineStats = $result.find('[data-stats]');
          if ($inlineStats.length && self.$statsSlot) {
            self.$statsSlot.html($inlineStats.html());
            $inlineStats.remove();
          }

          self.bindFieldToggles($result[0]);
          self.bindTabNav($result[0]);
          self.updateToolbarOffset();

          const toolbar = document.querySelector('[data-review-toolbar]');
          if (toolbar) {
            Craft.Delta.reviewMode.checkForPriorState(toolbar);
          }

          var $wfToolbar = $result.find('.delta-workflow-toolbar');
          if ($wfToolbar.length && Craft.Delta.mountWorkflowToolbar) {
            Craft.Delta.mountWorkflowToolbar($wfToolbar);
          }
        })
        .catch(function () {
          if (requestId !== self._loadId) { return; }

          $result.empty().append(
            $('<div class="delta-empty"></div>').append(
              $('<p></p>').text(Craft.t('craft-delta', Craft.Delta._keys.failedLoadDiff))
            )
          );
        });
    },

    applyFilter: function () {
      if (!this.$wrapper || !this.$filterCheckbox) { return; }
      var checked = this.$filterCheckbox.prop('checked');
      if (checked) {
        this.$wrapper.addClass('delta-changed-only');
      } else {
        this.$wrapper.removeClass('delta-changed-only');
      }
    },

    bindFieldToggles: function (container) {
      var self = this;
      var headers = container.querySelectorAll('.delta-field-header');
      headers.forEach(function (header) {
        // The header button sits inside `.delta-field-headerbar` (alongside the
        // review accept/reject actions), so its parentElement is that wrapper —
        // walk up to the actual `.delta-field` that the collapse CSS targets and
        // that carries `data-field-handle`.
        var field = header.closest('.delta-field');
        if (!field) { return; }
        var handle = field.getAttribute('data-field-handle');

        // Restore collapsed state from previous diff load
        if (handle && self._collapsedFields[handle]) {
          field.classList.add('is-collapsed');
          header.setAttribute('aria-expanded', 'false');
        }

        header.addEventListener('click', function () {
          field.classList.toggle('is-collapsed');
          var expanded = !field.classList.contains('is-collapsed');
          header.setAttribute('aria-expanded', String(expanded));
          if (handle) {
            if (expanded) {
              delete self._collapsedFields[handle];
            } else {
              self._collapsedFields[handle] = true;
            }
          }
        });
      });

      // Nested block toggles (collapsible modified Matrix blocks)
      var blockToggles = container.querySelectorAll('.delta-block-toggle');
      blockToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
          var block = toggle.parentElement;
          block.classList.toggle('is-collapsed');
          var expanded = !block.classList.contains('is-collapsed');
          toggle.setAttribute('aria-expanded', String(expanded));
        });
      });

      this.applyFilter();
    },

    // Re-measured on every diff load + window resize because the toolbar
    // height changes when stats wrap or the filter checkbox is toggled.
    updateToolbarOffset: function () {
      if (!this.$wrapper || !this.$wrapper.length) { return; }
      var wrapper = this.$wrapper[0];
      var toolbar = wrapper.querySelector('.delta-toolbar');
      if (!toolbar) { return; }
      var height = toolbar.getBoundingClientRect().height;
      wrapper.style.setProperty('--delta-toolbar-height', height + 'px');
    },

    // Returns { el, eventTarget, isWindow } for the active mode's scroll
    // container, or null if the wrapper isn't available yet. In fullpage
    // mode the page (window) is what scrolls; in slideout/modal it's the
    // .delta-slideout wrapper.
    _resolveScroller: function () {
      if (this.mode === 'fullpage') {
        return {
          el: document.scrollingElement || document.documentElement,
          eventTarget: window,
          isWindow: true,
        };
      }
      if (!this.$wrapper || !this.$wrapper.length) {
        return null;
      }
      var el = this.$wrapper[0];
      return { el: el, eventTarget: el, isWindow: false };
    },

    bindTabNav: function (container) {
      var self = this;
      var nav = container.querySelector('.delta-tabnav');
      if (!nav) { return; }

      var scroller = self._resolveScroller();
      if (!scroller) { return; }

      var toolbar = self.$wrapper && self.$wrapper.length
        ? self.$wrapper[0].querySelector('.delta-toolbar')
        : null;

      var links = nav.querySelectorAll('.delta-tabnav-item');
      var linksByTarget = {};
      links.forEach(function (link) {
        linksByTarget[link.getAttribute('data-tab-target')] = link;

        link.addEventListener('click', function (e) {
          e.preventDefault();
          var targetId = link.getAttribute('data-tab-target');
          var target = container.querySelector('#' + targetId);
          if (!target) { return; }

          var toolbarHeight = toolbar ? toolbar.getBoundingClientRect().height : 0;
          var targetRect = target.getBoundingClientRect();
          var currentScroll = scroller.isWindow ? window.scrollY : scroller.el.scrollTop;
          var viewportTop = scroller.isWindow ? 0 : scroller.el.getBoundingClientRect().top;
          var offsetTop = targetRect.top - viewportTop + currentScroll;

          // Land the tab at toolbarHeight + 4 so it crosses the spy threshold
          // (toolbar.bottom + 4) and the active highlight moves to it.
          scroller.el.scrollTo({
            top: Math.max(0, offsetTop - toolbarHeight - 4),
            behavior: 'smooth',
          });
        });
      });

      var tabGroups = Array.prototype.slice.call(container.querySelectorAll('.delta-tab-group'));
      if (tabGroups.length === 0) { return; }

      var setActive = function (id) {
        links.forEach(function (l) { l.classList.remove('delta-tabnav-item-active'); });
        var active = id ? linksByTarget[id] : null;
        if (active) { active.classList.add('delta-tabnav-item-active'); }
      };

      var updateActive = function () {
        var threshold = (toolbar ? toolbar.getBoundingClientRect().bottom : 0) + 4;
        var current = null;
        for (var i = 0; i < tabGroups.length; i++) {
          var rect = tabGroups[i].getBoundingClientRect();
          if (rect.top <= threshold) {
            current = tabGroups[i].id;
          } else {
            break;
          }
        }
        if (!current && tabGroups.length > 0) {
          current = tabGroups[0].id;
        }
        setActive(current);
      };

      var ticking = false;
      var onScroll = function () {
        if (ticking) { return; }
        ticking = true;
        window.requestAnimationFrame(function () {
          updateActive();
          ticking = false;
        });
      };

      if (self._tabSpyHandler && self._tabSpyEventTarget) {
        self._tabSpyEventTarget.removeEventListener('scroll', self._tabSpyHandler);
      }
      self._tabSpyHandler = onScroll;
      self._tabSpyEventTarget = scroller.eventTarget;
      scroller.eventTarget.addEventListener('scroll', onScroll, { passive: true });

      updateActive();
    },
  };

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
    eventsBound: false,                 // guard so bindEvents only attaches once
    toolbarEl: null,                    // the "Start Review" bar, hidden while active
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
      // (idx + delta + length) % length is always in range for delta of ±1 and
      // length >= 1, so no extra negative-index guard is needed.
      idx = (idx + delta + ids.length) % ids.length;

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
        if (!e.key) return; // synthetic / IME-composition events have no key
        // Never hijack browser/OS shortcuts (Cmd+A select-all, Ctrl+R reload, …)
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        // Skip when typing in an input or operating a select
        if (e.target.matches('input, textarea, select, [contenteditable], [contenteditable="true"]')) return;
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
        banner.textContent = Craft.t('craft-delta', Craft.Delta._keys.entryChangedSinceLastReview);
        banner.removeAttribute('hidden');
        return;
      }

      const total = document.querySelectorAll('[data-atom-id]').length;
      const decided = Object.keys(parsed.decisions).length;

      banner.innerHTML = '';
      const text = document.createElement('span');
      text.textContent = Craft.t('craft-delta', Craft.Delta._keys.resumePreviousReview, {
        decided: decided,
        total: total,
      }) + ' ';
      banner.appendChild(text);

      const resume = document.createElement('button');
      resume.type = 'button';
      resume.className = 'btn submit';
      resume.textContent = Craft.t('craft-delta', Craft.Delta._keys.resume);
      resume.addEventListener('click', function () {
        Craft.Delta.reviewMode.enter(toolbar);
        banner.setAttribute('hidden', '');
      });
      banner.appendChild(resume);

      const fresh = document.createElement('button');
      fresh.type = 'button';
      fresh.className = 'btn';
      fresh.textContent = Craft.t('craft-delta', Craft.Delta._keys.startFresh);
      fresh.addEventListener('click', function () {
        try { localStorage.removeItem(key); } catch (e) {}
        banner.setAttribute('hidden', '');
      });
      banner.appendChild(fresh);

      banner.removeAttribute('hidden');
    },

    enter: function (toolbar) {
      // If a previous session is still active (re-enter without an explicit
      // exit — e.g. Resume after a diff reload), tear it down first so the
      // keyboard handler and IntersectionObserver don't stack and leak.
      if (this.active) { this.exit(); }

      const entryId = toolbar.dataset.entryId;
      const siteId = toolbar.dataset.siteId;
      const sourceRef = toolbar.dataset.sourceRef;
      const userId = (Craft.userId || '0');

      this.storageKey = this.REVIEW_KEY_PREFIX + userId + ':' + entryId + ':' + siteId + ':' + sourceRef;
      this.canonicalUpdatedAt = toolbar.dataset.canonicalUpdatedAt || null;
      this.active = true;
      this.state = Object.create(null);

      this.pruneStoredReviews();
      this.loadFromStorage();
      this.showStepper();
      // The standalone "Start Review" bar is redundant once review mode is
      // active — hide it so only the stepper shows.
      this.toolbarEl = toolbar;
      if (toolbar) { toolbar.style.display = 'none'; }
      this.showAllAtomActions();
      this.refreshUiFromState();
      this.bindEvents();
      this.bindKeyboardShortcuts();
      this.bindScrollFocus();
      // Auto-focus the first atom
      const ids = this.atomIdsInDocumentOrder();
      if (ids.length > 0) this.setFocus(ids[0], false);
    },

    exit: function () {
      this.active = false;
      this.state = Object.create(null);
      this.hideStepper();
      if (this.toolbarEl) { this.toolbarEl.style.display = ''; this.toolbarEl = null; }
      this.hideAllAtomActions();
      this.unbindKeyboardShortcuts();
      this.unbindScrollFocus();
      this.focusedAtomId = null;
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

      // Reflect decision on the wrapper's own buttons (filter to skip nested
      // atom buttons inside Matrix sub-fields).
      wrapper.querySelectorAll('.delta-atom-accept, .delta-atom-reject').forEach(function (btn) {
        if (btn.closest('[data-atom-id]') !== wrapper) return;
        if (btn.classList.contains('delta-atom-accept')) {
          btn.classList.toggle('is-active', decision === 'accepted');
        } else {
          btn.classList.toggle('is-active', decision === 'rejected');
        }
      });
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
      document.querySelectorAll('.delta-atom-accept.is-active, .delta-atom-reject.is-active').forEach(function (btn) {
        btn.classList.remove('is-active');
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
        progressEl.textContent = Craft.t('craft-delta', Craft.Delta._keys.decidedOfTotal, {
          decided: decided,
          total: total,
        });
      }

      const applyBtn = document.querySelector('[data-action="apply"]');
      if (applyBtn) {
        applyBtn.textContent = Craft.t('craft-delta', Craft.Delta._keys.applyCountAccepted, { count: accepted });
        applyBtn.disabled = accepted === 0;
      }
    },

    bindEvents: function () {
      // Bind the delegated click handler ONCE, to `document`. The review UI
      // (toolbar, atom buttons, stepper) is re-rendered into a fresh container
      // on every diff load and across slideout → modal → full-page switches, so
      // binding to a specific container would leave later containers without a
      // handler (and `eventsBound` would suppress re-binding). `document`
      // always persists; the handler is inert unless review mode is active.
      if (this.eventsBound) return;
      this.eventsBound = true;

      const self = this;

      // One delegated click handler covers all per-atom buttons + stepper actions
      document.addEventListener('click', function (e) {
        if (!self.active) return;

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

        if (action === 'apply') { self.apply(); return; }
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
          savedAt: Date.now(),
        }));
      } catch (e) { /* quota exceeded etc — silent */ }
    },

    // Review state is keyed per (user, entry, site, sourceRef) and is normally
    // cleared on apply/cancel — but closing the slideout without cancelling
    // orphans a key. Prune by age and cap the count so localStorage can't grow
    // unbounded over a long editorial session. Called once per enter().
    REVIEW_KEY_PREFIX: 'craftdelta:review:',
    REVIEW_KEY_MAX_AGE_MS: 30 * 24 * 60 * 60 * 1000, // 30 days
    REVIEW_KEY_MAX_COUNT: 50,
    pruneStoredReviews: function () {
      try {
        const now = Date.now();
        const entries = [];
        for (let i = 0; i < localStorage.length; i++) {
          const key = localStorage.key(i);
          if (!key || key.indexOf(this.REVIEW_KEY_PREFIX) !== 0) continue;
          let savedAt = 0;
          try { savedAt = (JSON.parse(localStorage.getItem(key)) || {}).savedAt || 0; } catch (e) {}
          entries.push({ key: key, savedAt: savedAt });
        }
        const survivors = [];
        const self = this;
        entries.forEach(function (e) {
          if (e.savedAt && (now - e.savedAt) > self.REVIEW_KEY_MAX_AGE_MS) {
            try { localStorage.removeItem(e.key); } catch (err) {}
          } else {
            survivors.push(e);
          }
        });
        if (survivors.length > this.REVIEW_KEY_MAX_COUNT) {
          survivors.sort(function (a, b) { return a.savedAt - b.savedAt; });
          survivors.slice(0, survivors.length - this.REVIEW_KEY_MAX_COUNT).forEach(function (e) {
            try { localStorage.removeItem(e.key); } catch (err) {}
          });
        }
      } catch (e) { /* localStorage unavailable — nothing to prune */ }
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
        if (!confirm(Craft.t('craft-delta', Craft.Delta._keys.discardDecisions, { decided: decided }))) return;
      }
      try { localStorage.removeItem(this.storageKey); } catch (e) {}
      this.exit();
    },

    apply: function () {
      const self = this;
      const accepted = Object.entries(this.state)
        .filter(function (kv) { return kv[1] === 'accepted'; })
        .map(function (kv) { return kv[0]; });

      if (accepted.length === 0) return;

      const confirmed = confirm(Craft.t(
        'craft-delta',
        Craft.Delta._keys.publishAcceptedConfirm,
        { count: accepted.length }
      ));
      if (!confirmed) return;

      const toolbar = document.querySelector('[data-review-toolbar]');
      if (!toolbar) {
        // The diff (and its toolbar) was replaced out from under us; nothing
        // safe to apply against.
        self.handleApplyError({ errorCode: 'stale-atoms' });
        return;
      }
      const entryId = toolbar.dataset.entryId;
      const siteId = toolbar.dataset.siteId;
      const sourceRef = toolbar.dataset.sourceRef;
      const sourceUpdatedAt = toolbar.dataset.sourceUpdatedAt || '';
      const deleteSourceCheckbox = document.querySelector('[data-delete-source-draft]');
      const deleteSourceDraft = !!(deleteSourceCheckbox && deleteSourceCheckbox.checked);

      Craft.sendActionRequest('POST', 'craft-delta/diff/apply', {
        data: {
          entryId: parseInt(entryId, 10),
          siteId: parseInt(siteId, 10),
          sourceRef: sourceRef,
          sourceUpdatedAt: sourceUpdatedAt,
          acceptedAtoms: accepted,
          deleteSourceDraft: deleteSourceDraft ? 1 : 0,
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
      const goNow = confirm(Craft.t('craft-delta', Craft.Delta._keys.changesPublishedOpenEntry));
      if (goNow && data.entryEditUrl) {
        window.location.href = data.entryEditUrl;
      }
    },

    handleApplyError: function (data) {
      const banner = document.querySelector('[data-review-banner]');
      switch (data.errorCode) {
        case 'stale-atoms':
          try { localStorage.removeItem(this.storageKey); } catch (e) {}
          if (banner) {
            banner.textContent = data.error || Craft.t('craft-delta', Craft.Delta._keys.entryChangedSinceReviewStarted);
            banner.removeAttribute('hidden');
          }
          // Trigger a fresh diff reload if a helper exists; otherwise no-op.
          if (typeof Craft.Delta.reload === 'function') {
            Craft.Delta.reload();
          }
          break;
        case 'validation-failed':
          // Preserve localStorage; show the error
          alert((data.error || Craft.t('craft-delta', Craft.Delta._keys.validationFailed)) + '\n\n' + Craft.t('craft-delta', Craft.Delta._keys.decisionsSavedRetry));
          break;
        case 'no-changes':
          // Shouldn't happen — apply button is disabled when 0 accepted
          alert(data.error || Craft.t('craft-delta', Craft.Delta._keys.noChangesToApply));
          break;
        default:
          alert((data.error || Craft.t('craft-delta', Craft.Delta._keys.applyFailed)) + '\n\n' + Craft.t('craft-delta', Craft.Delta._keys.decisionsStillSaved));
      }
    },
  };

  // Helper for querySelector — atom IDs contain colons which are invalid in
  // CSS selectors unless escaped. CSS.escape may not be available in older browsers.
  function cssEscape(s) {
    return (window.CSS && window.CSS.escape) ? window.CSS.escape(s) : s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  // Top-level delegated listener for the Start Review button. Module-level
  // is consistent with the rest of init wiring in this file.
  document.addEventListener('click', function (e) {
    const startBtn = e.target.closest('[data-action="start-review"]');
    if (!startBtn) return;
    const toolbar = startBtn.closest('[data-review-toolbar]');
    if (!toolbar) return;
    Craft.Delta.reviewMode.enter(toolbar);
  });
})();
