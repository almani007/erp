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

### Accounting: reports, budgets, recurring journals, year-end

Finance → Financial reports adds the general ledger for any account (opening and running
balance), profit and loss by month or by branch side by side, a cash flow statement (direct
method: each cash and bank movement classified by the other side of its journal into operating,
investing and financing), budget against actual with favourable / unfavourable variances, and a
sales tax summary (output tax on sales less returns, input tax on receipts and expense bills less
debit notes, by rate) as a working paper for the return. Every grid exports to Excel.

Finance → Accounting tools: budgets (12 monthly figures per income or expense account, company-wide
or per branch); recurring journals (weekly, monthly, quarterly or yearly; posted automatically each
night by `finance:recurring`, or on demand; a failure is shown on the template); and year-end close,
which moves the year's income and expense into retained earnings in one journal and closes the
year's periods — reversible with a reason. Closed years still show their results in profit and loss.

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
tax is entered per employee, not computed from tax slabs.

**Leave** (Finance → Leave). Leave types with a yearly allowance (fixed days, or each employee's
own annual leave days), carry-forward limits, half days, and paid or unpaid. New businesses start
with annual, sick, casual and unpaid leave. Staff whose login is linked to their employee record
request their own leave and see their balances; managers approve or reject, enter leave for
others, see a register of every employee's balance, and keep a holiday calendar (all branches or
one). Days are counted without holidays and weekly off days (`payroll.weekly_off_days`, e.g.
`["sun"]`). A paid request cannot go over the balance (allowance for the year, pro-rated for new
joiners, plus carry-forward, less leave taken or waiting). Approving writes the days into the
attendance register — paid leave, or unpaid leave that payroll deducts like an absence — and
cancelling takes them back out unless that month's payroll is already approved.

### Approvals, notifications and the online store

**Approvals** (Approvals in the menu; rules under its Rules tab). A rule names what needs approval —
supplier payments, stock adjustments (by value at cost), invoices from sales orders, employee
advances — from which amount, and the roles that approve it in order. A covered action is not
carried out: it is saved exactly as asked and the screen says "Sent for approval". Each level is
decided by someone with that role who is not the requester (the owner can approve any level);
after the last approval the action runs as the requester, or the request shows why it could not
(stock gone, period closed). Full history per request.

**Notifications.** A bell in the top bar: approvals waiting for your role, decisions on your
requests, new online orders and bookings, and morning alerts (`workflow:alerts`, 07:40) for low
stock, batches expiring within 30 days, FBR failures and overdue invoices.

**Online store** (Integrations → Online store). A public page at `/store/<business slug>`: products
by category with search (stock is shown only as available / out of stock), a cart, checkout for
delivery or pick-up, and appointment booking from real free slots. Orders become sales orders
marked "online" for staff to confirm and invoice; customers are matched by phone or added; the
branch manager is notified and the customer gets a WhatsApp / SMS confirmation when messaging is
on. Requests are rate-limited and carry a hidden bot trap. Products can be hidden from the store
and given an image URL.

### Gift cards and vouchers

Selling → Gift cards & vouchers. Cards are sold for cash or bank, or given free as promotions
(managers only; booked as promotional expense), singly or in batches, with an optional expiry
and customer, and printed on an A4 sheet with a QR code each. The unspent value sits in a
liability account. At the till "Gift card / voucher" is a payment method: the card number is
checked, its balance shown, and the amount it can cover filled in; a void puts the value back. A
return can be refunded as a store-credit voucher instead of cash. Lost cards can be blocked.
Cards past their expiry are closed every night (`sales:expire-gift-cards`, 00:40) and the value
left is booked as income.

### Foreign currencies

Finance → Currencies & exchange. Add the currencies you deal in and enter rates by date (units of
the base currency per unit). A supplier can be set to bill in a currency. A supplier bill can be
entered in that currency: the amounts are converted at the bill's rate (given, or the latest rate
on the bill date) and the bill keeps its foreign total and foreign balance. Paying in the currency
settles the foreign balance; payables are relieved at each bill's own rate and the money leaves at
the payment's rate, and the difference is posted as a realised exchange gain or loss. Paying a
foreign bill in base currency reduces its foreign balance at the bill's rate. At month end the
open foreign bills can be revalued at the closing rate (preview first); the unrealised gain or
loss is posted on that date and reversed the next day. Customer invoices in foreign currency are
not supported yet.

### Integrations

Administration → Integrations.

- **FBR, per branch.** *POS integration* (Tier-1 retail): every till sale and return is reported
  with the branch's POS ID and token; the receipt shows the FBR invoice number and its QR code.
  *Digital Invoicing*: back-office invoices are reported with the PRAL token, seller NTN and
  province, buyer NTN / province and each product's HS / PCT code. A sale never waits on FBR: if
  FBR is slow or down the submission is retried with back-off (up to 12 times) and listed with
  its error and a Retry button. Sandbox and live endpoints are FBR's published defaults and can be
  overridden. **Payload field names follow FBR's published specifications as of writing — run your
  first invoices in FBR's sandbox and compare with the current technical specification before
  going live.**
