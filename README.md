# SGMS — Garments & Apparel Inventory SaaS

Multi-tenant, branch-aware inventory, lot purchasing, POS and an online store for garment shops.
Laravel 12 · Livewire 3 Volt (class components) · Alpine.js · Herozi (Bootstrap 5) admin theme · MySQL (InnoDB).

## Quick start (WAMP / local)

```bash
composer install
cp .env.example .env            # DB_DATABASE=sgms, root / no password (WAMP default)
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

| Area | URL | Demo login (password `password`) |
|---|---|---|
| Landing page | `/` | one-click demo tags when `SGMS_DEMO=true` |
| Super admin | `/admin` | `admin@platform.com` |
| Shop staff | `/app` | `owner@urbangarments.com`, `manager@urbangarments.com` (City Mall), `cashier@urbangarments.com` |
| POS | `/app/pos` | any staff user |
| Online store | `/urban-garments` | no login needed; coupon `WELCOME10` |
| Customer account | `/urban-garments/account` | phone `03001234567` |

`Denim Corner` is seeded as a **suspended** shop to demonstrate the platform controls.

Tests run against a separate MySQL database `sgms_testing` (see `phpunit.xml`):

```bash
php artisan test
```

## Architecture

**Multi-tenancy (single database).** Every shop-owned table has `tenant_id`. The
`BelongsToTenant` trait adds a global `TenantScope` and fills `tenant_id` on create.
`TenantContext` (request-scoped singleton) holds the active shop and branch.

| Middleware | Area | Purpose |
|---|---|---|
| `ResetTenantContext` | web group | start each request with no tenant |
| `IdentifyTenant` (`tenant`) | `/app` | shop from the signed-in user; blocks suspended shops / inactive users; picks the branch |
| `ResolvePortalTenant` (`portal.tenant`) | `/{shop}`, `/{shop}/account` | shop from the URL slug, *before* auth so the customer guard is shop-scoped |
| `EnsureStoreOpen` (`store.open`) | `/{shop}` storefront | store blocked → 404, not opened → "coming soon" (staff can preview) |
| `EnsureRole` (`role:owner,manager`) | `/app` | role gates |

These middleware are in the router priority list (they run before route-model
binding) and are registered as **Livewire persistent middleware**, so every
component update request is tenant-scoped too. Lookup caches are keyed per tenant
(`tenant:{id}:catalog:*`).

**Guards:** `superadmin` (`/admin`), `web` (staff, `/app`), `customer` (`/{shop}/account`).

**Branches.** Owners can switch between branches or view "All branches"; managers
and cashiers are locked to their branch. Each branch has its own stock
(`inventories`) and a default cash drawer created automatically.

**Money & stock are posted only through services** (`app/Services`), each
running in a DB transaction with row locks:

- `AccountService` — cash drawer / bank / wallet balances + `transactions` journal (credit = in, debit = out)
- `InventoryService` — stock per branch, oversell protection, `stock_movements` audit trail
- `LedgerService` — vendor (payable) and customer (receivable) running-balance ledgers
- `PurchaseService` — lot intake, weighted-average cost, payment split, FIFO vendor payments
- `SaleService` — POS checkout (server re-prices and re-totals), credit sales, FIFO customer payments
- `SaleReturnService` — returns, refunds, account credit and exchanges
- `StockTransferService`, `ExpenseService`, `TenantProvisioner`, `BarcodeService`, `DocumentNumber`

### Ledger conventions

- **Vendor ledger:** every lot posts its full cost as a credit (`purchase`), and each payment posts as a debit (`payment`). `vendors.current_balance` is the amount owed.
- **Customer ledger:** each invoice is a debit (`sale`); payments and returns are credits. A negative balance means the customer has an advance.

## Modules

- **Super admin:** platform dashboard; create a shop (owner, main branch, cash drawer and starter attributes in one step); instant suspend/activate; reset any shop user's password (also signs them out).
- **Lot intake (4 steps):** searchable vendor with inline "Add vendor"; lot details; colour × size rows with a *size-run* generator and inline product creation. Item costs auto-average from the lot total, and any row can be overridden. Payment modes: cash, bank, split, full credit, partial credit.
- **Vendors:** running ledger, FIFO payments, adjustments, ledger PDF and payment-voucher PDFs.
- **POS:** catalogue loaded once and searched client-side (instant), barcode scanner support, colour × size matrix picker, retail/wholesale pricing, line and invoice discounts, tax, hold/recall carts, split payments, credit to customer account, cash tendered/change, exchanges, auto-print 80/58 mm thermal receipts. On phones the cart and checkout open as bottom sheets.
- **Sales, returns & exchanges, customers** (portal access, receive payments, statements).
- **Inventory:** stock per branch, low/out-of-stock filters, physical count adjustments, movement history, branch transfers, barcode labels (thermal 38×25 / 50×30 mm, A4 sheets).
- **Finance:** payment accounts, fund transfers, reconciliation, expenses.
- **Customer account** (`/{shop}/account`): sign-up, online orders, purchase history with item detail, live statement, PDF download, printable receipts.
- **Reports** (owner/manager, `/app/reports`), filterable by period (presets or custom) and branch, with print and CSV export:
  - *Profit & loss:* gross sales → discounts → net sales (excluding tax) → returns → COGS → gross profit → expenses by category → inventory write-offs → net profit. Shows the previous period alongside, plus a 12-month trend.
  - *Sales analysis:* net sales, cost, profit and margin grouped by category, product, brand, size, colour, staff, branch, customer or day, plus collections per payment account.
  - *Stock valuation:* current value at cost and retail (by category or branch), value over time, stock flow (opening → received/sold/returned/transferred/adjusted → closing) and aged stock with no sales for 30–180 days.

## Online store

Every shop has its own store at `/{shop-slug}` (e.g. `/urban-garments`), selling
from the same products, prices and stock as the POS. Words used by the app
(`admin`, `app`, `portal`, `livewire`, …) can never be shop slugs. The customer
account lives at `/{shop}/account`; old `/portal/{shop}` links redirect there.

**Shopper side.** Home page, listings with filters (category, size, colour,
price, in stock, on sale) and sorting kept in the URL, product page with colour
photo swap, size buttons that grey out sold-out sizes, size chart, stock
messages and reviews, a slide-out bag, one-page checkout (guest or signed in,
coupon codes, delivery charge with a free-delivery threshold), order
confirmation and tracking by order number + phone, wishlist, and customer
sign-up. Pages are plain Blade (server-rendered, SEO meta, Open Graph, Product
JSON-LD); the bag and product options are upgraded with Alpine. Checkout is a
Livewire component.

**How orders post.**

| Order status | What happens |
|---|---|
| New (`pending`) | Items are reserved: online stock = all branches − pending orders. Nothing is posted yet. |
| Confirmed | Staff pick the shipping branch. A normal sale invoice is created there (`channel = online`): stock out, customer ledger, delivery charge on the invoice. Prepaid orders are paid into the account the customer chose. |
| Shipped | Courier and tracking number shown to the customer. |
| Delivered | Cash on delivery can be collected into a drawer/bank; otherwise it stays due on the customer's account. |
| Cancelled / returned by courier | Before confirmation nothing is posted. After it, the goods go back to stock, the invoice is cleared, the delivery charge is waived, and paid money is refunded. |

Delivery charges count as revenue in Profit & Loss; Sales Analysis can group by
channel (in-store vs online).

**Back office** (owner/manager): *Online Orders* (with a bell and sidebar badge
for new orders, per-branch stock on each order, WhatsApp message shortcuts,
payment receipt check), *Coupons*, *Reviews* (approve before publishing).
Product form: show/hide online, featured, compare-at ("was") price, one photo
per colour, size chart image. Owner settings:

- *Settings › Online store*: open/close the store, delivery charge and free
  delivery threshold, cash on delivery, which bank accounts and JazzCash /
  Easypaisa wallets appear at checkout.
- *Settings › Appearance*: theme, brand colour, logo, favicon, tagline,
  announcement bar, social links, WhatsApp chat button, home page banners and
  featured categories (with photos). Each theme can be previewed before
  switching (`?preview_theme=key` while signed in as staff).

The platform admin can disable any shop's store from *Shops › shop*.

**Themes** (`app/Support/Store/Themes.php`, views in
`resources/views/store/themes/{key}`, styles in `public/store/themes/{key}.css`):

| Theme | For | Character |
|---|---|---|
| Linen | Everyday wear | Calm grotesk type, split hero, category tiles |
| Atelier | Premium & boutique | Bodoni + Jost, full-bleed editorial hero, square edges |
| Noor | Eastern wear | Marcellus, emerald and gold, arched frames |
| Kinetic | Streetwear & western | Anton poster type, scrolling ticker, hard edges |
| Playground | Kids wear | Fredoka, wavy edges, chunky buttons |

Shared pages (listing, product, bag, checkout, order) live in
`resources/views/store/pages` and read each theme's CSS tokens. Design guidance
used: `.claude/skills/frontend-design` (Anthropic) and
`.claude/skills/storefront-best-practices` (Medusa).

The demo seed opens the Urban Garments store with eight products (a generated
illustration per colour, size charts), three banners, coupons `WELCOME10` and
`EID500`, and five online orders at different stages.

## Scheduler

Stock valuation history comes from nightly snapshots (`sgms:stock-snapshot`, 23:55),
which record the cost that applied on each day. Register the Laravel scheduler once:

```bash
# Linux cron
* * * * * cd /path/to/sgms && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, add a Task Scheduler job that runs `php artisan schedule:run` every minute.
Missing history can be rebuilt from stock movements (valued at today's cost):

```bash
php artisan sgms:stock-snapshot --days=90
```

## Landing page and public demo

`/` is the product page (`resources/views/landing.blade.php`, styles in
`public/landing/landing.css`, screenshots in `public/landing/shots`). Plans and
the WhatsApp number live in `config/sgms.php`.

With `SGMS_DEMO=true` the page shows four hang tags (owner, manager, cashier,
customer). Each one is a `POST /demo/{role}` that signs the visitor in to the
Urban Garments demo shop; the super admin is never offered. For those accounts
the app refuses changes that would lock the next visitor out (password, sign-in
email, editing demo staff, closing the store), shows a demo notice, and
`sgms:demo-reset` rebuilds the whole database and uploads every night at
`SGMS_DEMO_RESET_AT` (03:00). The command refuses to run when `SGMS_DEMO` is
off. **Never turn the demo on for an install that holds real shop data.**

To refresh the screenshots, sign in through the demo tags and capture the
pages at 1440 × 900 (1.5× scale, WebP); file names are listed in the view.

## Production (sub-folder install)

The live demo runs at `http://23.230.253.206/sgms` (Apache `Alias /sgms` →
`/var/www/SGMS/public`, config in `/etc/apache2/conf-available/sgms.conf`).
`.env` sets `APP_URL=http://23.230.253.206/sgms`, `ASSET_URL=/sgms` and
`SESSION_PATH=/sgms`. Livewire builds its script and update URLs from the domain
root, so `App\Support\Livewire\SubdirectoryHandleRequests` puts the folder back;
at the domain root it changes nothing.

**Do not run `route:cache` or `optimize` there.** With cached routes Laravel
trims the slash from `/sgms/` before matching, loses the folder, and the store
route `/{shop}` answers with a 404. Cache config, events and views instead.

To update after a push (as root on the server):

```bash
cd /var/www/SGMS && git pull && composer install --no-dev --optimize-autoloader \
  && runuser -u www-data -- php artisan migrate --force \
  && runuser -u www-data -- php artisan config:cache \
  && runuser -u www-data -- php artisan event:cache \
  && runuser -u www-data -- php artisan view:cache
```

## Frontend

The Herozi template's compiled assets in `public/assets` are used as-is. App-specific
styles and scripts live in `public/css/sgms.css`, `public/js/sgms.js` (layout,
toasts, combobox) and `public/js/pos.js` (POS terminal). They sit outside
`public/assets`, so a template rebuild never overwrites them. Navigation uses
`wire:navigate` (SPA-style).

The original Herozi demo pages were moved to `resources/views/herozi-demo/` for
reference; they are not routed.
