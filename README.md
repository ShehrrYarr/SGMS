# SGMS — Garments & Apparel Inventory SaaS

Multi-tenant, branch-aware inventory, lot purchasing and POS for garment shops.
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
| Super admin | `/admin` | `admin@platform.com` |
| Shop staff | `/app` | `owner@urbangarments.com`, `manager@urbangarments.com` (City Mall), `cashier@urbangarments.com` |
| POS | `/app/pos` | any staff user |
| Customer portal | `/portal/urban-garments` | phone `03001234567` |

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
| `ResolvePortalTenant` (`portal.tenant`) | `/portal/{shop}` | shop from the URL slug, *before* auth so the customer guard is shop-scoped |
| `EnsureRole` (`role:owner,manager`) | `/app` | role gates |

These middleware are in the router priority list (they run before route-model
binding) and are registered as **Livewire persistent middleware**, so every
component update request is tenant-scoped too. Lookup caches are keyed per tenant
(`tenant:{id}:catalog:*`).

**Guards:** `superadmin` (`/admin`), `web` (staff, `/app`), `customer` (`/portal/{shop}`).

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
- **Customer portal:** purchase history with item detail, live statement, PDF download, printable receipts.

## Frontend

The Herozi template's compiled assets in `public/assets` are used as-is. App-specific
styles and scripts live in `public/css/sgms.css`, `public/js/sgms.js` (layout,
toasts, combobox) and `public/js/pos.js` (POS terminal). They sit outside
`public/assets`, so a template rebuild never overwrites them. Navigation uses
`wire:navigate` (SPA-style).

The original Herozi demo pages were moved to `resources/views/herozi-demo/` for
reference; they are not routed.
