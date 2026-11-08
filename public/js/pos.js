/**
 * SGMS POS terminal (Alpine component).
 *
 * The catalogue for the branch is loaded once from the Volt component and
 * searched/filtered client side, so tapping products and scanning barcodes
 * is instant. Only checkout, customer search and lookups hit the server,
 * where every price and total is validated again.
 */
document.addEventListener('alpine:init', () => {
  const round = (n) => Math.round((Number(n) || 0) * 100) / 100;

  Alpine.data('posTerminal', (config) => ({
    config,
    loading: true,
    products: [],
    variantIndex: {},
    codeIndex: {},
    colorMap: {},
    sizeMap: {},

    // Catalogue filters
    query: '',
    category: null,
    colorId: null,
    sizeId: null,
    inStockOnly: false,
    showFilters: false,
    limit: 60,

    // Cart
    cart: [],
    customer: null,
    priceMode: 'retail',
    discountValue: 0,
    discountType: 'amount',
    applyTax: false,
    notes: '',
    lastAdded: null,

    // UI state
    picker: null,
    cartOpen: false,
    checkoutOpen: false,
    heldOpen: false,
    customerOpen: false,
    customerQuery: '',
    customerResults: [],
    customerLoading: false,
    newCustomer: { open: false, name: '', phone: '', saving: false },

    // Checkout
    payments: [],
    tendered: null,
    submitting: false,
    result: null,
    held: [],

    init() {
      this.colorMap = Object.fromEntries((config.colors || []).map((c) => [c.id, c]));
      this.sizeMap = Object.fromEntries((config.sizes || []).map((s, i) => [s.id, { ...s, sort: i }]));
      this.applyTax = config.taxRate > 0;
      this.restore();
      if (config.exchange?.customer) this.customer = config.exchange.customer;
      this.held = this.readHeld();
      this.load();

      this._onKey = (e) => this.onKey(e);
      window.addEventListener('keydown', this._onKey);
    },

    destroy() {
      window.removeEventListener('keydown', this._onKey);
    },

    async load() {
      this.loading = true;
      try {
        this.products = await this.$wire.catalog();
        this.buildIndex();
        this.syncCartStock();
      } finally {
        this.loading = false;
      }
    },

    buildIndex() {
      this.variantIndex = {};
      this.codeIndex = {};
      for (const p of this.products) {
        for (const v of p.v) {
          this.variantIndex[v.id] = { v, p };
          if (v.bc) this.codeIndex[String(v.bc).toLowerCase()] = v.id;
          if (v.s) this.codeIndex[String(v.s).toLowerCase()] = v.id;
        }
      }
    },

    syncCartStock() {
      for (const line of this.cart) {
        const ref = this.variantIndex[line.id];
        line.stock = ref ? ref.v.q : 0;
      }
    },

    /* ---------------- Catalogue ---------------- */

    get filtered() {
      const q = this.query.trim().toLowerCase();
      let list = this.products;

      if (this.category) list = list.filter((p) => p.c === this.category);

      if (this.colorId || this.sizeId || this.inStockOnly) {
        list = list.filter((p) => p.v.some((v) =>
          (!this.colorId || v.co === this.colorId) &&
          (!this.sizeId || v.sz === this.sizeId) &&
          (!this.inStockOnly || v.q > 0)));
      }

      if (q) {
        list = list.filter((p) => p.n.toLowerCase().includes(q)
          || (p.sku || '').toLowerCase().includes(q)
          || p.v.some((v) => String(v.bc).toLowerCase() === q || String(v.s).toLowerCase().includes(q)));
      }

      return list;
    },

    get visible() {
      return this.filtered.slice(0, this.limit);
    },

    get activeFilterCount() {
      return [this.colorId, this.sizeId, this.inStockOnly || null].filter(Boolean).length;
    },

    stockOf(p) {
      return p.v.reduce((sum, v) => sum + Math.max(0, v.q), 0);
    },

    priceLabel(p) {
      const prices = p.v.map((v) => v.p);
      const min = Math.min(...prices);
      const max = Math.max(...prices);
      return min === max ? this.money(min) : this.money(min) + ' +';
    },

    initials(name) {
      return name.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
    },

    colorHex(id) { return this.colorMap[id]?.hex_code || null; },
    colorName(id) { return this.colorMap[id]?.name || 'Standard'; },
    sizeCode(id) { return this.sizeMap[id]?.code || this.sizeMap[id]?.name || '—'; },

    variantLabel(v) {
      return [v.co ? this.colorName(v.co) : null, v.sz ? this.sizeCode(v.sz) : null].filter(Boolean).join(' / ') || 'Standard';
    },

    money(value) {
      return SGMS.money(value, this.config.currency);
    },

    /* ---------------- Scanning & search ---------------- */

    submitSearch() {
      const q = this.query.trim().toLowerCase();
      if (!q) return;

      const id = this.codeIndex[q];
      if (id) {
        this.addVariant(id);
        this.query = '';
        return;
      }

      const list = this.filtered;
      if (list.length === 1) {
        this.openProduct(list[0]);
        return;
      }

      if (list.length === 0) this.lookupRemote(q);
    },

    async lookupRemote(code) {
      const found = await this.$wire.findByCode(code);
      if (!found) {
        SGMS.toast('warning', `No item found for "${code}"`);
        return;
      }
      if (!this.variantIndex[found.v.id]) {
        let product = this.products.find((p) => p.id === found.p.id);
        if (!product) {
          product = { ...found.p, v: [] };
          this.products.push(product);
        }
        product.v.push(found.v);
        this.buildIndex();
      }
      this.query = '';
      this.addVariant(found.v.id);
    },

    openProduct(p) {
      if (p.v.length === 1) {
        this.addVariant(p.v[0].id);
        return;
      }
      this.picker = p;
    },

    get pickerColors() {
      if (!this.picker) return [];
      return [...new Set(this.picker.v.map((v) => v.co))];
    },

    get pickerSizes() {
      if (!this.picker) return [];
      const ids = [...new Set(this.picker.v.map((v) => v.sz))];
      return ids.sort((a, b) => (this.sizeMap[a]?.sort ?? 999) - (this.sizeMap[b]?.sort ?? 999));
    },

    pickerCell(colorId, sizeId) {
      return this.picker?.v.find((v) => v.co === colorId && v.sz === sizeId) || null;
    },

    /* ---------------- Cart ---------------- */

    addVariant(id, qty = 1) {
      const ref = this.variantIndex[id];
      if (!ref) return;
      const { v, p } = ref;
      const line = this.cart.find((l) => l.id === id);
      const inCart = line ? line.qty : 0;

      if (inCart + qty > v.q) {
        SGMS.toast('warning', `Only ${Math.max(0, v.q)} in stock — ${p.n} (${this.variantLabel(v)})`);
        if (v.q <= inCart) return;
        qty = v.q - inCart;
      }

      if (line) {
        line.qty += qty;
      } else {
        this.cart.unshift({
          id, pid: p.id, name: p.n, variant: this.variantLabel(v), sku: v.s,
          retail: v.p, wholesale: v.w,
          price: this.priceMode === 'wholesale' && v.w > 0 ? v.w : v.p,
          qty, discount: 0, stock: v.q, hex: this.colorHex(v.co),
        });
      }

      this.lastAdded = id;
      setTimeout(() => { if (this.lastAdded === id) this.lastAdded = null; }, 700);
      this.persist();
    },

    setQty(line, value) {
      let qty = parseInt(value, 10) || 0;
      if (qty <= 0) { this.remove(line); return; }
      if (qty > line.stock) {
        SGMS.toast('warning', `Only ${line.stock} in stock for ${line.name}`);
        qty = line.stock;
      }
      line.qty = qty;
      this.persist();
    },

    inc(line) { this.setQty(line, line.qty + 1); },
    dec(line) { this.setQty(line, line.qty - 1); },

    remove(line) {
      this.cart = this.cart.filter((l) => l !== line);
      this.persist();
    },

    async clearCart(ask = true) {
      if (ask && this.cart.length && !(await SGMS.confirm({ title: 'Clear the cart?', confirmText: 'Clear' }))) return;
      this.cart = [];
      this.customer = this.config.exchange?.customer || null;
      this.discountValue = 0;
      this.notes = '';
      this.persist();
    },

    setPriceMode(mode) {
      this.priceMode = mode;
      for (const line of this.cart) {
        line.price = mode === 'wholesale' && line.wholesale > 0 ? line.wholesale : line.retail;
      }
      this.persist();
    },

    lineTotal(line) {
      return Math.max(0, round(line.qty * line.price - (Number(line.discount) || 0)));
    },

    get itemCount() { return this.cart.reduce((s, l) => s + l.qty, 0); },
    get subtotal() { return round(this.cart.reduce((s, l) => s + this.lineTotal(l), 0)); },

    get discountAmount() {
      const d = Math.max(0, Number(this.discountValue) || 0);
      const amount = this.discountType === 'percent' ? this.subtotal * Math.min(d, 100) / 100 : d;
      return round(Math.min(amount, this.subtotal));
    },

    get taxAmount() {
      return this.applyTax ? round((this.subtotal - this.discountAmount) * this.config.taxRate / 100) : 0;
    },

    get total() { return round(this.subtotal - this.discountAmount + this.taxAmount); },
    get exchangeCredit() { return this.config.exchange ? Math.min(this.config.exchange.credit, this.total) : 0; },
    get payable() { return round(this.total - this.exchangeCredit); },

    /* ---------------- Customer ---------------- */

    async searchCustomers() {
      this.customerLoading = true;
      try {
        this.customerResults = await this.$wire.searchCustomers(this.customerQuery);
      } finally {
        this.customerLoading = false;
      }
    },

    openCustomerSearch() {
      if (this.config.exchange?.customer) return;
      this.customerOpen = true;
      this.customerQuery = '';
      this.searchCustomers();
      this.$nextTick(() => this.$refs.customerSearch?.focus());
    },

    chooseCustomer(c) {
      this.customer = c;
      this.customerOpen = false;
      this.persist();
    },

    walkIn() {
      if (this.config.exchange?.customer) return;
      this.customer = null;
      this.persist();
    },

    async saveNewCustomer() {
      this.newCustomer.saving = true;
      try {
        const res = await this.$wire.quickCustomer(this.newCustomer.name, this.newCustomer.phone);
        if (!res.ok) { SGMS.toast('error', res.message); return; }
        this.chooseCustomer(res.customer);
        this.newCustomer = { open: false, name: '', phone: '', saving: false };
        SGMS.toast('success', 'Customer added');
      } finally {
        this.newCustomer.saving = false;
      }
    },

    /* ---------------- Checkout ---------------- */

    account(id) { return this.config.accounts.find((a) => a.id === Number(id)); },
    isCash(id) { return this.account(id)?.type === 'cash_drawer'; },

    openCheckout() {
      if (!this.cart.length) { SGMS.toast('info', 'Add items to the cart first'); return; }
      const cash = this.config.cashAccountId || this.config.accounts[0]?.id;
      this.payments = this.payable > 0 ? [{ account: cash, amount: this.payable, ref: '' }] : [];
      this.tendered = null;
      this.result = null;
      this.cartOpen = false;
      this.checkoutOpen = true;
    },

    get paidTotal() { return round(this.payments.reduce((s, p) => s + (Number(p.amount) || 0), 0)); },
    get remaining() { return round(this.payable - this.paidTotal); },
    get cashPayment() { return this.payments.find((p) => this.isCash(p.account)); },

    get change() {
      const cash = this.cashPayment;
      if (!cash || !this.tendered) return 0;
      return Math.max(0, round(Number(this.tendered) - (Number(cash.amount) || 0)));
    },

    get quickCashOptions() {
      const due = this.cashPayment ? Number(this.cashPayment.amount) || 0 : this.payable;
      const opts = new Set([due]);
      for (const note of [500, 1000, 5000]) opts.add(Math.ceil(due / note) * note);
      return [...opts].filter((v) => v > 0).sort((a, b) => a - b).slice(0, 4);
    },

    setTendered(amount) {
      this.tendered = amount;
      if (!this.cashPayment && this.config.cashAccountId) {
        this.payments.unshift({ account: this.config.cashAccountId, amount: Math.min(amount, Math.max(0, this.remaining)), ref: '' });
      }
    },

    payFullWith(accountId) {
      this.payments = [{ account: accountId, amount: this.payable, ref: '' }];
      this.tendered = null;
    },

    addPayment() {
      const used = this.payments.map((p) => Number(p.account));
      const next = this.config.accounts.find((a) => !used.includes(a.id)) || this.config.accounts[0];
      this.payments.push({ account: next?.id, amount: Math.max(0, this.remaining), ref: '' });
    },

    removePayment(index) {
      this.payments.splice(index, 1);
    },

    putOnAccount() {
      if (!this.customer) { SGMS.toast('warning', 'Select a registered customer to sell on credit'); return; }
      this.payments = [];
      this.tendered = null;
    },

    get checkoutError() {
      if (this.remaining < -0.009) return 'Payments exceed the bill. Enter the net amount; change is calculated from cash tendered.';
      if (this.remaining > 0.009 && !this.customer) return 'Walk-in customers must pay in full.';
      if (this.payments.some((p) => !p.account)) return 'Choose an account for every payment.';
      return null;
    },

    async submit() {
      if (this.submitting || this.checkoutError) return;
      this.submitting = true;

      const payload = {
        items: this.cart.map((l) => ({ variant_id: l.id, quantity: l.qty, unit_price: round(l.price), discount: round(l.discount) })),
        customer_id: this.customer?.id || null,
        discount: this.discountAmount,
        apply_tax: this.applyTax,
        payments: this.payments.filter((p) => Number(p.amount) > 0).map((p) => ({ payment_account_id: Number(p.account), amount: round(p.amount), reference_no: p.ref || null })),
        notes: this.notes || null,
        tendered: Number(this.tendered) || 0,
        change: this.change,
      };

      try {
        const res = await this.$wire.checkout(payload);
        if (!res.ok) { SGMS.toast('error', res.message); return; }

        for (const [id, qty] of Object.entries(res.stock)) {
          const ref = this.variantIndex[id];
          if (ref) ref.v.q -= qty;
        }

        this.result = { ...res, change: this.change };
        this.config.exchange = null;
        this.cart = [];
        this.customer = null;
        this.discountValue = 0;
        this.notes = '';
        this.persist();

        if (this.config.autoPrint) SGMS.print(res.receipt_url);
      } catch (e) {
        SGMS.toast('error', 'Could not complete the sale. Please try again.');
      } finally {
        this.submitting = false;
      }
    },

    newSale() {
      this.checkoutOpen = false;
      this.result = null;
      this.$nextTick(() => this.$refs.search?.focus());
    },

    /* ---------------- Hold / recall & persistence ---------------- */

    persist() {
      SGMS.store.set(this.config.storageKey, JSON.stringify({
        cart: this.cart, customer: this.customer, discountValue: this.discountValue,
        discountType: this.discountType, priceMode: this.priceMode,
      }));
    },

    restore() {
      try {
        const saved = JSON.parse(SGMS.store.get(this.config.storageKey) || 'null');
        if (!saved) return;
        this.cart = saved.cart || [];
        this.customer = saved.customer || null;
        this.discountValue = saved.discountValue || 0;
        this.discountType = saved.discountType || 'amount';
        this.priceMode = saved.priceMode || 'retail';
      } catch (e) { /* ignore corrupt storage */ }
    },

    readHeld() {
      try { return JSON.parse(SGMS.store.get(this.config.storageKey + '-held') || '[]'); } catch (e) { return []; }
    },

    writeHeld() {
      SGMS.store.set(this.config.storageKey + '-held', JSON.stringify(this.held.slice(0, 15)));
    },

    hold() {
      if (!this.cart.length) return;
      this.held.unshift({
        at: Date.now(),
        label: this.customer?.label || 'Walk-in',
        total: this.total,
        items: this.itemCount,
        cart: JSON.parse(JSON.stringify(this.cart)),
        customer: this.customer,
        discountValue: this.discountValue,
        discountType: this.discountType,
      });
      this.writeHeld();
      this.clearCart(false);
      SGMS.toast('info', 'Cart held — recall it from "Held"');
    },

    async recall(index) {
      if (this.cart.length && !(await SGMS.confirm({ title: 'Replace the current cart?', text: 'The current cart will be held first.', danger: false }))) return;
      if (this.cart.length) this.hold();
      const h = this.held.splice(index, 1)[0];
      this.cart = h.cart;
      this.customer = h.customer;
      this.discountValue = h.discountValue;
      this.discountType = h.discountType;
      this.syncCartStock();
      this.writeHeld();
      this.persist();
      this.heldOpen = false;
    },

    dropHeld(index) {
      this.held.splice(index, 1);
      this.writeHeld();
    },

    timeAgo(ts) {
      const m = Math.round((Date.now() - ts) / 60000);
      return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : `${Math.round(m / 60)} h ago`;
    },

    /* ---------------- Keyboard ---------------- */

    onKey(e) {
      if (e.key === 'F2') { e.preventDefault(); this.$refs.search?.focus(); return; }
      if (e.key === 'F9') { e.preventDefault(); this.checkoutOpen ? this.submit() : this.openCheckout(); return; }
      if (e.key === 'Escape') { this.picker = null; this.customerOpen = false; this.heldOpen = false; if (!this.submitting && !this.result) this.checkoutOpen = false; return; }

      // Barcode scanners "type" into the page: route stray keystrokes to the search box.
      const t = e.target;
      const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
      if (!typing && !this.checkoutOpen && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        this.$refs.search?.focus();
      }
    },
  }));
});
