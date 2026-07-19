# Pre-go-live security gate — walkthrough record

Phase 9. This is the evidence log for the 7-point release-blocking gate in
`SECURITY.md` §9. It is refreshed each time the gate is run; the last run is
dated below. Five of the seven items are code-level and pass now; two
(TLS grade, backup restore drill) can only be exercised against the live
alpharey.com server and are verified at cutover — they are tracked in
`LAUNCH_READINESS.md`.

**Last run: 2026-07-18** — 446 Pest tests / 2693 assertions passing (1 skipped),
Larastan level 6 clean, Pint clean, `composer audit` + `npm audit` clean.

---

## The 7-point gate

| # | Gate item | Status | Evidence |
|---|-----------|--------|----------|
| 1 | OWASP Top 10 walkthrough | ✅ PASS | §"OWASP Top 10" below |
| 2 | Tenancy isolation suite green, all modules | ✅ PASS | 18 test files assert cross-company isolation (see below) |
| 3 | Permission fuzz — every module × action × role | ✅ PASS | `PermissionFuzzTest` (144 abilities × roles, 1160 assertions) |
| 4 | Direct file-access probing returns nothing | ✅ PASS | `FileAccessProbeTest` (guest/cross-company/no-right all blocked) |
| 5 | `composer audit` + `npm audit` clean | ✅ PASS | both report zero advisories |
| 6 | TLS configuration (SSL Labs grade A) | ⏳ AT CUTOVER | live server only — `LAUNCH_READINESS.md` |
| 7 | Backup restore drill | ⏳ AT CUTOVER | production only — `LAUNCH_READINESS.md` |

---

## OWASP Top 10 (2021) walkthrough

**A01 — Broken Access Control.** Defence in depth, three layers:
1. Route middleware — `auth` + `active` on everything past login; `admin`
   (SA|CA) and `super_admin` groups gate the admin/company screens.
2. Per-action Gates — `{module}.{action}` checked in every controller action
   and every Inertia prop payload; `Gate::before` grants SA all and denies
   inactive users all.
3. Default-deny tenancy scope — `CompanyScope` returns zero rows for guests and
   filters every company-owned model to the acting company; a cross-company id
   resolves to **404, not 403** (existence is not leaked). Shared models
   (clients/vendors/proposals) omit the scope by design.
   No IDOR: `ColumnSettingsController` writes only `$request->user()->id`'s row.
   Pinned by `PermissionFuzzTest`, `FileAccessProbeTest`, and 18 tenancy suites.

**A02 — Cryptographic Failures.** NIF/DNI/NIE, IBAN, bank name, and every
wage/salary/pay figure (`base_salary`, `daily_wage`, `wage_rate`,
`per_meter_rate`, `project_rate`, advance `amount`, and all payroll line
figures incl. `net_amount`) use `encrypted` casts and sit in `$hidden` — so
they are never audited or serialised into an Inertia payload. Searchable NIF
uses a blind index (HMAC over `APP_KEY`), never the plaintext. TLS in transit
is enforced by forced-HTTPS (below) + HSTS.

**A03 — Injection.** Eloquent parameterises all queries. The only raw SQL is
static aggregation (`count(*)`, `SUM(...)`) and the constant `whereRaw('1 = 0')`
guard in `CompanyScope`; grep confirms **no** string interpolation into raw SQL.

**A04 — Insecure Design.** Money is computed server-side and never trusted from
the client (`InvoiceTotals`, `ProposalController::withTotals`); Option B
deployments (cesión ilegal) are refused at two layers; period locks reject
edits system-wide; audit logs and project notes are immutable at the model
layer.

**A05 — Security Misconfiguration.** `SecurityHeaders` middleware (outside
local): CSP `default-src 'self'; script-src 'self'` (no script `unsafe-inline`),
`frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`,
`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, HSTS on HTTPS.
HTTPS is forced outside local/testing; guests redirect to `/login`. Production
errors render `Pages/Error.vue` with no stack trace (bootstrap respond hook,
outside local/testing). **Launch item:** the server keeps its own `.env`, so
`APP_ENV=production` + `APP_DEBUG=false` must be verified there before go-live
(see `LAUNCH_READINESS.md`).

**A06 — Vulnerable & Outdated Components.** `composer audit` and `npm audit`
both clean; CI re-runs both on every push.

**A07 — Identification & Authentication Failures.** Login throttled 5/min per
email+IP; credential check enforces `active`; password reset is
enumeration-safe and throttled 6/min; `EnsureUserIsActive` locks a user out
mid-session; remember-me is 30 days.

**A08 — Software & Data Integrity Failures.** `AuditLog` throws on update and
delete (append-only at the model layer); `ReportRemark` project notes are
immutable once saved; deploy builds `vendor/` + assets on the CI runner and
rsyncs — the server runs neither Composer nor npm.

**A09 — Security Logging & Monitoring Failures.** Every business model is
`Auditable`: created/updated/deleted with old+new values, acting user, IP,
user-agent, method, and URL — append-only. `login`/`logout`/`exported`/`viewed`
are logged alongside CRUD.

**A10 — SSRF.** No feature fetches a user-supplied URL server-side; the only
outbound network call is SMTP, configured by an admin in Settings (encrypted
password), never derived from request input.

---

## Tenancy isolation coverage (gate item 2)

Cross-company isolation is asserted in: `TenancyTest`, `EmployeeTenancyTest`,
`AttendanceTest`, `DeploymentTest`, `DocumentsTest`, `ProjectDocumentsTest`,
`InventoryTest`, `InvoiceTest`, `LeaveTest`, `MeasurementsTest`, `ProjectsTest`,
`VehicleTest`, `DashboardTest`, `CallPanelTest`, `AuditLogScreenTest`,
`AdminUsersTest`, `PermissionMatrixTest`, `FileAccessProbeTest` — every
company-owned module. Each asserts (a) a user sees only their company's rows,
(b) a cross-company id 404s, and (c) a `company_id` supplied in request input
is ignored (filled from the session, never the request — dev-skill Rule 1).

---

## What a re-run does NOT re-check

Items 6 and 7 depend on the production host and are executed once, at cutover,
against alpharey.com. Everything else is exercised by the test suite and the
two audit commands, so a re-run is: `php artisan test`, `composer audit`,
`npm audit`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`.
