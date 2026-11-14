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

Demo logins (created only when `APP_ENV` is not `production`; change them after first login):

| User | Password | Role |
|---|---|---|
| admin@brixelltd.com | `BrixelAdmin2026!` | tenant owner, all branches |
| cashier@brixelltd.com | `BrixelCashier2026!` | cashier, Head Office only |

The demo tenant has a running 10% beverage deal, the coupon `WELCOME5`, and a branch price for sugar at the Gulshan branch.

## Current status

This repository holds the first vertical slice of the SRS: the platform foundation and the
sale-to-ledger flow (roughly SRS phases 1–3 plus the general ledger from phase 4).

**This code has not been executed yet.** It was written without access to a PHP 8.5 / Composer /
npm environment, so it has been syntax-checked only (`php -l`, TypeScript parser). Expect to fix
some issues on the first `./setup.sh` run, and run the test suite before relying on it.

| Module | State | What works |
|---|---|---|
| Shared | wired | Decimal maths, tenant scoping trait, contracts (audit, outbox, document numbers, settings) |
| Organization | wired | Tenants, companies, branches, warehouses, settings, gap-free document numbers |
| Identity | wired | Token login, users, seeded roles and permissions, branch grants, append-only audit log |
| Integration | wired | `Idempotency-Key` middleware, transactional outbox table (events are recorded, not yet published) |
| Catalog | wired | Products, barcodes, categories, brands, units, tax codes, branch-wise prices, deals (percent / amount off, by product, category or branch, time-bound), coupons, server-side price and tax quote |
| Inventory | wired | Append-only stock ledger, weighted-average cost, adjustments with journal, warehouse transfers |
| Finance | wired | Chart of accounts, posting-account mapping, periods, immutable double-entry journals, reversal, trial balance, P&L, balance sheet, general ledger |
| Sales | wired | Customers, credit limits and account payments, terminals, shifts with cash reconciliation and cash in/out, POS sale with deals and coupons, hold/resume, void, partial returns and refunds, price checker |
| Purchasing | wired | Suppliers, purchase orders with maker-checker approval, full or partial goods receipts into stock, supplier payments |
| Reporting | wired | Dashboard: sales, margin, stock, receivables, payables, payment mix, low stock |
| Production, CRM | partial, **not wired** | Unreviewed scaffolding from an earlier pass; no routes, not registered in `bootstrap/providers.php` |
| People, Payroll, Order management, Delivery, Workflow, SaaS admin, offline POS sync | not started | — |

Known gaps inside the wired modules: supplier-invoice matching (the liability is booked at
receipt, at order prices), supplier advances and purchase returns, buy-X-get-Y and stacked
deals, batch/serial/expiry tracking, stock counts, partial transfer receipts, inter-company
transfers, journals for cash paid-in/paid-out, per-tenant custom roles, MFA, webhook delivery,
and server-side paging (lists are capped, typically at 500–1000 rows). Held POS sales are kept
in the browser of the terminal, not on the server.

Benchmark features still to build (from the SRS list of Technosys public capabilities):
loyalty points, salesperson commissions, quotations and sales orders, delivery and riders,
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
