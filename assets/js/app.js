/* =========================================================
   RMC EVENTS — MAIN APPLICATION JAVASCRIPT
   =========================================================
   Shared UI behavior: sidebar, dropdowns, modals, toasts,
   mobile navigation, password visibility, scroll reveals.
   ========================================================= */

(function() {
  'use strict';

  /* =========================================================
     STATE MANAGEMENT
     ========================================================= */
  const state = {
    sidebarOpen: false,
    sidebarCollapsed: false,
    notificationPanelOpen: false,
    activeModals: [],
    toasts: [],
  };

  /* =========================================================
     UTILITY FUNCTIONS
     ========================================================= */
  const $ = (selector, context = document) => context.querySelector(selector);
  const $$ = (selector, context = document) => Array.from(context.querySelectorAll(selector));

  const createElement = (html) => {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    return template.content.firstElementChild;
  };

  const trapFocus = (element) => {
    const focusable = element.querySelectorAll(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    element.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;
      if (e.shiftKey) {
        if (document.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    });

    first?.focus();
  };

  const preventBodyScroll = (prevent) => {
    document.body.style.overflow = prevent ? 'hidden' : '';
  };

  /* =========================================================
     SIDEBAR
     ========================================================= */
  const sidebar = {
    elements: {
      sidebar: null,
      overlay: null,
      toggle: null,
      mainContent: null,
    },

    init() {
      this.elements.sidebar = $('#mobileSidebar') || $('.sidebar');
      this.elements.overlay = $('#mobileOverlay') || $('.mobile-overlay');
      this.elements.toggle = $('.menu-toggle, .sidebar-toggle');
      this.elements.mainContent = $('.main-content');

      this.bindEvents();
      this.restoreState();
    },

    bindEvents() {
      // Toggle buttons
      $$('.menu-toggle, .sidebar-toggle').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          this.toggle();
        });
      });

      // Overlay click
      if (this.elements.overlay) {
        this.elements.overlay.addEventListener('click', () => this.close());
      }

      // Close on Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.sidebarOpen) {
          this.close();
        }
      });

      // Responsive: close sidebar on resize to desktop
      window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024 && state.sidebarOpen) {
          this.close();
        }
      });

      // Collapse/expand on desktop
      const collapseBtn = $('.sidebar-collapse-btn');
      if (collapseBtn) {
        collapseBtn.addEventListener('click', () => this.toggleCollapse());
      }
    },

    open() {
      if (this.elements.sidebar) {
        this.elements.sidebar.classList.add('open');
        state.sidebarOpen = true;
      }
      if (this.elements.overlay) {
        this.elements.overlay.classList.add('open');
      }
      preventBodyScroll(true);
      this.saveState();
    },

    close() {
      if (this.elements.sidebar) {
        this.elements.sidebar.classList.remove('open');
        state.sidebarOpen = false;
      }
      if (this.elements.overlay) {
        this.elements.overlay.classList.remove('open');
      }
      preventBodyScroll(false);
      this.saveState();
    },

    toggle() {
      if (state.sidebarOpen) this.close(); else this.open();
    },

    toggleCollapse() {
      if (this.elements.sidebar) {
        this.elements.sidebar.classList.toggle('collapsed');
        state.sidebarCollapsed = !state.sidebarCollapsed;
        this.saveState();
      }
    },

    saveState() {
      try {
        localStorage.setItem('rmc_sidebar_collapsed', state.sidebarCollapsed);
      } catch (e) { /* ignore */ }
    },

    restoreState() {
      try {
        const collapsed = localStorage.getItem('rmc_sidebar_collapsed') === 'true';
        if (collapsed && this.elements.sidebar) {
          this.elements.sidebar.classList.add('collapsed');
          state.sidebarCollapsed = true;
        }
      } catch (e) { /* ignore */ }
    },
  };

  /* =========================================================
     NOTIFICATION PANEL
     ========================================================= */
  const notificationPanel = {
    elements: {
      button: null,
      panel: null,
    },

    init() {
      this.elements.button = $('.notif-btn');
      this.elements.panel = $('#notificationPanel');

      this.bindEvents();
    },

    bindEvents() {
      if (this.elements.button) {
        this.elements.button.addEventListener('click', (e) => {
          e.stopPropagation();
          this.toggle();
        });
      }

      // Close on outside click
      document.addEventListener('click', (e) => {
        if (state.notificationPanelOpen && this.elements.panel) {
          if (!this.elements.panel.contains(e.target) && !this.elements.button?.contains(e.target)) {
            this.close();
          }
        }
      });

      // Close on Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && state.notificationPanelOpen) {
          this.close();
        }
      });
    },

    open() {
      if (this.elements.panel) {
        this.elements.panel.classList.remove('hidden');
        state.notificationPanelOpen = true;
        trapFocus(this.elements.panel);
      }
    },

    close() {
      if (this.elements.panel) {
        this.elements.panel.classList.add('hidden');
        state.notificationPanelOpen = false;
      }
    },

    toggle() {
      if (state.notificationPanelOpen) this.close(); else this.open();
    },
  };

  // Global function for backward compatibility
  window.toggleNotificationPanel = () => notificationPanel.toggle();

  /* =========================================================
     MODALS
     ========================================================= */
  const modals = {
    init() {
      // No global setup needed — modals are built dynamically
      // via open()/alert()/confirm() when triggered. This exists
      // so the shared init() loop below can call modals.init()
      // without erroring, matching the other modules.
    },

    open(html, options = {}) {
      const overlay = createElement(`
        <div class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title">
          <div class="modal">${html}</div>
        </div>
      `);

      const modal = overlay.querySelector('.modal');
      const closeBtn = modal.querySelector('.modal-close');

      document.body.appendChild(overlay);

      // Force reflow for animation
      requestAnimationFrame(() => {
        overlay.classList.add('open');
      });

      // Close handlers
      const close = () => {
        overlay.classList.remove('open');
        setTimeout(() => overlay.remove(), 200);
        const idx = state.activeModals.indexOf(close);
        if (idx > -1) state.activeModals.splice(idx, 1);
        preventBodyScroll(state.activeModals.length > 0);
      };

      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close();
      });

      if (closeBtn) {
        closeBtn.addEventListener('click', close);
      }

      // Escape key
      const escHandler = (e) => {
        if (e.key === 'Escape') close();
      };
      document.addEventListener('keydown', escHandler);
      modal.dataset.escHandler = 'added';

      // Cleanup on close
      const originalClose = close;
      const wrappedClose = () => {
        document.removeEventListener('keydown', escHandler);
        originalClose();
      };
      modal.close = wrappedClose;

      state.activeModals.push(wrappedClose);
      preventBodyScroll(true);

      // Focus management
      trapFocus(modal);

      return { close: wrappedClose, element: modal };
    },

    closeAll() {
      [...state.activeModals].forEach(close => close());
    },

    alert(title, message, type = 'info') {
      const icons = {
        success: 'fa-circle-check',
        error: 'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info',
      };
      const iconClass = icons[type] || icons.info;

      return this.open(`
        <div class="modal-header">
          <h3 id="modal-title" class="modal-title">${title}</h3>
          <button class="modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-${type}">
            <i class="fa-solid ${iconClass} alert-icon"></i>
            <div class="alert-content">
              <p class="alert-message">${message}</p>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-primary" data-modal-close>OK</button>
        </div>
      `);
    },

    confirm(title, message, onConfirm, onCancel, options = {}) {
      const { confirmText = 'Confirm', cancelText = 'Cancel', danger = false } = options;

      return this.open(`
        <div class="modal-header">
          <h3 id="modal-title" class="modal-title">${title}</h3>
          <button class="modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
          <p>${message}</p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-modal-cancel>${cancelText}</button>
          <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-modal-confirm>${confirmText}</button>
        </div>
      `, {
        onClose: () => {
          const modal = this;
          modal.element.querySelector('[data-modal-confirm]').addEventListener('click', () => {
            onConfirm();
            modal.close();
          });
          modal.element.querySelector('[data-modal-cancel]').addEventListener('click', () => {
            if (onCancel) onCancel();
            modal.close();
          });
        }
      });
    },
  };

  /* =========================================================
     TOASTS / NOTIFICATIONS
     ========================================================= */
  const toasts = {
    container: null,

    init() {
      this.container = document.createElement('div');
      this.container.className = 'toast-container';
      this.container.setAttribute('role', 'region');
      this.container.setAttribute('aria-live', 'polite');
      this.container.setAttribute('aria-label', 'Notifications');
      document.body.appendChild(this.container);
    },

    show(message, options = {}) {
      const { title = '', type = 'info', duration = 5000, action } = options;
      const icons = {
        success: 'fa-circle-check',
        error: 'fa-circle-xmark',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info',
      };

      const toast = createElement(`
        <div class="toast toast-${type}" role="alert">
          <i class="fa-solid ${icons[type]} toast-icon"></i>
          <div class="toast-content">
            ${title ? `<p class="toast-title">${title}</p>` : ''}
            <p class="toast-message">${message}</p>
          </div>
          <button class="toast-close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>
        </div>
      `);

      this.container.appendChild(toast);

      // Close button
      toast.querySelector('.toast-close').addEventListener('click', () => this.remove(toast));

      // Auto dismiss
      let timeoutId;
      if (duration > 0) {
        timeoutId = setTimeout(() => this.remove(toast), duration);
      }

      // Pause on hover
      toast.addEventListener('mouseenter', () => {
        if (timeoutId) clearTimeout(timeoutId);
      });
      toast.addEventListener('mouseleave', () => {
        if (duration > 0) {
          timeoutId = setTimeout(() => this.remove(toast), duration);
        }
      });

      // Action button
      if (action) {
        const actionBtn = createElement(`<button class="btn btn-sm btn-outline">${action.label}</button>`);
        actionBtn.addEventListener('click', () => {
          action.handler();
          this.remove(toast);
        });
        toast.querySelector('.toast-content').appendChild(actionBtn);
      }

      return { remove: () => this.remove(toast) };
    },

    remove(toast) {
      if (!toast || toast.classList.contains('removing')) return;
      toast.classList.add('removing');
      setTimeout(() => toast.remove(), 200);
    },

    success(message, options) { return this.show(message, { ...options, type: 'success' }); },
    error(message, options) { return this.show(message, { ...options, type: 'error' }); },
    warning(message, options) { return this.show(message, { ...options, type: 'warning' }); },
    info(message, options) { return this.show(message, { ...options, type: 'info' }); },
  };

  /* =========================================================
     PASSWORD VISIBILITY TOGGLE
     ========================================================= */
  const passwordToggle = {
    init() {
      $$('.form-input-action[data-toggle-password]').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const input = btn.closest('.form-input-wrapper').querySelector('.form-input');
          if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = btn.dataset.hideText || 'Hide';
          } else {
            input.type = 'password';
            btn.textContent = btn.dataset.showText || 'Show';
          }
        });
      });
    },
  };

  /* =========================================================
     DROPDOWNS
     ========================================================= */
  const dropdowns = {
    init() {
      $$('.dropdown').forEach(dropdown => {
        const trigger = dropdown.querySelector('[data-dropdown-trigger]');
        const menu = dropdown.querySelector('.dropdown-menu');

        if (trigger && menu) {
          trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggle(dropdown);
          });
        }
      });

      // Close on outside click
      document.addEventListener('click', (e) => {
        $$('.dropdown-menu.open').forEach(menu => {
          if (!menu.parentElement.contains(e.target)) {
            menu.classList.remove('open');
          }
        });
      });

      // Close on Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          $$('.dropdown-menu.open').forEach(menu => menu.classList.remove('open'));
        }
      });
    },

    toggle(dropdown) {
      const menu = dropdown.querySelector('.dropdown-menu');
      const isOpen = menu.classList.contains('open');

      // Close all other dropdowns
      $$('.dropdown-menu.open').forEach(m => {
        if (m !== menu) m.classList.remove('open');
      });

      menu.classList.toggle('open', !isOpen);
    },

    closeAll() {
      $$('.dropdown-menu.open').forEach(menu => menu.classList.remove('open'));
    },
  };

  /* =========================================================
     TABS
   ========================================================= */
  const tabs = {
    init() {
      $$('.tabs').forEach(tabList => {
        tabList.addEventListener('click', (e) => {
          const tab = e.target.closest('.tab');
          if (!tab) return;

          const tabs = tabList.querySelectorAll('.tab');
          const panels = tabList.parentElement?.querySelectorAll('.tab-panel');

          tabs.forEach(t => t.classList.remove('active'));
          tab.classList.add('active');

          if (panels) {
            const index = Array.from(tabs).indexOf(tab);
            panels.forEach((p, i) => p.classList.toggle('active', i === index));
          }
        });

        // Keyboard navigation
        tabList.addEventListener('keydown', (e) => {
          const tabs = Array.from(tabList.querySelectorAll('.tab'));
          const activeIndex = tabs.findIndex(t => t.classList.contains('active'));
          let newIndex = activeIndex;

          if (e.key === 'ArrowRight') newIndex = (activeIndex + 1) % tabs.length;
          else if (e.key === 'ArrowLeft') newIndex = (activeIndex - 1 + tabs.length) % tabs.length;
          else if (e.key === 'Home') newIndex = 0;
          else if (e.key === 'End') newIndex = tabs.length - 1;
          else return;

          e.preventDefault();
          tabs[newIndex].click();
          tabs[newIndex].focus();
        });
      });
    },
  };

  /* =========================================================
     ACCORDION
     ========================================================= */
  const accordion = {
    init() {
      $$('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
          const item = header.closest('.accordion-item');
          const isOpen = item.classList.contains('open');

          // Close siblings if single-open mode
          if (item.dataset.accordion !== 'multiple') {
            $$('.accordion-item.open').forEach(openItem => {
              if (openItem !== item) openItem.classList.remove('open');
            });
          }

          item.classList.toggle('open', !isOpen);
        });
      });
    },
  };

  /* =========================================================
     FORM ENHANCEMENTS
     ========================================================= */
  const forms = {
    init() {
      // Floating labels
      $$('.form-input').forEach(input => {
        const wrapper = input.closest('.form-input-wrapper');
        if (!wrapper) return;

        const update = () => {
          wrapper.classList.toggle('has-value', input.value !== '');
        };

        input.addEventListener('input', update);
        input.addEventListener('blur', update);
        update(); // Initial check
      });

      // Character counter
      $$('[data-maxlength]').forEach(input => {
        const max = parseInt(input.dataset.maxlength, 10);
        const counter = document.createElement('div');
        counter.className = 'text-xs text-tertiary text-right mt-1';
        counter.innerHTML = `${input.value.length} / ${max}`;
        input.parentNode.appendChild(counter);

        input.addEventListener('input', () => {
          counter.textContent = `${input.value.length} / ${max}`;
          counter.classList.toggle('text-danger', input.value.length > max * 0.9);
        });
      });

      // Auto-resize textarea
      $$('textarea.form-input[data-auto-resize]').forEach(textarea => {
        const resize = () => {
          textarea.style.height = 'auto';
          textarea.style.height = textarea.scrollHeight + 'px';
        };
        textarea.addEventListener('input', resize);
        resize();
      });
    },
  };

  /* =========================================================
     SCROLL REVEAL ANIMATIONS
     ========================================================= */
  const scrollReveal = {
    observer: null,

    init() {
      if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        $$('[data-reveal]').forEach(el => el.style.opacity = '1');
        return;
      }

      this.observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('revealed');
            this.observer.unobserve(entry.target);
          }
        });
      }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px',
      });

      $$('[data-reveal]').forEach(el => this.observer.observe(el));
    },
  };

  /* =========================================================
     LOADING OVERLAY (Global)
     ========================================================= */
  const loadingOverlay = {
    overlay: null,
    textEl: null,
    subEl: null,
    count: 0,

    init() {
      this.overlay = $('#rmcLoadingOverlay');
      if (this.overlay) {
        this.textEl = $('#rmcLoadingText');
        this.subEl = $('#rmcLoadingSubtext');
      }
    },

    show(message = { text: 'Processing...', sub: 'Please wait.' }) {
      if (!this.overlay) return;
      this.count++;
      if (this.textEl) this.textEl.textContent = message.text || 'Processing...';
      if (this.subEl) this.subEl.textContent = message.sub || 'Please wait.';
      this.overlay.classList.remove('hidden');
      preventBodyScroll(true);
    },

    hide() {
      if (!this.overlay) return;
      this.count = Math.max(0, this.count - 1);
      if (this.count === 0) {
        this.overlay.classList.add('hidden');
        preventBodyScroll(false);
      }
    },
  };

  /* =========================================================
     FORM SUBMIT LOADING INTEGRATION
     ========================================================= */
  const formLoading = {
    init() {
      // Listen for form submits
      document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.method && form.method.toUpperCase() !== 'POST') return;
        if (form.closest('#rmcLoadingOverlay')) return;
        if (form.hasAttribute('data-no-loading')) return;

        const fd = new FormData(form);
        const actionKey = this.detectAction(fd);
        const messages = {
          create_event: { text: 'Creating event...', sub: 'Please wait.' },
          approve_id: { text: 'Approving...', sub: 'Please wait.' },
          reject_id: { text: 'Rejecting...', sub: 'Please wait.' },
          archive_id: { text: 'Archiving...', sub: 'Please wait.' },
          unarchive_id: { text: 'Restoring...', sub: 'Please wait.' },
          delete_id: { text: 'Deleting...', sub: 'This may take a moment.' },
          toggle_status: { text: 'Updating user...', sub: 'Please wait.' },
          unlock_user_id: { text: 'Unlocking account...', sub: 'Please wait.' },
          delete_notification_id: { text: 'Deleting...', sub: 'Please wait.' },
          bulk_action: { text: 'Processing bulk action...', sub: 'This may take a moment.' },
          toggle_setting: { text: 'Saving settings...', sub: 'Please wait.' },
          bulk_delete_ids: { text: 'Deleting...', sub: 'Please wait.' },
          mark_all_read: { text: 'Marking as read...', sub: 'Please wait.' },
          mark_read: { text: 'Marking as read...', sub: 'Please wait.' },
          register_event_id: { text: 'Registering...', sub: 'Please wait.' },
        };

        const msg = messages[actionKey] || { text: 'Processing...', sub: 'Please wait.' };
        loadingOverlay.show(msg);

        // Disable submit button
        const btn = form.querySelector('button[type="submit"], button:not([type="button"])');
        if (btn) {
          btn.disabled = true;
          btn.setAttribute('data-rmc-disabled', '1');
        }
      }, true);
    },

    detectAction(formData) {
      const keys = [
        'create_event', 'approve_id', 'reject_id', 'archive_id',
        'unarchive_id', 'delete_id', 'toggle_status', 'unlock_user_id',
        'delete_notification_id', 'bulk_action', 'toggle_setting',
        'bulk_delete_ids', 'mark_all_read', 'mark_read', 'register_event_id'
      ];
      for (const key of keys) {
        if (formData.has(key)) return key;
      }
      return null;
    },
  };

  /* =========================================================
     THEME / DARK MODE
     ========================================================= */
  const theme = {
    init() {
      // Theme is handled by dark_mode.php partial
      // This just ensures the document class is set
      const saved = localStorage.getItem('theme');
      if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
      }

      // Listen for theme changes from dark_mode.php
      window.addEventListener('storage', (e) => {
        if (e.key === 'theme') {
          document.documentElement.classList.toggle('dark', e.newValue === 'dark');
        }
      });
    },
  };

  /* =========================================================
     TOOLTIPS INITIALIZATION
     ========================================================= */
  const tooltips = {
    init() {
      // CSS-only tooltips via [data-tooltip] attribute
      // No JS needed, but we can enhance if needed
    },
  };

  /* =========================================================
     PER-TAB ROLE BRIDGE
     ========================================================= */
  const roleBridge = {
    init() {
      // Read server role from data attribute on <body> set by PHP
      const body = document.body;
      const serverRole = body.dataset.rmcRole || '';

      try {
        var stored = sessionStorage.getItem('rmc_role');

        if (!stored && serverRole) {
          stored = serverRole;
          sessionStorage.setItem('rmc_role', stored);
        }

        function writeCookie() {
          if (!stored) return;
          document.cookie = 'rmc_tab_role=' + encodeURIComponent(stored) + ';path=/;samesite=Lax';
        }

        writeCookie();

        /* Self-heal: this tab shows another role's content → bounce once */
        if (
          serverRole && stored &&
          serverRole !== stored &&
          location.pathname.indexOf('login') === -1 &&
          location.search.indexOf('rmc_role=') === -1
        ) {
          writeCookie();
          var sep = location.search ? '&' : '?';
          location.replace(location.pathname + location.search + sep + 'rmc_role=' + encodeURIComponent(stored));
          return;
        }

        /* Tag link clicks with this tab's role */
        document.addEventListener('click', function(e) {
          var link = e.target.closest && e.target.closest('a[href]');
          if (!link) return;

          var href = link.getAttribute('href');
          if (
            !href || href.charAt(0) === '#' ||
            /^(https?:)?\/\//i.test(href) ||
            /^(mailto|tel|javascript):/i.test(href) ||
            href.indexOf('rmc_role=') !== -1 ||
            href.indexOf('logout') !== -1
          ) return;

          if (link.dataset.rmcTagged === '1') return;
          link.dataset.rmcTagged = '1';

          link.href = href + (href.indexOf('?') === -1 ? '?' : '&') +
            'rmc_role=' + encodeURIComponent(stored || serverRole || '');
        }, true);

        /* Tag form posts with this tab's role */
        document.addEventListener('submit', function(e) {
          var form = e.target;
          if (!form || !form.elements || form.elements['rmc_role']) return;
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'rmc_role';
          input.value = stored || serverRole || '';
          form.appendChild(input);
        }, true);

        /* Keep the shared cookie pointing at this tab right before leaving */
        window.addEventListener('pagehide', writeCookie);
        document.addEventListener('visibilitychange', function() {
          if (document.visibilityState === 'visible') {
            var cur = sessionStorage.getItem('rmc_role');
            if (cur && cur !== stored) {
              stored = cur;
              if (serverRole && stored !== serverRole &&
                location.pathname.indexOf('login') === -1 &&
                location.search.indexOf('rmc_role=') === -1) {
                var sep2 = location.search ? '&' : '?';
                location.replace(location.pathname + location.search + sep2 + 'rmc_role=' + encodeURIComponent(stored));
              }
            }
            writeCookie();
          }
        });
      } catch (err) { /* sessionStorage unavailable — legacy behavior */ }
    },
  };
  function init() {
    // Initialize all modules
    sidebar.init();
    notificationPanel.init();
    modals.init();
    toasts.init();
    passwordToggle.init();
    dropdowns.init();
    tabs.init();
    accordion.init();
    forms.init();
    scrollReveal.init();
    loadingOverlay.init();
    formLoading.init();
    theme.init();
    tooltips.init();
    roleBridge.init();

    // Mark page as loaded for CSS animations
    document.body.classList.add('loaded');

    // Dispatch custom event for other scripts
    document.dispatchEvent(new CustomEvent('rmc:app:ready'));
  }

  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* =========================================================
     GLOBAL EXPORTS
     ========================================================= */
  window.RMC = {
    sidebar,
    notificationPanel,
    modals,
    toasts,
    dropdowns,
    tabs,
    accordion,
    loadingOverlay,
    formLoading,
    roleBridge,
  };
})();