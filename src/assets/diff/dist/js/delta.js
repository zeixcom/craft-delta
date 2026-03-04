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

    // ─── Init ───

    init: function (entryId, options) {
      this.entryId = entryId;
      this.options = options || {};
      this.bindCompareButton();
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

    // ─── Slideout Mode ───

    openSlideout: function () {
      this.mode = 'slideout';
      var self = this;

      var $loading = $('<div class="delta-slideout">' +
        '<div class="delta-loading">' +
        '<div class="spinner"></div>' +
        Craft.t('craft-delta', 'Loading revisions\u2026') +
        '</div></div>');

      var slideout = new Craft.Slideout($loading);
      slideout.open();
      this.slideout = slideout;

      this.fetchRevisionsAndBuild(slideout.$container);
    },

    // ─── Modal Mode ───

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

    // ─── Full Page Mode ───

    openFullPage: function () {
      this.closeModal();
      var url = Craft.getCpUrl('craft-delta/compare', { entryId: this.entryId, siteId: this.options.siteId });
      window.location.href = url;
    },

    // ─── Shared build logic ───

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
              Craft.t('craft-delta', 'At least two revisions are needed to compare.') +
              '</p></div></div>'
            );
            return;
          }

          self.buildUI($container, revisions, drafts);
        })
        .catch(function () {
          $container.html(
            '<div class="delta-slideout"><div class="delta-empty"><p>' +
            Craft.t('craft-delta', 'Failed to load revisions.') +
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
            label: Craft.t('craft-delta', 'Current Draft'),
            date: '',
          });
        }
      }

      // ─── Build toolbar ───
      var $toolbar = $('<div class="delta-toolbar"></div>');

      // Top row: title + actions
      var $topRow = $('<div class="delta-toolbar-top"></div>');
      var $title = $('<span class="delta-toolbar-title">' + Craft.t('craft-delta', 'Compare Revisions') + '</span>');
      var $actions = $('<div class="delta-toolbar-actions"></div>');

      // Expand button: slideout → modal (only in slideout mode)
      if (this.mode === 'slideout') {
        var $expandBtn = $('<button type="button" class="delta-toolbar-btn" title="' + Craft.t('craft-delta', 'Expand') + '"></button>');
        $expandBtn.html('<svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2h2.5a.75.75 0 010 1.5H4.56l2.72 2.72a.75.75 0 01-1.06 1.06L3.5 4.56v1.69a.75.75 0 01-1.5 0v-2.5A1.75 1.75 0 013.75 2zm8.5 0h-2.5a.75.75 0 000 1.5h1.69L8.72 6.22a.75.75 0 001.06 1.06l2.72-2.72v1.69a.75.75 0 001.5 0v-2.5A1.75 1.75 0 0012.25 2zM3.5 9.75a.75.75 0 00-1.5 0v2.5c0 .966.784 1.75 1.75 1.75h2.5a.75.75 0 000-1.5H4.56l2.72-2.72a.75.75 0 00-1.06-1.06L3.5 11.44V9.75zm9 0a.75.75 0 011.5 0v2.5A1.75 1.75 0 0112.25 14h-2.5a.75.75 0 010-1.5h1.69l-2.72-2.72a.75.75 0 011.06-1.06l2.72 2.72V9.75z"/></svg>');
        $expandBtn.on('click', function () { self.openModal(); });
        $actions.append($expandBtn);
      }

      // Full page button (shown in slideout and modal, not in fullpage)
      if (this.mode !== 'fullpage') {
        var $fullPageBtn = $('<button type="button" class="delta-toolbar-btn" title="' + Craft.t('craft-delta', 'Open full page') + '"></button>');
        $fullPageBtn.html('<svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2A1.75 1.75 0 002 3.75v8.5c0 .966.784 1.75 1.75 1.75h8.5A1.75 1.75 0 0014 12.25v-3.5a.75.75 0 00-1.5 0v3.5a.25.25 0 01-.25.25h-8.5a.25.25 0 01-.25-.25v-8.5a.25.25 0 01.25-.25h3.5a.75.75 0 000-1.5h-3.5zm6.75 0a.75.75 0 000 1.5h1.19L8.22 6.97a.75.75 0 001.06 1.06l3.5-3.5v1.22a.75.75 0 001.5 0v-3A.75.75 0 0013.53 2h-3z"/></svg>');
        $fullPageBtn.on('click', function () { self.openFullPage(); });
        $actions.append($fullPageBtn);
      }

      // Close button (not shown in fullpage mode)
      if (this.mode !== 'fullpage') {
        var $closeBtn = $('<button type="button" class="delta-toolbar-btn" title="Close"></button>');
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
        currentOpt.textContent = Craft.t('craft-delta', 'Current');
        $select.append(currentOpt);

        // Drafts group
        if (drafts.length > 0) {
          var $draftGroup = $('<optgroup></optgroup>');
          $draftGroup.attr('label', Craft.t('craft-delta', 'Drafts'));
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
          $revGroup.attr('label', Craft.t('craft-delta', 'Revisions'));
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

      var $swapBtn = $('<button type="button" class="delta-swap-btn" title="Swap">\u21C4</button>');
      $swapBtn.on('click', function () {
        var o = $older.val(), n = $newer.val();
        $older.val(n);
        $newer.val(o);
        $swapBtn.addClass('delta-swap-active');
        setTimeout(function () {
          $swapBtn.removeClass('delta-swap-active');
        }, 300);
        self.loadDiff($older.val(), $newer.val());
      });

      var $selectors = $('<div class="delta-selectors"></div>');
      $selectors.append($older).append($swapBtn).append($newer);
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
      $filter.append($checkbox).append(Craft.t('craft-delta', 'Changed only'));
      $bottomRow.append($filter);

      $toolbar.append($bottomRow);

      // ─── Result container ───
      var $result = $('<div class="delta-result"></div>');
      this.$resultContainer = $result;

      // ─── Assemble ───
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

      // Auto-load
      this.loadDiff($older.val(), $newer.val());
    },

    // ─── Load Diff ───

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
          Craft.t('craft-delta', 'Comparing\u2026') +
          '</div>'
        );
      }

      this._debounceTimer = setTimeout(function () {
        self._doLoadDiff(olderId, newerId);
      }, 300);
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

          if (!response.data.success) {
            $result.html(
              '<div class="delta-empty"><p>' +
              (response.data.error || Craft.t('craft-delta', 'Failed to load diff.')) +
              '</p></div>'
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
        })
        .catch(function () {
          if (requestId !== self._loadId) { return; }

          $result.html(
            '<div class="delta-empty"><p>' +
            Craft.t('craft-delta', 'Failed to load diff.') +
            '</p></div>'
          );
        });
    },

    // ─── Field Interactions ───

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
        var field = header.parentElement;
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
  };
})();
