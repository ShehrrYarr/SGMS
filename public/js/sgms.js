/**
 * SGMS shell behaviour for the Herozi layout + Livewire SPA navigation.
 * Loaded once in <head>; everything uses event delegation so it keeps
 * working after `wire:navigate` swaps the page body.
 */
(function () {
  'use strict';

  const root = document.documentElement;
  const store = {
    get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
  };

  const SGMS = {
    store,

    applyLayout() {
      if (root.hasAttribute('data-layout-locked')) return;
      const width = window.innerWidth;
      const preferred = store.get('sgms-sidebar');
      const layout = width < 768 ? 'close-sidebar' : (preferred || (width < 1200 ? 'small-icon' : 'vertical'));
      root.setAttribute('data-main-layout', layout);
    },

    toggleSidebar() {
      const next = root.getAttribute('data-main-layout') === 'small-icon' ? 'vertical' : 'small-icon';
      store.set('sgms-sidebar', next);
      root.setAttribute('data-main-layout', next);
    },

    setTheme(theme) {
      root.setAttribute('data-bs-theme', theme);
      store.set('sgms-theme', theme);
    },

    toggleTheme() {
      this.setTheme(root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
    },

    toast(type, message) {
      if (!message) return;
      if (!window.Swal) { alert(message); return; }
      Swal.fire({
        toast: true, position: 'top-end', icon: type || 'success', title: message,
        showConfirmButton: false, timer: type === 'error' ? 5000 : 3000, timerProgressBar: true,
      });
    },

    /** Promise<boolean> confirmation dialog. */
    confirm({ title = 'Are you sure?', text = '', confirmText = 'Yes, continue', icon = 'warning', danger = true } = {}) {
      if (!window.Swal) return Promise.resolve(window.confirm(title));
      return Swal.fire({
        title, text, icon, showCancelButton: true, confirmButtonText: confirmText, reverseButtons: true,
        buttonsStyling: false,
        customClass: { confirmButton: 'btn ' + (danger ? 'btn-danger' : 'btn-primary') + ' ms-2', cancelButton: 'btn btn-light' },
      }).then((r) => r.isConfirmed);
    },

    /** Print a URL through a hidden iframe (no popup blockers). */
    print(url) {
      let frame = document.getElementById('sgms-print-frame');
      if (frame) frame.remove();
      frame = document.createElement('iframe');
      frame.id = 'sgms-print-frame';
      frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
      frame.src = url;
      document.body.appendChild(frame);
    },

    flash() {
      document.querySelectorAll('[data-sgms-flash]').forEach((el) => {
        SGMS.toast(el.dataset.type, el.dataset.message);
        el.remove();
      });
    },

    /**
     * ApexCharts with app defaults. Extra options:
     *   money: true          -> currency axis + tooltip
     *   colors: ['primary']  -> theme colour names are resolved from CSS variables
     */
    chart(el, options = {}) {
      if (!window.ApexCharts || !el) return null;
      const { money, ...rest } = options;
      const short = (v) => SGMS.money(v).replace(/\.00$/, '');
      const resolve = (c) => (typeof c === 'string' && !c.startsWith('#') && !c.startsWith('rgb') ? SGMS.color(c) : c);

      const config = {
        dataLabels: { enabled: false },
        grid: { borderColor: 'rgba(148,163,184,.2)' },
        legend: { position: 'top', horizontalAlign: 'left' },
        theme: { mode: root.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light' },
        ...rest,
        chart: { height: 320, toolbar: { show: false }, fontFamily: 'inherit', background: 'transparent', ...(rest.chart || {}) },
      };

      if (rest.colors) config.colors = rest.colors.map(resolve);
      if (money) {
        config.yaxis = { labels: { formatter: short }, ...(rest.yaxis || {}) };
        config.tooltip = { y: { formatter: (v) => SGMS.money(v) }, ...(rest.tooltip || {}) };
      }

      el._chart?.destroy();
      el._chart = new ApexCharts(el, config);
      el._chart.render();
      return el._chart;
    },

    /** Resolve a Bootstrap theme colour (CSS variable) for charts. */
    color(name, fallback = '#0d6efd') {
      return getComputedStyle(root).getPropertyValue('--bs-' + name).trim() || fallback;
    },

    /** Short two-note chime for new online orders (no audio file needed). */
    chime() {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [[880, 0], [1320, 0.16]].forEach(([freq, at]) => {
          const osc = ctx.createOscillator();
          const gain = ctx.createGain();
          osc.frequency.value = freq;
          gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
          gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + at + 0.02);
          gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.35);
          osc.connect(gain).connect(ctx.destination);
          osc.start(ctx.currentTime + at);
          osc.stop(ctx.currentTime + at + 0.4);
        });
      } catch (e) { /* audio blocked until the user interacts with the page */ }
    },

    money(value, symbol) {
      const n = Number(value || 0);
      const s = symbol ?? (window.SGMS_CURRENCY || 'Rs.');
      return (n < 0 ? '-' : '') + s + ' ' + Math.abs(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
  };

  window.SGMS = SGMS;

  // Apply before first paint.
  const savedTheme = store.get('sgms-theme');
  if (savedTheme) root.setAttribute('data-bs-theme', savedTheme);
  SGMS.applyLayout();

  let resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => SGMS.applyLayout(), 120);
  });

  document.addEventListener('click', (e) => {
    if (e.target.closest('.sidebar-toggle')) { SGMS.toggleSidebar(); return; }
    if (e.target.closest('[data-sgms-theme-toggle]')) { SGMS.toggleTheme(); return; }

    if (e.target.closest('[data-sgms-fullscreen]')) {
      if (!document.fullscreenElement) document.documentElement.requestFullscreen?.();
      else document.exitFullscreen?.();
      return;
    }

    // Close the mobile offcanvas sidebar when a menu link is tapped.
    const link = e.target.closest('.small-screen-sidebar a.side-menu__item');
    if (link && window.bootstrap) {
      const canvas = bootstrap.Offcanvas.getInstance(document.getElementById('smallScreenSidebar'));
      canvas?.hide();
    }

    // Confirm-before-submit for plain forms: <form data-confirm="...">
    const submit = e.target.closest('form[data-confirm] [type=submit]');
    if (submit && !submit.dataset.confirmed) {
      e.preventDefault();
      const form = submit.closest('form');
      SGMS.confirm({ title: form.dataset.confirm, danger: form.dataset.danger !== 'false' }).then((ok) => {
        if (ok) { submit.dataset.confirmed = '1'; form.requestSubmit(submit); }
      });
    }
  });

  document.addEventListener('livewire:init', () => {
    Livewire.on('toast', (event) => {
      const detail = Array.isArray(event) ? event[0] : event;
      SGMS.toast(detail.type, detail.message);
    });

    Livewire.on('print', (event) => {
      const detail = Array.isArray(event) ? event[0] : event;
      SGMS.print(detail.url);
    });
  });

  // Runs on first load and after every wire:navigate visit.
  document.addEventListener('livewire:navigated', () => {
    SGMS.flash();
    document.querySelectorAll('.offcanvas-backdrop').forEach((el) => el.remove());
    document.body.style.removeProperty('overflow');
  });

  document.addEventListener('DOMContentLoaded', () => SGMS.flash());

  /* ---------------------------------------------------------------------
   | Alpine components
   * ------------------------------------------------------------------- */
  document.addEventListener('alpine:init', () => {
    /**
     * Searchable select. Static options: [{id, label, sub}] or a remote
     * Livewire method name that returns the same shape.
     */
    Alpine.data('combobox', (config) => ({
      value: null,
      open: false,
      query: '',
      results: [],
      active: 0,
      loading: false,
      selectedLabel: config.label || '',
      options: config.options || null,
      remote: config.search || null,
      placeholder: config.placeholder || 'Select…',
      nullable: config.nullable !== false,

      init() {
        if (this.options) this.results = this.options.slice(0, 100);
        this.$watch('value', (v) => this.syncLabel(v));
        this.$nextTick(() => this.syncLabel(this.value));
      },

      syncLabel(v) {
        if (v === null || v === '' || v === undefined) { this.selectedLabel = ''; return; }
        const pool = (this.options || []).concat(this.results || []);
        const found = pool.find((o) => String(o.id) === String(v));
        if (found) this.selectedLabel = found.label;
      },

      toggle() { this.open ? this.close() : this.show(); },

      show() {
        this.open = true;
        this.active = 0;
        if (this.remote && this.results.length === 0) this.fetch();
        this.$nextTick(() => this.$refs.q?.focus());
      },

      close() { this.open = false; this.query = ''; if (this.options) this.results = this.options.slice(0, 100); },

      async fetch() {
        const q = this.query.trim().toLowerCase();
        if (this.options) {
          this.results = this.options.filter((o) => (o.label + ' ' + (o.sub || '')).toLowerCase().includes(q)).slice(0, 100);
          this.active = 0;
          return;
        }
        this.loading = true;
        try {
          this.results = await this.$wire.call(this.remote, this.query.trim());
        } finally {
          this.loading = false;
          this.active = 0;
        }
      },

      move(step) {
        if (!this.results.length) return;
        this.active = (this.active + step + this.results.length) % this.results.length;
        this.$nextTick(() => this.$refs.list?.querySelector('.active')?.scrollIntoView({ block: 'nearest' }));
      },

      choose(option) {
        if (!option) return;
        this.selectedLabel = option.label;
        this.value = option.id;
        this.$dispatch('combobox-selected', option);
        this.close();
      },

      clear() { this.value = null; this.selectedLabel = ''; },
    }));
  });
})();
