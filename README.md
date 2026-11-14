# Brixel Enterprise ERP

Multi-tenant, multi-company, multi-branch ERP with an integrated POS, built as a modular monolith.
Specification: `Brixel_ERP_Complete_SRS_and_Implementation_Plan_v1` (project docs).

| Layer | Technology |
|---|---|
| Backend | Laravel 13, PHP 8.5 |
| Frontend | React 18, TypeScript, DevExtreme (DevExpress) |
| Database | PostgreSQL 18 |
| Cache / queue / realtime | Redis 8, database queue, Laravel Reverb |
| Runtime | Docker Compose |

## Quick start

```bash
git clone https://github.com/almani007/erp.git
cd erp
./setup.sh
```

`setup.sh` creates `.env`, builds and starts the containers, installs Composer and npm dependencies,
builds the frontend, runs the migrations and seeds a demo tenant. It is safe to run again.

| What | Where |
|---|---|
| Web application | http://localhost:8090 |
| API | http://localhost:8090/api/v1 |
| PostgreSQL | localhost:35432 (`erp_app` / `erp_app_password`) |
| Redis | localhost:36379 |

## One system for different businesses

Retail and wholesale work side by side everywhere. Under Administration → Business & branches
you pick the closest business type and then switch individual features on or off. The switches
only decide which fields, tabs and menu items are shown; they never change behaviour or data.

| Feature switch | What it gives you | Typical trades |
|---|---|---|
| Wholesale | Wholesale price from a minimum quantity; wholesale customers; wholesale per sale | Wholesale, distribution, grocery, hardware |
| Packs | Sell by carton, dozen or set as well as singly, each with its own price and barcode | Grocery, bartan, pharmacy, hardware |
| Variants | A style with size / colour variants, each its own SKU, barcode and stock | Garments, footwear, cosmetics |
| Market rates | Price = weight × today's rate + wastage % + making charge; rate changes are audited | Gold, silver, bartan and steel by weight |
| Batch & expiry | Batches with expiry dates; sold first-expiry-first-out; expired stock cannot be sold | Pharmacy, food, cosmetics |
| Serials & warranty | One serial / IMEI per unit sold, warranty period, lookup screen | Mobile phones, electronics, tools |
| Custom fields | Your own extra fields on products, customers and suppliers | Anything not covered above |

Always available: services and non-stock items, loose items by weight (decimal quantities),
branch-wise prices, deals and coupons, loyalty points, customer credit, purchasing, stock
counts, accounting.

### Shaping the system per business: templates, custom records and plans

Three settings layers let one installation serve businesses the built-in screens were not
written for — no code change, no new tables:

1. **Custom records** (Administration → Custom records). A business defines its own record
   kinds — job cards, appointments, table reservations, rental agreements, production batches,
   prescriptions, tailoring or gold orders — each with its own fields (text, notes, number,
   amount, date, date-time, yes/no, list of choices), required flags, number prefix, status
   list and optional link to a customer. Each appears in the menu under **Records** with a
   searchable, exportable list and a form. Values are checked against the definition on the
   server; changing a definition never loses saved values.
