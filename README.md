# Cashflow

Multi-company / multi-profit-center cashflow & financial management module.

## Configuration

Set via environment variables (all optional, fall back to local defaults):

- `CASHFLOW_DB_HOST` (default `127.0.0.1`)
- `CASHFLOW_DB_PORT` (default `3306`)
- `CASHFLOW_DB_NAME` (default `cashflow`)
- `CASHFLOW_DB_USER` (default `root`)
- `CASHFLOW_DB_PASS` (default empty)
- `CASHFLOW_DEBUG=1` to enable `display_errors`
- `CASHFLOW_ENCRYPTION_KEY` — encrypts integration credentials at rest;
  **required in production** (see `DEPLOY.md`).

See `DEPLOY.md` for the full production deployment checklist (required PHP
extensions, `.htaccess`/webserver rules, first-deploy steps, and exactly
what each third-party integration needs from you before it can connect).

## Frontend / design system

Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 are vendored under
`assets/vendor/` and served same-origin — **not** loaded from a CDN. A
blocked/unreachable CDN (corporate firewall, ad blocker, regional
filtering, an outage) used to make the entire UI fall back to unstyled
browser default rendering while the custom dark/light backgrounds in
`assets/style.css` still applied, producing a visually broken split
layout; self-hosting removes that failure mode entirely and lets the CSP
in `config.php` be locked to `'self'`.

`assets/style.css` is the whole design system: CSS custom properties for
color/spacing/shadow tokens, the navbar/card/KPI-tile/table/form
component styles, and the small motion layer (`.cf-fade-in`, `.cf-stagger`
for staggered card entrances, hover/press micro-interactions on buttons
and cards) used consistently across the company app, the platform admin
panel (dark theme, same component language), and the auth screens
(login/select-company/setup). No external fonts either — a system font
stack, for the same reliability reason.

The schema (`schema.sql`) is applied automatically and idempotently on every
request (`CREATE TABLE IF NOT EXISTS`), matching the self-provisioning
convention already used elsewhere in this repo.

## Demo data

```
php seed.php
```

Creates two companies, several profit centers (Transport, Service,
Detailing, Colantări, plus an auto-created Corporate/General center per
company), a fleet vehicle/trailer/driver, a settled trip, a work order, an
outstanding invoice, a shared-cost allocation rule, and two users:

- `ion@exemplu.ro` / `parola123` — owner of both companies, full access.
- `maria@exemplu.ro` / `parola123` — operator on SC SERVICE SRL, access
  restricted to Detailing + Colantări only (Transport and Corporate are not
  visible to her, demonstrating the per-profit-center isolation model).

## Modules

- **Dashboard** — consolidated and per-profit-center views, cash real vs.
  cash atribuit, receivables/payables, 90-day forecast, and an
  activity-specific KPI panel (cost/km, venit/km, profit/cursă for
  `type = 'transport'` centers; materiale/manoperă/profit-per-lucrare for
  `type = 'service'` centers) driven entirely by the profit center's `type`
  field rather than hardcoded per-center logic (section 46).
- **Tranzacții** — manual ledger entries, scoped to the active context.
- **Transport** (`modules/transport.php`) — vehicles, drivers, curse
  (trips); a trip can be "trecută în cashflow", which posts an income
  transaction (tariff) and an expense transaction (fuel/road taxes/other
  costs) via `cashflow_create_transaction()`.
- **Lucrări** (`modules/service_orders.php`) — the Service/Detailing/
  Colantări equivalent of trips, same settle-into-ledger pattern.
- **Facturi** (`modules/invoices.php`) — creanțe (receivables) and datorii
  (payables); marking one paid/partial posts the matching transaction.
- **Alocare costuri** (`modules/allocations.php`) — splits one transaction's
  amount across several profit centers (must sum to the transaction total)
  and reusable percent/fixed allocation rules; recorded in
  `cf_transaction_allocations`, kept separate from realized cashflow
  (reports show "cashflow direct" and "costuri alocate primite" as
  distinct columns, per the spec's warning not to conflate cashflow with
  profit).