- **WhatsApp, SMS, email.** WhatsApp Business Cloud API (your phone number ID and token, with an
  approved template for business-initiated messages), any HTTP SMS gateway (URL with `{to}` and
  `{message}`), and email through `MAIL_*` in `.env`. Automatic: a receipt / invoice link after a
  sale to a known customer, overdue-invoice reminders N days after due (`1,7,30` by default) and
  appointment reminders the evening before. Every message is logged with its outcome.
- **Share links.** Receipts and invoices open from a signed link that expires after 60 days, no
  login needed; the POS receipt has a WhatsApp button.
- **Webhooks.** Subscribe a URL to events (`sales.sale.completed`, `sales.sale.returned`,
  `purchasing.goods.received`, … or `*`). Each POST carries `X-ERP-Event`, `X-ERP-Delivery` and
  `X-ERP-Signature: sha256=<HMAC-SHA256 of the raw body with the subscription secret>`; non-2xx
  answers are retried with back-off (10 attempts). Delivery history per subscription.
- **API keys.** A key for another system (website, marketplace sync, BI) acting with one role's
  permissions; shown once, revocable. Calls go to `/api/v1/...` with `Authorization: Bearer <key>`,
  `X-Branch-ID` for branch-scoped data and `Idempotency-Key` on posting calls.
- **Import.** Products, customers and suppliers from CSV (templates downloadable): a check run
  reports problems row by row without saving; existing SKUs / phone numbers / names are updated.

`integration:relay` (every minute) publishes the transactional outbox to FBR and webhooks;
`integration:reminders` (daily 18:05) sends reminders. Both run in the scheduler container.

### Restaurants, appointments, rentals and manufacturing

**Restaurant** (Selling → Restaurant, Kitchen display; Administration → Restaurant setup). Tables
by area on a live floor plan; dine-in, takeaway and delivery orders; modifier groups with
required / optional choices and price differences ("Sugar: gur +20", "Size", "Add-ons"); kitchen
notes; items sent to the kitchen as numbered tickets (KOT) printed per station; a kitchen display
with Start / Ready, timers and late tickets in red; void with reason, move table, bill print and
reopen; settlement with split payment and discount through the cashier's shift, so stock (recipes
use up ingredients), tax, journal and cash count work as at the till. Service charge % and its
service item are settings (`restaurant.service_charge_percent`, `restaurant.service_charge_sku`).

**Appointments** (Selling → Appointments). Staff, rooms or equipment with weekly working hours and
the services they offer; services carry a duration. Day / week / month / agenda calendar per
resource; bookings outside working hours or overlapping another are refused; free-slot lookup;
confirmed → arrived → done / no-show / cancelled; billing at the till; tomorrow's reminders open
WhatsApp with the message ready.

**Rentals** (Selling → Rentals). Contracts with a security deposit (held as a liability), the
stocked item leaving and returning to stock, rent invoiced to the customer's account daily,
weekly or monthly — automatically each night by `rentals:bill`, catching up missed periods — and
returns that bill up to the return date and refund the deposit less any deduction.

**Manufacturing.** Work centres with an hourly cost; recipe work steps (routing) costed into each
production order automatically; multi-level recipes — planning an order can also plan
sub-assemblies that are short in stock, down to five levels.

Still missing: a
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
| Accounting tools | wired | General ledger, monthly / branch P&L, cash flow, budget vs actual, sales tax summary, budgets, recurring journals, year-end close |
| Banking | wired | Bank and cash accounts, cheque register with post-dated cheques, transfers, bank reconciliation with CSV statement matching |
| Workflow | wired | Configurable multi-level approvals for payments, adjustments, invoices and advances; in-app notifications and daily alerts |
| Storefront | wired | Public online store with cart, delivery / pick-up orders and online booking |
| Integrations | wired | FBR POS and Digital Invoicing with retries, WhatsApp / SMS / email with automatic reminders, signed share links, signed webhooks, API keys, CSV import |
| Restaurant | wired | Floor plan, dine-in / takeaway / delivery, modifiers, KOT per station, kitchen display, split settlement |
| Appointments | wired | Resources with working hours, calendar, clash checks, free slots, WhatsApp reminders, billing |
| Rentals | wired | Contracts, deposits, nightly rent invoicing, returns with deductions |
| Fixed assets | wired | Asset register, categories, straight-line and reducing-balance depreciation runs with reversal, disposal with gain/loss |
| Payables | wired | Supplier bills matched to goods receipts with variance tolerance, expense bills, debit notes, payments allocated to bills, aging, statements |
| Leave | wired | Leave types and allowances, self-service requests, approval into attendance and payroll, balances with carry-forward, holiday calendar |
| Gift cards | wired | Sold or promotional cards and vouchers, redemption at the till, store credit on returns, blocking, expiry with breakage |
| Currencies | wired | Currencies and dated rates, foreign-currency supplier bills and payments, realised exchange gain/loss, month-end revaluation with automatic reversal |
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
