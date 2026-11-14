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

### Recipes and production

Supply chain → Recipes & production. A recipe (bill of materials) lists the ingredients or
materials for a quantity of a product, with wastage %. It is used one of two ways:

- **Production order** (bakery, food unit, workshop, garment stitching): plan a quantity; on
  completion enter what was produced, materials really used, and labour / overheads. Materials
  leave stock at average cost, the product enters stock at (materials + extra cost) ÷ quantity,
  with an optional batch and expiry. Extra cost is journalled to inventory against
  "Production Costs Applied" (5250).
- **Used up when sold** (restaurant dish, café drink): the menu item is a non-stock product; each
  POS sale takes its ingredients out of the counter's warehouse and books their cost as cost of
  goods sold. Voiding the sale puts them back; refunds do not (food is not resold).

Demo: *Doodh Patti Chai (cup)* uses milk, tea and sugar when sold; *Sweet Lassi* is produced in
batches of 10 from yogurt and sugar.

### Quotations, sales orders, invoices and receivables

Selling → Quotations & orders. A quotation (validity date, credit days, price list, customer PO
reference, terms) is marked sent / accepted / rejected and converted into a sales order. Prices
are worked out by the same engine as the till when the document is saved, then fixed. An order
is invoiced in one go or in parts (serials entered per unit where tracked); each invoice takes
stock out of the order's warehouse, books revenue, tax and cost, puts the unpaid part on the
customer's account within the credit limit, and is due after the credit days. Voiding an
invoice reopens its quantities on the order; an invoice with receipts applied cannot be voided.

Selling → Receivables. Aging by customer (not due, 1–30, 31–60, 61–90, 90+ days past due),
statements of account for any period, and receipts applied to the oldest invoices first or to
invoices you pick. Returns credited to the account reduce that invoice's balance.

Quotations, orders, invoices and statements print on A4 (or save as PDF) with the company name
and NTN, customer details and the terms set under Quotations & orders → Print settings.

### Supplier invoices, debit notes and payables

Supply chain → Payables. A supplier's invoice is matched to the goods receipts it covers (the
receipts already matched the purchase order — a three-way match). Goods were put on the
supplier's account when received, so the bill only posts the difference between their invoice
and what was received (to Purchase Price Variance, 5130), plus any other charges on it —
freight, services, rent, utilities — to the expense accounts chosen, with input tax. A
difference above `purchasing.variance_tolerance_percent` (default 2 %) needs the
`purchasing.bill.override` permission. The same supplier invoice number cannot be entered twice.

Debit notes send goods back from a receipt: stock leaves at average cost, the supplier is owed
less at the receipt price (the gap to price variance), and an already-billed invoice's balance
drops. Payments are applied to the bills due first, or to the bills chosen. Aging by due date
(plus goods received but not yet billed) and supplier statements print like customer ones.

### Banking, cheques and reconciliation

Finance → Banking & cheques. Bank accounts and cash boxes each get their own ledger account (the
default cash and bank accounts are set up automatically). Cheques received and issued —
post-dated included — are kept in a register: in hand → deposited → cleared or bounced. Nothing
is posted until a cheque clears; clearing records the customer receipt or supplier payment into
that bank account and pays off the oldest invoices or bills. Cheques due within 7 days are
highlighted. Transfers (cash deposited, withdrawn, between banks) post a journal. Bank
reconciliation ticks the ledger lines on the statement and completes only when the reconciled
balance equals the statement balance; a statement CSV can be imported to tick matching amounts
within 5 days automatically, and unmatched statement lines are listed.

### Fixed assets

Finance → Fixed assets. Categories set the default method (straight line over a life in months,
or reducing balance at an annual rate) and residual value %. An asset is registered as paid from
bank or cash, owed to a supplier, or brought in as an existing asset with depreciation already
charged up to a date; each posts its journal. Monthly depreciation is previewed, then posted as
one journal (expense by branch against accumulated depreciation); missed months are caught up,
the same month cannot be charged twice, and only the latest run can be reversed. Each asset's
card projects its remaining depreciation. Selling or writing off posts the gain or loss.

### HR and payroll

Finance → HR & payroll. Employees (monthly salary or daily wage, named allowances and
deductions such as house rent or EOBI, monthly tax withheld, bank account, branch), a daily
attendance register with overtime, salary advances and loans recovered in installments, and a
monthly payroll that calculates from all of these. Lines can be adjusted (absent days, overtime,
bonus, tax, other deductions, advance recovery) before approval; approval needs a second person
(the owner excepted) and posts salaries expense against salaries payable, deductions payable and
advances; payment posts salaries payable against cash or bank. Printable payslips. The rules are
settings (`payroll.month_days` 30, `payroll.hours_per_day` 8, `payroll.overtime_rate` 1). Income
tax is entered per employee, not computed from tax slabs; leave balances are not tracked yet.

**What this does and does not cover.** Custom records capture data and track status; they do
not run process logic. A restaurant now has recipe-based stock, but no table plan, modifiers or
kitchen display; production has no routing, work centres or multi-level BOMs (a sub-assembly
must be produced first); a rental business can track agreements, but rent is not invoiced on a
schedule.
Also still missing within the retail trades: a
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
| Production | wired | Recipes / bills of materials with wastage, production orders (plan, complete with actual usage and overheads, cancel), recipe items that consume ingredients on sale |
| CRM | partial, **not wired** | Unreviewed scaffolding from an earlier pass; no routes, not registered in `bootstrap/providers.php` |
| People (HR & payroll) | wired | Employees, attendance register, advances and loans, monthly payroll with maker-checker approval, journals, payslips |
| Banking | wired | Bank and cash accounts, cheque register with post-dated cheques, transfers, bank reconciliation with CSV statement matching |
| Fixed assets | wired | Asset register, categories, straight-line and reducing-balance depreciation runs with reversal, disposal with gain/loss |
| Payables | wired | Supplier bills matched to goods receipts with variance tolerance, expense bills, debit notes, payments allocated to bills, aging, statements |
| Sales documents | wired | Quotations, sales orders, partial invoicing on credit terms, receipts allocated to invoices, aging, statements, A4 printing |
| Order management (delivery notes, stock reservation), Delivery, Workflow, billing for SaaS plans, offline POS sync | not started | — |

Known gaps inside the wired modules: supplier advances (paying before goods arrive), buy-X-get-Y and stacked
deals, partial transfer receipts, inter-company
transfers, journals for cash paid-in/paid-out, per-tenant custom roles, MFA, webhook delivery,
and server-side paging (lists are capped, typically at 500–1000 rows). Held POS sales are kept
in the browser of the terminal, not on the server.

Benchmark features still to build (from the SRS list of Technosys public capabilities):
salesperson commissions, quotations and sales orders, delivery and riders,
restaurant tables / kitchen display / reservations, online store, feedback (Feedo-style),
tasks and approval workflows (CAN-DO-style), leave management and tax-slab calculation,
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