- **Documente** (`modules/documents.php`) — uploads (PDF/JPG/PNG/XML,
  validated by real MIME sniffing, 10MB cap, stored outside any directly
  web-servable path) count against the plan's `max_documents_month`; each
  can be sent on to Google Drive once that integration is connected.
- **Integrări** (`modules/integrations.php`, admin-only) — per-company
  settings + OAuth connect flows for ANAF e-Factura and Google Drive, a
  username/token form + test-connection for SmartBill, and a "verifică
  CUI" tool against ANAF's free public lookup (metered against
  `max_anaf_lookups_month`). See `lib/integrations/`.
- **Abonament** (`modules/billing.php`) — the company's current plan,
  this period's usage vs. limits, and which integrations the plan unlocks.
- **Rapoarte / Permisiuni / Audit / Conturi / Centre de profit** — as
  described below.

## Global admin panel (`admin.php`)

A separate front controller, gated by `cf_users.is_platform_admin` (set
manually in the DB — see `DEPLOY.md`), operating across every company
rather than one active company context:

- **Firme** — create companies (with their first owner account, creating
  the user if the email doesn't exist yet) system-wide, activate/
  deactivate, assign subscription plans. A platform admin can also "enter"
  any company from here for support purposes; `cashflow_require_company_access()`
  synthesizes an owner-equivalent role for them when they have no real
  `cf_company_users` row, and every such entry is itself written to
  `cf_audit_log` (`platform_admin_impersonation`) so it's never silent.
- **Utilizatori** — create accounts, reset passwords, toggle platform-admin
  status, see which companies each user belongs to.
- **Abonamente** — CRUD subscription plans: price, `max_documents_month`,
  `max_anaf_lookups_month`, `max_users`, `max_profit_centers`, and which
  feature flags (`excel_export`, `anaf_lookup`, `anaf_efactura`,
  `smartbill`, `google_drive`) the plan unlocks.
- **RBAC** — the role → permission matrix (`cf_roles` × `cf_permissions`
  via `cf_role_permissions`); roles are shared platform-wide, so an edit
  here applies to every company using that role immediately.
- **Audit** — `cf_audit_log` across every company, filterable by company.

There's no self-service signup or payment flow: assigning a plan to a
company is a platform-admin action (`admin.php` → Firme), not checkout.
Wiring a real payment processor would plug into
`cashflow_assign_subscription()` in `lib/subscriptions.php`.

## Architecture

- `lib/access.php` is the single authorization chokepoint: every module
  re-verifies `user -> company -> profit center -> action` against the
  database on every request. Nothing trusts a `company_id`/`profit_center_id`
  coming from the URL, a form, or the session without that check.
- Every company gets one `corporate`-type profit center automatically
  (`cashflow_ensure_corporate_center`), so `profit_center_id` can stay
  `NOT NULL` on transactions — general/shared costs land there instead of
  a null "no center" state.
- `lib/finance.php` holds the aggregation queries used by the dashboard and
  reports; they always take an explicit, pre-authorized list of profit
  center IDs rather than resolving access themselves.
- The active company/profit-center context is persisted in the session and
  re-validated (not just read) on every request; switching company clears
  the previously active profit center.
- RBAC (`lib/access.php`'s `cashflow_user_has_permission()`) is a second,
  finer-grained layer on top of the existing role/access-level checks:
  new company-scoped modules (documents, integrations) gate on a specific
  permission code rather than just "is admin"; the older modules keep
  their original role/access-level checks unchanged.
- Subscriptions/usage (`lib/subscriptions.php`) never trust a client-side
  "am I over quota" signal: `cashflow_check_and_increment_usage()` reads
  and increments the counter inside a DB transaction (`SELECT ... FOR
  UPDATE`) so two concurrent uploads can't both slip through under a
  limit of 1.
- Third-party integration credentials (`cf_company_integrations`) are
  encrypted at rest (`lib/crypto.php`, AES-256-GCM) and never re-rendered
  into a settings form — only a masked "connected" status is shown.
