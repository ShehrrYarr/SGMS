/*
 * SGMS online store — shared behaviour for every theme (Alpine.js, loaded by Livewire).
 * Forms work without JavaScript; these handlers upgrade them to in-page updates.
 */
(() => {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** "Rs. 3,500" (decimals only when there are paisa). */
    window.SF = window.SF || {};
    window.SF.money = (value) => {
        const v = Number(value) || 0;
        return (window.SF.currency || 'Rs.') + ' ' + v.toLocaleString('en-PK', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 });
    };

    async function send(url, { method = 'POST', body = null } = {}) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
        const response = await fetch(url, { method, body, headers, credentials: 'same-origin' });
        let data = {};
        try { data = await response.json(); } catch (e) { /* non-JSON error page */ }
        if (!response.ok) {
            const message = data.message || (data.errors ? Object.values(data.errors)[0][0] : 'Something went wrong. Please try again.');
            throw Object.assign(new Error(message), { data });
        }
        return data;
    }

    document.addEventListener('alpine:init', () => {
        const Alpine = window.Alpine;

        Alpine.store('shop', {
            cartCount: window.SF?.cartCount ?? 0,
            wishCount: window.SF?.wishCount ?? 0,
            drawer: false,
            menu: false,
            toasts: [],
            busy: false,

            toast(text, error = false) {
                if (!text) return;
                const id = Date.now() + Math.random();
                this.toasts.push({ id, text, error });
                setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, error ? 6000 : 3500);
            },
            close() { this.drawer = false; this.menu = false; document.body.classList.remove('sf-locked'); },
            openMenu() { this.menu = true; document.body.classList.add('sf-locked'); },
            async openBag() {
                this.drawer = true;
                document.body.classList.add('sf-locked');
                try { this.apply(await send(window.SF.drawerUrl, { method: 'GET' })); } catch (e) { this.toast(e.message, true); }
            },
            apply(data) {
                if (typeof data.count === 'number') this.cartCount = data.count;
                if (data.html !== undefined) {
                    const body = document.getElementById('sf-drawer-body');
                    if (body) body.innerHTML = data.html;
                }
            },
            /** Submit any bag form (add, quantity, remove, coupon) without leaving the page. */
            async submit(form, { open = false } = {}) {
                if (this.busy) return;
                this.busy = true;
                form.classList.add('is-busy');
                try {
                    const data = await send(form.action, { body: new FormData(form) });
                    this.apply(data);
                    if (open || data.open) { this.drawer = true; document.body.classList.add('sf-locked'); }
                    else if (data.message) this.toast(data.message);
                } catch (e) {
                    if (e.data?.html !== undefined) this.apply(e.data);
                    this.toast(e.message, true);
                } finally {
                    this.busy = false;
                    form.classList.remove('is-busy');
                }
            },
        });

        // Delegate submits from the bag drawer (its HTML is replaced after each change).
        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form[data-bag]');
            if (!form) return;
            event.preventDefault();
            Alpine.store('shop').submit(form, { open: form.dataset.bag === 'open' });
        });
        document.addEventListener('change', (event) => {
            const input = event.target.closest('form[data-bag] [data-autosubmit]');
            if (input) input.form.requestSubmit();
        });

        /** Wishlist heart. */
        Alpine.data('wish', (saved = false) => ({
            saved,
            async toggle(url) {
                const before = this.saved;
                this.saved = !before;
                try {
                    const data = await send(url);
                    this.saved = data.saved;
                    Alpine.store('shop').wishCount = data.count;
                    Alpine.store('shop').toast(data.message);
                } catch (e) {
                    this.saved = before;
                    Alpine.store('shop').toast(e.message, true);
                }
            },
        }));

        /**
         * Product page: colour + size selection, live price/stock, colour photo swap.
         * config = { variants: [{id, color, size, price, available}], colors: [{id, name, hex, image}], sizes: [...], images: {colorId: url}, base }
         */
        Alpine.data('productPage', (config) => ({
            ...config,
            color: config.colors.length === 1 ? config.colors[0].id : (config.initialColor ?? null),
            size: config.sizes.length === 1 ? config.sizes[0].id : null,
            qty: 1,
            missing: false,
            slide: 0,

            init() {
                if (this.color && this.colors.length > 1 && this.colorStock(this.color) <= 0) this.color = null;
                if (!this.color && this.colors.length) {
                    const first = this.colors.find((c) => this.colorStock(c.id) > 0) ?? this.colors[0];
                    this.color = first.id;
                }
            },
            get variant() {
                return this.variants.find((v) => (v.color ?? null) === (this.colors.length ? this.color : null)
                    && (v.size ?? null) === (this.sizes.length ? this.size : null)) ?? null;
            },
            get colorName() { return this.colors.find((c) => c.id === this.color)?.name ?? ''; },
            get price() { return this.variant ? this.variant.price : this.minPriceFor(this.color); },
            get available() { return this.variant ? this.variant.available : 0; },
            get ready() { return !!this.variant && this.variant.available > 0; },
            get photo() { return this.images[this.color] ?? this.base; },
            get gallery() { return [...new Set([this.photo, this.base].filter(Boolean))]; },

            minPriceFor(colorId) {
                const prices = this.variants.filter((v) => !colorId || v.color === colorId).map((v) => v.price);
                return prices.length ? Math.min(...prices) : 0;
            },
            colorStock(colorId) {
                return this.variants.filter((v) => v.color === colorId).reduce((sum, v) => sum + Math.max(0, v.available), 0);
            },
            sizeStock(sizeId) {
                const v = this.variants.find((x) => x.size === sizeId && (!this.colors.length || x.color === this.color));
                return v ? v.available : -1; // -1: combination does not exist
            },
            pickColor(id) {
                this.color = id;
                if (this.size && this.sizeStock(this.size) <= 0) this.size = null;
                this.slide = 0;
                this.$nextTick(() => this.$refs.gallery?.scrollTo({ left: 0, behavior: reduceMotion ? 'auto' : 'smooth' }));
            },
            pickSize(id) {
                if (this.sizeStock(id) <= 0) return;
                this.size = id;
                this.missing = false;
                if (this.qty > this.available) this.qty = Math.max(1, this.available);
            },
            step(delta) {
                const max = Math.max(1, Math.min(this.available || 1, 20));
                this.qty = Math.min(max, Math.max(1, this.qty + delta));
            },
            async add(form) {
                if (!this.variant) {
                    this.missing = true;
                    this.$refs.sizes?.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
                    return;
                }
                if (!this.ready) return;
                await Alpine.store('shop').submit(form, { open: true });
            },
            onScroll(el) {
                this.slide = Math.round(el.scrollLeft / el.clientWidth);
            },
            go(index) {
                const el = this.$refs.gallery;
                if (el) el.scrollTo({ left: index * el.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
            },
        }));

        /** Simple slider for hero banners (scroll-snap based, swipe friendly). */
        Alpine.data('slider', (count = 1, interval = 6000) => ({
            index: 0,
            count,
            timer: null,
            init() {
                if (count > 1 && !reduceMotion) this.play();
            },
            play() { this.stop(); this.timer = setInterval(() => this.go((this.index + 1) % this.count), interval); },
            stop() { clearInterval(this.timer); },
            go(i) {
                const el = this.$refs.track;
                if (!el) return;
                this.index = i;
                el.scrollTo({ left: i * el.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
            },
            sync(el) { this.index = Math.round(el.scrollLeft / el.clientWidth); },
        }));
    });
})();