2. **Business templates.** A template bundles a business type, feature switches, extra fields
   on products / customers / suppliers, and record types. Starter templates ship for general,
   grocery, pharmacy, garments, jewellery, bartan, electronics, hardware, cosmetics, wholesale,
   restaurant, services (salon / clinic / workshop), manufacturing and rental
   (`config/business_templates.php`, seeded once). A business applies one itself ("Start from a
   template"), or the platform operator applies it. Applying only adds what is missing.
3. **SaaS admin** (sign in as the platform administrator). Lists every business with users,
   branches and 30-day sales; creates a new business in one step (company, branch, warehouse,
   POS terminal, owner login, chart of accounts with posting accounts, periods, units, template);
   sets each plan — which modules (sales, catalog, inventory, purchasing, accounting, reporting,
   custom records) and features are included, maximum users and branches; suspends and
   reactivates (suspension signs everyone out). Edits the templates themselves. Plans are
   enforced by the API (`module:` middleware, user / branch limits), not only by the menu.

**What this does and does not cover.** Custom records capture data and track status; they do
not run process logic. So a restaurant can take reservations and catering orders and sell
through the POS, but there is no table plan, modifiers or kitchen display; a workshop can log
production batches, but materials are not consumed and finished goods are not costed
automatically; a rental business can track agreements, but rent is not invoiced on a schedule.
HR and payroll calculation is not built. Also still missing within the retail trades: a
size-colour stock matrix view, route / van sales, and stock-level serial tracking.

Demo logins (created only when `APP_ENV` is not `production`; change them after first login):

| User | Password | Role |
|---|---|---|
| admin@brixelltd.com | `BrixelAdmin2026!` | tenant owner, all branches |
| cashier@brixelltd.com | `BrixelCashier2026!` | cashier, Head Office only |
| platform@brixelltd.com | `BrixelPlatform2026!` | SaaS platform administrator (set `ERP_PLATFORM_ADMIN_PASSWORD` in production) |

The demo tenant has one example per trade (a kurta in six size/colour variants, a 22K gold ring priced
from the gold rate, steel bartan by weight, cola by the carton, sugar with a wholesale price, a medicine
with batch and expiry, a phone with serial and warranty), two custom fields, a running
10% beverage deal, the coupon `WELCOME5`, loyalty at 1 point per 100 spent, and two wholesale customers.
The gold and steel rates are placeholders — enter the real rate of the day before selling.

## Current status

This repository holds the first vertical slice of the SRS: the platform foundation and the
sale-to-ledger flow (roughly SRS phases 1–3 plus the general ledger from phase 4).

**This code has not been executed yet.** It was written without access to a PHP 8.5 / Composer /
npm environment, so it has been syntax-checked only (`php -l`, TypeScript parser). Expect to fix
some issues on the first `./setup.sh` run, and run the test suite before relying on it.

| Module | State | What works |
|---|---|---|
| Shared | wired | Decimal maths, tenant scoping trait, contracts (audit, outbox, document numbers, settings) |
| Organization | wired | Tenants, companies, branches, warehouses, settings, business profile and feature switches, custom fields, gap-free document numbers |
| Identity | wired | Token login, users, seeded roles and permissions, branch grants, append-only audit log |
| Integration | wired | `Idempotency-Key` middleware, transactional outbox table (events are recorded, not yet published) |
| Catalog | wired | Products, barcodes, categories, brands, units, tax codes, branch-wise prices, deals (percent / amount off, by product, category or branch, time-bound), coupons, wholesale prices, packs (carton, dozen), size / colour variants, market-rate pricing (gold, silver, steel), server-side price and tax quote |
| Inventory | wired | Append-only stock ledger, weighted-average cost, adjustments with journal, warehouse transfers, physical stock counts, batch / expiry lots (FEFO) |
| Finance | wired | Chart of accounts, posting-account mapping, periods, immutable double-entry journals, reversal, expense and income vouchers, trial balance, P&L, balance sheet, general ledger |
| Sales | wired | Customers, credit limits and account payments, terminals, shifts with cash reconciliation and cash in/out, POS sale with deals and coupons, retail / wholesale tier, selling in packs, loyalty points (earn and redeem), hold/resume, void, partial returns and refunds, price checker |
| Purchasing | wired | Suppliers, purchase orders with maker-checker approval, full or partial goods receipts into stock, supplier payments, reorder suggestions that raise a draft order |
| Reporting | wired | Dashboard; drag-and-drop pivot analysis of sales by branch, cashier, category, product, customer, tier, day and hour |
| Records | wired | Tenant-defined record types (fields, numbering, statuses, customer link) and a generic list / form for each |
| SaaS | wired | Platform admin: provision businesses, plans (modules, features, user and branch limits), suspend / reactivate, business templates |
| Production, CRM | partial, **not wired** | Unreviewed scaffolding from an earlier pass; no routes, not registered in `bootstrap/providers.php` |
| People, Payroll, Order management, Delivery, Workflow, billing for SaaS plans, offline POS sync | not started | — |

Known gaps inside the wired modules: supplier-invoice matching (the liability is booked at
receipt, at order prices), supplier advances and purchase returns, buy-X-get-Y and stacked
deals, partial transfer receipts, inter-company
transfers, journals for cash paid-in/paid-out, per-tenant custom roles, MFA, webhook delivery,
and server-side paging (lists are capped, typically at 500–1000 rows). Held POS sales are kept
in the browser of the terminal, not on the server.

Benchmark features still to build (from the SRS list of Technosys public capabilities):
salesperson commissions, quotations and sales orders, delivery and riders,
restaurant tables / kitchen display / reservations, online store, feedback (Feedo-style),
tasks and approval workflows (CAN-DO-style), HR and payroll, production and recipes,
FBR e-invoicing, offline POS sync.

## Architecture

```
src/<Module>/
  Domain/          enums, value objects, exceptions, contracts — no framework code
  Application/     use cases (one class per business action)
  Infrastructure/  Eloquent models, migrations, service provider, adapters
  Presentation/    controllers and routes
resources/js/      React + DevExtreme single-page application
```

Rules the code relies on:

- **Tenant isolation.** The tenant always comes from the authenticated user, never from a header or
  the request body. Tenant-owned models use `Shared\Infrastructure\Concern\BelongsToTenant`; with no
  tenant resolved, queries return nothing. Validation uses `TenantRule::exists()` instead of `exists:`.
- **Branch scope.** The client sends `X-Branch-ID`; the server checks it against the user's grants.
- **One posting path.** Stock changes only through `Inventory\Domain\Repository\StockLedger`, and
  the ledger only through `Finance\Application\PostJournalEntry`, both inside the caller's
  transaction — a sale, its stock issue and its journal commit or roll back together.
- **Immutable history.** PostgreSQL triggers reject changes to posted journals, journal lines, stock
  movements and audit logs. Corrections are reversals.
- **Money and quantities** are decimal strings (`bcmath`), never floats.
- **Retries.** Endpoints that create documents (sales, returns, payments, purchase orders,
  goods receipts, journals, stock adjustments and transfers) require an `Idempotency-Key` header.

Sale posting: `Dr Cash / Bank / Receivable — Cr Sales revenue, Sales tax payable` and
`Dr Cost of goods sold — Cr Inventory`. Accounts are mapped per company in `posting_accounts`.

## Development

```bash
docker compose up -d
docker compose exec node npm run dev          # Vite hot reload on :5173
docker compose exec app vendor/bin/pint       # code style
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/deptrac    # layer rules

# Tests need their own database (they exercise the PostgreSQL triggers)
docker compose exec postgres createdb -U erp_app erp_test
docker compose exec app php artisan test
```

## Notes

- **DevExtreme licence.** DevExtreme is a commercial product. Without a key it runs in trial mode
  and shows a notice. Put your key in `.env` as `VITE_DEVEXTREME_LICENSE_KEY=...` and rebuild.
- **Tax rates** in the demo data (18% sales tax, exempt) are examples. Confirm the treatment for
  your products with your accountant; FBR e-invoicing is not implemented.
- **Production.** Set `APP_ENV=production`, `APP_DEBUG=false`, real database and Reverb secrets,
  and terminate TLS in front of nginx. The demo tenant is not seeded in production; a tenant
  provisioning command is still to be written.

Proprietary — Brixel Technologies.
