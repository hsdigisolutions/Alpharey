# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status: Phase 9 in progress — hardening (2026-07-24)

**Every screen 01–26 is built (Phase 8 complete).** Phase 9 is hardening, UAT, and
launch — no new screens. Current: **560 Pest tests / 3284 assertions passing (1
skipped) · Pint clean · Larastan level 6 clean · `composer audit` + `npm audit`
clean · production Vite build working.**

### Role system rebuild (2026-07-24)

The user/role/permission hierarchy was rebuilt:

**4-tier hierarchy (replaces the old 3-tier):**
- **`super_admin`** — God mode; bypasses all Gates via `Gate::before`; no one can
  edit/delete/demote an SA except another SA; not bound to any company via the pivot.
- **`admin`** (was `company_admin`) — Broad management access; bypasses the per-module
  permission matrix for their assigned companies; can manage Managers within their company.
- **`manager`** (was `user`) — Company-level access governed by `user_module_permissions`
  per-module rows; SA or Admin can assign them to multiple companies.
- **`worker`** — PWA only; sees own attendance/days/salary; no CRM access; exempt from 2FA.

**`user_company` pivot table** (migration `2026_07_24_070916_rebuild_user_roles_and_add_user_company_pivot`):
- Many-to-many between users and companies; columns: `id, user_id, company_id, assigned_by (nullable FK), created_at`
- No `updated_at` — assignments are discrete events, never "updated"
- Seeded from existing `users.company_id` on migration; `users.company_id` retained as the primary company
- `User::companies()` relation uses `withPivot('assigned_by', 'created_at')` — NOT `withTimestamps()`

**Backward-compat aliases kept** (deprecated, will be removed after all callers are updated):
- `User::isCompanyAdmin()` → calls `isAdmin()`
- `UserFactory::companyAdmin()` → calls `admin()`

**PermissionMatrixController additions:**
- `POST /admin/permissions/{user}/companies` — `assignCompany()`: SA assigns anywhere; Admin within their own assigned companies
- `DELETE /admin/permissions/{user}/companies/{company}` — `removeCompany()`: same scope rules
- Both endpoints: SA and Worker targets refused (403); audit every change; `company_id` follows the pivot when the primary changes
- Index returns `assigned_companies` + `availableCompanies`; the Permissions.vue user modal has the assign/remove panel

**Review pass (same day, post-rebuild) — what it caught and fixed:**
- The rebuild had left the FRONTEND posting the old role strings — user create/edit
  from Permissions.vue failed validation, `AppLayout`'s admin check hid the admin nav
  from Admin-role users, the Settings notification matrix rendered dead columns, and
  `notification_role_rules.role` rows were never renamed (second data migration added).
- **The pivot was decorative — now it is the authority.** `CompanyScope` scopes
  non-SA users through `CurrentCompany` (NOT bare `users.company_id`), and
  `CurrentCompany` lets Admins/Managers select among pivot-assigned companies,
  re-validating the session selection against the pivot on EVERY request.
  `POST /company/{company}/switch` + a header switcher (rendered when the shared
  `companies` prop holds >1 entry). `ModulePermissions` evaluates Manager rows
  against the ACTIVE company — on the user's own requests only, so
  `Gate::forUser()` on somebody else never reads the actor's session. Data scope
  and permission scope resolve through the same source and cannot diverge (a real
  divergence bug the `CompanySwitchTest` suite caught: gates followed a switch,
  `CompanyScope` didn't).
- **Invariant: every company-bound user appears on the pivot.** Seeded by the
  rebuild migration, maintained by `UserController::store`, `UsersImporter`, and
  assign/remove. The matrix writes grants to the BROWSED company when the target
  is assigned to it — that is how a multi-company Manager gets per-company rights.
- **SA role is LOCKED** — no demotion path, even for another SA (a demoted SA has
  no company: limbo). Deactivate instead. Supersedes the earlier demote behavior.
- `NotificationRules::recipients` includes pivot-assigned users; the fuzz suite
  now covers Worker (denied all 144 abilities even with a stray matrix row).

**Tests:** `CompanySwitchTest` (new, 6 tests) + additions across PermissionMatrix/
AdminUsers/PermissionFuzz/NotificationRules/LegacyImporters tests.

**Open (client decisions, not defects):** no UI path creates a NEW Super Admin
(seeder/console only — "only SA creates SA" is currently "nobody via UI");
whether Admins should get SA-tunable per-module restrictions (currently full
bypass within assigned companies, the pre-rebuild behavior).

### Post-Phase-9 client work (2026-07-22)

- **Rebrand Verto5 → AlphaRey**: code, UI, emails, PDFs, the wordmark (text "AR"
  treatment until a logo asset lands), and a data migration renaming the brand,
  the `app_name` setting, and the 5 placeholder companies to the real names
  (Contalex 365 · Alovar · Shizukani · Grupo Verto 5 · Malaga). The LEGACY system
  stays "VertoCRM" — that's the thing being replaced, not our branding.
- **Worker Mobile PWA (Phases A–E built; F is device QA + GDPR at cutover)**: an
  installable phone app for site crews. New `Worker` role — exempt from 2FA
  (client decision: email + password only), reaches NO CRM module (`EnsureWorker`
  / `DenyWorkers` are the two halves of the wall). `employees.user_id` links a
  login to one worker (the link neither schema had). Check-in captures a GPS fix
  and a live selfie, check-out a fix; GPS is EVIDENCE not a gate — a refusal
  flags `location_denied` and the punch still records. The wage side reuses
  `AttendanceService`, so a punch and a clerk timesheet flow through the identical
  snapshot path. Absence-with-reason writes an Absent row. Dashboard: month
  calendar + present/absent/hours/earned. Admin sees location (Google Maps link),
  selfie (gated + audited route), and the absence reason on the attendance record.
  Service worker scoped to /worker only. Offline check-in deliberately OUT of v1.
  A **pre-punch privacy notice** (LOPDGDD art. 90 / RD-ley 8/2019) now gates the
  first check-in — an INFORMATION duty with an acknowledgement, NOT consent;
  versioned (`WorkerPrivacyNotice::VERSION`) + audited + server-enforced; text in
  `docs/GDPR_WORKER_NOTICE.md` for the client's lawyer. **Open for the client:
  finalise the notice specifics (controller/DPO, selfie+GPS retention period,
  rights contact, RAT/DPIA); the monthly-worker `earned` figure convention.**
  Known edge: check-out across midnight not yet handled (day-shift crews
  unaffected — flagged as a follow-up task).
- **My Account (self-service, 2026-07-23)**: every CRM user reaches `/account`
  from the header user menu. Edit own name only (email/role/company stay
  admin-managed); password is a **request to a Super Admin**
  (`password_reset_requested_at` flag → SA sends a reset link from the Permission
  Matrix, never sees the password); own 2FA reconfigure + recovery-code regen
  behind a `current_password` re-check. Workers never see it (no CRM session).

### Phase 9 done so far

- **Line-by-line review, passes 1–6 (2026-07-19/20)**: every app PHP file, all 87
  Vue files, and every migration read in full, one phase-group per pass, each pass
  committed with its fixes pinned by tests. The catches, worst first: unapproved
  expense claims were reimbursed through payroll; `Rule::exists` on tenant tables
  let one company's admin inject advances/expenses/measurements into ANOTHER
  company's payslips (fixed with `OwnCompanyEmployee`/`OwnCompanyProject` rules);
  booking attendance for a deployed worker had NEVER worked (tenant-scoped lookup
  404'd the home-company row — the Phase 5 grid displayed them, entry was broken);
  a soft-deleted monthly employee kept drawing full payslips (bare
  `withoutGlobalScopes()` strips SoftDeletes — now dead via `ScopeDisciplineTest`);
  deleting an invoice cascaded away its payment records; the grid summary leaked
  `total_wage` past the pay gate; deployment complete/cancel transitions were
  unguarded; the 2FA screens' code label never rendered (`label-key=` vs `k=` —
  now guarded). Advisories logged for UAT: NIF visible to any employees.view
  holder; monthly-worker OT prices from `wage_rate` (data-convention risk);
  TOTP replay within the 30s window not burned.

- **Two-step verification, mandatory on every login** (client request): TOTP via an
  authenticator app (`pragmarx/google2fa`), with 8 single-use recovery codes and a
  Super-Admin reset lever on the Permission Matrix (the lost-phone path). The password
  step grants NO session — it parks the user id in the session and redirects to
  `/two-factor/challenge`; `TwoFactorController::verify` re-checks `active` there, so an
  account disabled between the two steps cannot slip through. `RequireTwoFactor`
  middleware confines an un-enrolled user to the setup screen. The secret and codes are
  `encrypted` casts AND in `$hidden` — never serialised, never audited. The QR is an
  inline `data:` SVG (the CSP forbids external images, and a QR service would be handed
  the shared secret). **`UserFactory` enrols by default** — a factory user without a
  second factor is half-configured and bounces off the middleware; enrolment tests use
  the `pendingTwoFactor()` state.
- **Security gate** (`docs/SECURITY_GATE.md`): 5 of the 7 release-blocking points
  (SECURITY.md §9) are green now — OWASP Top 10 walkthrough clean, tenancy suite
  (18 files), **permission fuzz** (`PermissionFuzzTest` — every Module × Action ×
  role, 1160 assertions), **file-access probing** (`FileAccessProbeTest`), audits
  clean. Items 6–7 (SSL Labs grade A, backup restore drill) are live-server-only
  and run at cutover.
- **Performance pass** (`PerformanceTest`): query-count N+1 guards on the heavy
  endpoints. Caught + fixed a real N+1 (employees list lazy-loaded `company` per
  row — 36 queries for 30 rows → a handful). Index review clean against the real
  dump's volumes; dashboard 120s cache verified.
- **Launch docs**: `LAUNCH_READINESS.md` (go/no-go checklist), `GO_LIVE_RUNBOOK.md`
  (alpharey.com cutover steps + rollback), `ADMIN_HANDBOOK.md` (roles, matrix,
  locked periods, APP_KEY custody, audit archive, backups).
- **Company-removal guard hardened**: the Phase-1 TODO ("append employees/projects/
  invoices blockers") was never closed — a company with a live workforce or money
  owed could be soft-deleted. Now blocks on users + employees + projects + unpaid
  invoices (fully-paid don't block).

Still open in Phase 9 (need the live server or client): TLS grade + backup drill,
responsive/mobile final pass, the final legacy cutover, UAT sign-off, the bilingual
screenshot user guide, EU-hosting confirmation in writing.

### Phase 8 recap (2026-07-18)

Dashboards, reports, search, notifications — built and verified against seeded MySQL
(charts drawn, 10 report modules + export links, live global-search endpoint).

Live screens: **03 Dashboard** (8 KPIs, 3 Chart.js charts, quick actions, expiring +
activity panels) · **14 Reports** (10 modules, filter bar, PDF/Excel export) · **15
Today's Report** (6 KPIs, live table, pending actions, 5-min refresh) · **global search**
(9 entity types, header dropdown) · **26 Settings** notification matrix + system health.

**Every screen 01–26 is now built.** The feature-complete system remains: Phase 9 is
hardening, UAT, and launch (no new screens).

### Phase 8 additions (map for future phases)

- **Dashboard** (`DashboardService`, cached 120s per company): all figures through the
  tenant-scoped models; deployments (no scope) hand-filtered. New **`VChart`** wraps
  Chart.js (bundled via Vite — the CSP blocks CDNs) and resolves the design tokens to
  concrete colours via `getComputedStyle`, re-rendering on theme flip. SA without a
  company selection → Welcome (decision 27).
- **Today's Report** (`TodayService`) is deliberately NOT cached — a live view. Advance
  amounts are encrypted pay data, stripped for anyone without `payroll.view`.
- **Reports** (`ReportService`, 9 modules): payroll/commission/financial each need their
  own module-view right beyond `reports.view` — the module is withheld from the page AND
  the export refused (403), and the dropdown only offers what the user may open. Export
  routes register BEFORE the index (the Phase 6 lesson). Generic `ReportExport` (Excel) +
  `exports/report-pdf.blade.php` (DomPDF).
- **Global search** (`GlobalSearch`): `/search` is the only non-Inertia GET (a keystroke
  dropdown wants JSON). A group is searched only if the user may view that module;
  company-owned models stay in the tenant scope; encrypted fields are never search
  targets.
- **Notifications** (`NotificationRules` + `NotificationDispatcher`): `notification_role_rules`
  is (type × role → enabled), group-wide; a missing row falls back to
  `NotificationType::defaultRoles`, so an empty table behaves as before. Every Phase-8
  alert goes through the dispatcher, so the Settings matrix genuinely controls delivery.
  **Deployment lifecycle events are the wired reference sender**; the other types (payroll
  ready, invoice overdue, advance/leave/project) have the dispatcher to call — their
  triggers are follow-up. `SystemHealth` powers the Settings health panel (DB, storage,
  DB-queue backlog, SMTP) — each check never throws.

### Phase 7 recap (2026-07-17)

Development Phase 7 (operations modules) was built and verified:
**387 Pest tests / 1358 assertions passing (1 skipped) · Pint clean · Larastan
level 6 clean · production Vite build working · all 5 translation guards green ·
the vehicle compliance light verified in the browser against seeded MySQL.**

Live screens: **22 Leave** (request → approve/reject/cancel, balances, adjust) ·
**21 Vehicles** (4 tabs) · **23 Inventory** (items, ledger, issues, project
assignments, categories) · **13 Call Panel**. The two integrations that made this
phase worth doing:

- **Leave feeds attendance AND payroll.** Approving writes the days into the grid
  as `AttendanceStatus::Leave`; paid leave for a daily worker then lands in
  `days_amount` on a real payroll run (verified: 3 days × 80 € = 240 €, overtime 0).
- **Vehicle insurance/ITV feed the compliance alerts.** Same traffic light, same
  `documents.warn_days` setting, same 90/60/30 + expiry-day schedule as documents.

Browser-verified: a Kangoo whose ITV lapses in 15 days saves under `company_id=1`
and renders the amber dot from the real date. That check also caught a **repeat of
scaffolding decision 27** (SA with no company → null `company_id` → 500) which 23
green tests were blind to; fixed + pinned.

## Phase 6 (complete, 2026-07-17)

Development Phase 6 (payroll & finance — the heaviest phase) is built and verified:
**306 Pest tests / 1102 assertions passing (1 skipped) · Pint clean · Larastan level 6
clean · `composer audit` clean · production Vite build working · payroll run, invoice
totals and the cross-company Option A note verified in the browser against seeded
MySQL.**

Live screens: **12 Payroll** (calculate → breakdown → adjust → approve all → mark paid
→ lock period) · **10 Invoices** (Ventas/Gastos tabs + slide-panel detail) · **Gastos**
· **19 Commission Reports**. Browser-verified: 4 employees × 840 € = 3.360 € from the
seeded July attendance (matching the Phase 4 grid exactly), and an invoice of
1000 − 10% discount + 21% IVA − 15% retención = **954,00 €** with the live preview and
the server independently agreeing.

**The D9 prototype gate was WAIVED by the client** (DECISIONS.md) — Phase 6 proceeded
without a prototype review, so design-change requests against these screens are normal
follow-up work, not defects.

Next up: **Development Phase 9** (hardening, UAT, launch — no new screens).
Earlier phases: Phase 0–8, design D1–D3 (approved 2026-07-14).

## Project skills — read them first

Two mandatory skills live in `.claude/skills/` (committed to the repo):

- **`alpharey-design`** — read before touching ANY UI file: tokens, typography,
  component rules, dark mode, mobile rules, the "what not to do" list.
- **`alpharey-development`** — read before writing ANY PHP/Vue: tenancy, gates,
  audit, VAT enum, bilingual system, form requests, testing requirements.

## What this is

AlphaReyCRM (brand: **AlphaRey**): a multi-company CRM for a Spanish construction group
(5 companies, one brand). Employees, documents/compliance, attendance, payroll,
invoicing, cross-company employee deployments, reports. 26 screens. Full spec:
`docs/REQUIREMENTS.md`.

Deploys to **alpharey.com** (Mukhost cPanel VPS, London DC — confirmed GDPR-acceptable),
replacing the legacy VertoCRM (Laravel 12 + Vue SPA) currently live there. Legacy data
migrates in — the authoritative database is on the live server, not local. Legacy code
copy and full schema review: `C:\Users\Super\Downloads\VertoCRM-main\` (see its
`CODEBASE_REVIEW.md`).

## Key documents

- `docs/REQUIREMENTS.md` — the client's full requirements (source of truth for scope)
- `docs/DECISIONS.md` — confirmed client decisions (**check first** — overrides any
  spec default; includes the 2026-07-13 stack change, hosting confirmation, VAT policy)
- `docs/DEVELOPMENT_PLAN.md` — 10 build phases (0–9), which screens/tables land where
- `docs/DESIGN_PLAN.md` — design phases D0–D10, tokens → components → all 26 screens
- `docs/DATA_MIGRATION.md` — legacy → new schema mapping, conflicts, cutover plan
- `docs/STACK.md` — stack rationale and cPanel/Mukhost hosting constraints
- `docs/SECURITY.md` — the security architecture and pre-launch gate
- `docs/PAYROLL_DEPLOYMENTS.md` — cross-company deployment payroll model (Option A)

## Stack (confirmed 2026-07-13)

Laravel 12 (PHP 8.3 in production) + **Vue 3 + Inertia.js 3** (no SSR, no separate API,
session auth) + Ziggy + Tailwind CSS 4 + MySQL. Database drivers for cache/session/queue
(no Redis). Pest + Larastan (level 6) + Pint. DomPDF, Maatwebsite/Excel, and Chart.js
are agreed but installed in the phases that first use them. GitHub Actions CI; deploy
GitHub → SSH/rsync → Mukhost.

## Local development (this machine, Windows)

Environment already configured here:

- PHP 8.2.30 (WinGet install) — extensions `pdo_mysql`, `zip`, `intl`, `gd` were enabled
  in its `php.ini` for this project (backup saved as `php.ini.bak-verto-20260712`)
- Composer runs as a phar: `php C:\Users\Super\.composer\composer.phar <command>`
  (no global composer on this machine)
- Laragon MySQL 8.4 on `127.0.0.1:3306`, user `root`, empty password; app database
  `vertocrm` (created); tests do NOT need MySQL (in-memory SQLite via phpunit.xml)
- Node 20 + npm 10

### Commands

```bash
# install
php C:\Users\Super\.composer\composer.phar install
npm install

# first-time env (already done here)
cp .env.example .env && php artisan key:generate

# database
php artisan migrate:fresh --seed     # 5 dummy companies + 3 users (see below)

# run (two options)
composer run dev                     # serve + queue + logs + vite together
php artisan serve                    # …or separately, plus: npm run dev

# tests — full suite, one file, one test
php artisan test
php artisan test tests/Feature/TenancyTest.php
vendor/bin/pest --filter "shows users only rows of their own company"

# quality gates (all must be green before commit)
vendor/bin/pint --test               # style check (vendor/bin/pint to auto-fix)
vendor/bin/phpstan analyse --memory-limit=1G
php C:\Users\Super\.composer\composer.phar audit
npm run build                        # production asset build

# legacy import framework (importers register from Phase 1 onward)
php artisan verto:import-legacy --dry-run
```

Seeded local users (password `password`, local only): `admin@alpharey.local` (Super
Admin), `empresa1.admin@alpharey.local` (Company Admin), `empresa1.user@alpharey.local`
(custom user). There is **no login flow yet** (Phase 1) — use `actingAs()` in tests.

## CI / deployment

- `.github/workflows/ci.yml` — every push/PR: Pint → Larastan → composer audit → Pest →
  Vite build → npm audit, on PHP 8.3 (the production target).
- `.github/workflows/deploy.yml` — manual `workflow_dispatch` (staging/production).
  Builds `vendor/` (no-dev) and assets **on the runner**, rsyncs over SSH (server needs
  neither Composer nor Node), then `migrate --force` + config/route/view/event cache.
  Required secrets: `DEPLOY_SSH_HOST`, `DEPLOY_SSH_USER`, `DEPLOY_SSH_KEY`,
  `DEPLOY_PATH`. The server keeps its own `.env` and `storage/` (excluded from sync).
- Server one-time setup (when provisioning): `.env` + key, storage dirs, document root →
  `public/`, AutoSSL, and cron: `* * * * * php $DEPLOY_PATH/artisan schedule:run`
  (the queue is processed via the scheduler from Phase 2 when the first jobs land).
- Branching: `main` = deployable, protected by CI; feature branches merge by PR.

## What Phase 0 built (map for future phases)

- **Tables**: users (+role/company_id/locale/active), brands, companies, settings,
  audit_logs, user_module_permissions, legacy_id_map (+ framework tables)
- **Tenancy**: `App\Models\Concerns\BelongsToCompany` + `App\Models\Scopes\CompanyScope`
  — default-deny: guests see zero rows; users/admins locked to their company; Super
  Admin browses all or selects one via `App\Support\CurrentCompany::select()` (session);
  system code opts out explicitly with `Model::acrossAllCompanies()`
- **Permissions**: `App\Enums\Module` (18) × `App\Enums\PermissionAction` (8) → Gate
  abilities named `"{module}.{action}"` backed by `user_module_permissions`
  (`App\Services\Permissions\ModulePermissions`); `Gate::before` grants Super Admin
  everything and denies inactive users everything; checks are uncached → changes apply
  immediately
- **Audit**: `App\Models\Concerns\Auditable` + `App\Services\Audit\AuditLogger` —
  created/updated/deleted with old/new values, acting user, IP/user-agent/method/URL;
  `AuditLog` is append-only (update/delete throw `RuntimeException`)
- **Settings**: `App\Services\Settings\SettingsService` — DB-backed key/value, cached
  per key, JSON round-trip, distinguishes stored-null from missing
- **Bilingual system**: `lang/es/ui.php` + `lang/en/ui.php` shipped to every Inertia
  page (`props.lang.es/en` via `HandleInertiaRequests`); global `<Bilingual k="…"/>`
  Vue component renders primary + secondary language; ES/EN toggle at `POST /locale`
  persists to `users.locale` (session for guests)
- **VAT**: `App\Enums\VatRate` — the canonical dropdown source (21/10/4/0 + blank
  default) with bilingual labels and `amountFor()` calculation, per DECISIONS.md
- **Shell**: `resources/js/Layouts/AppLayout.vue` (sidebar with the 10 primary items,
  header with dark/light + ES/EN toggles, mobile bottom nav), `Pages/Auth/Login.vue`
  placeholder, `Pages/Dashboard.vue` placeholder, bilingual `Pages/Error.vue`
- **Security**: `SecurityHeaders` middleware (CSP outside local, HSTS on HTTPS,
  X-Frame-Options DENY…), forced HTTPS outside local/testing, guests redirected to
  `/login`
- **Legacy import framework**: `php artisan verto:import-legacy` +
  `App\Services\LegacyImport\AbstractImporter` (idempotent via `legacy_id_map`, dry-run
  rolls back everything, exceptions CSV to `storage/app/import-exceptions/`); reads the
  `legacy` DB connection (`LEGACY_DB_*` env); importers register in
  `config/legacy-import.php` phase by phase

## Scaffolding decisions made in Phase 0 (not in DECISIONS.md)

1. `composer.json` requires `php ^8.2` because this dev machine runs 8.2.30; production
   is 8.3. **Do not use 8.3-only syntax** (e.g. typed class constants) until local PHP
   is upgraded.
2. Ziggy provides `route()` in Vue; `'ziggy-js'` is aliased to `vendor/tightenco/ziggy`
   in `vite.config.js` — `composer install` must run before `npm run build`.
3. Tests run on in-memory SQLite; the base `TestCase` calls `withoutVite()`.
4. `company_id` is intentionally **not mass assignable** on company-owned models — the
   `BelongsToCompany` creating hook fills it from the active company (tests assert a
   malicious `company_id` in input is ignored).
5. Inertia shared props are limited to: `auth.user` (minimal fields), `locale`,
   `lang.es/en`, `flash` — keep the payload small; never share data the current user
   isn't allowed to see.
6. Placeholder design tokens live in `resources/css/app.css` under `@theme` with
   semantic names (`surface/ink/accent/status-*`, light + `.dark` values). Design phase
   D1 replaces token **values** only — components must reference semantic names.
7. Audit hygiene: a model's `$hidden` attributes are always excluded from audit values;
   add `public array $auditExclude` for extra columns and
   `public string $auditModule = '…'` to tag the module.
8. Error rendering: 403/404/500/503 go through `Pages/Error.vue` in production
   (`bootstrap/app.php` respond hook); 419 redirects back with a bilingual flash;
   local/testing keep Laravel's debug pages.
9. No `/api` routes, no Sanctum tokens, no CORS — Inertia is server-driven with session
   auth. Do not copy API patterns from the legacy codebase.
10. The seeder is idempotent (`firstOrCreate`) and safe to re-run.

## Non-negotiable conventions

- **Bilingual labels everywhere**: every UI label renders Spanish primary + English
  secondary via `<Bilingual/>` and the `ui` lang files. Never hard-code UI strings.
- **Tenancy**: company-owned models use the `BelongsToCompany` global scope; the active
  company comes from the session, never from request input. Clients, vendors, and
  proposals are shared across companies by design; projects belong to one company.
- **Authorization**: every controller action checks module permissions
  (`user_module_permissions`) server-side, and every Inertia page prop payload is
  filtered server-side. UI hiding is never the control.
- **Audit**: all models are observed (`Auditable`) and logged to `audit_logs`; the log
  is append-only.
- **VAT dropdown, blank default**: every VAT field uses `App\Enums\VatRate` options
  (21/10/4/0/blank). No default rate, ever. Historical migrated VAT values untouched.
- **Company details are data, not code**: names, logos, CIFs editable from Settings.
- **Create/edit in modals or slide panels** — never a separate page.
- **Soft deletes** for employees and clients; project notes are immutable once saved.
- Encrypted casts for NIF/DNI/NIE/passport, IBAN, and salary fields (blind index where
  searchable) — lands with the employees table in Phase 2.
- Every module ships with Pest tests including tenancy-isolation and permission tests
  (`tests/Feature/TenancyTest.php` is the template).

## Phase 1 additions (map for future phases)

- **Auth**: `Auth\*` controllers + `LoginRequest` (5/min throttle, `active` credential
  check), `BilingualResetPassword` notification, `EnsureUserIsActive` (mid-session
  lockout), remember-me duration set to 30 days in `AppServiceProvider`
- **Middleware aliases**: `active`, `admin` (SA|CA), `super_admin`;
  `ApplySessionTimeout` is prepended to `web` so Settings-driven session lifetime
  applies before StartSession
- **Company context**: `HandleInertiaRequests` shares `company` (id+name); admin
  screens resolve context via `Admin\Concerns\ResolvesCompanyContext` (SA without a
  selection is redirected to Welcome)
- **Permission matrix**: `Module::actions()` defines the applicable-action map
  (the "—" cells); non-applicable actions are forced off server-side on save;
  matrix rows apply to role `user` only — admins bypass via `Gate::before`
- **Settings**: `MailSettings` service — SMTP config stored in settings, password
  Crypt-encrypted (`mail.password`), never echoed to the client (`has_password` flag),
  applied to the runtime mailer at boot and before test sends
- **Company removal**: `Services\Companies\CompanyRemovalGuard` returns blocker keys;
  Phase 1 blocks on attached users — future phases append employees/projects/invoices
  checks there
- **Importers**: `Importers\UsersImporter` (role mapping, hash carry-over via
  base-query update because the `hashed` cast rejects foreign-cost hashes),
  `Importers\SettingsImporter` (whitelist remap, secrets never imported)
- **Audit actions** now include `login`, `logout`, `exported`, `viewed` alongside
  created/updated/deleted

## Scaffolding decisions made in Phase 1 (not in DECISIONS.md)

11. The spec's RBAC tables (roles/permissions/role_permission/user_role) and teams
    tables were **not** created — the 3-level `UserRole` enum + `user_module_permissions`
    implements the spec's access model exactly; teams arrive with the Settings→Teams
    section in a later phase.
12. Users cannot edit themselves through `/admin/users` (lockout/escalation guard);
    Company Admins cannot modify other admins — Super Admin only.
13. Password reset responses are enumeration-safe (same message either way);
    reset + forgot endpoints throttled 6/min.
14. General settings keys: `general.app_name`, `general.default_locale`,
    `general.timezone`, `general.session_timeout_minutes`; mail keys under `mail.*`.

## Phase 2 additions (map for future phases)

- **Employees**: `Employee` uses `BelongsToCompany` + `Auditable`; NIF/IBAN/bank/salary
  are `encrypted` casts + in `$hidden` (never audited/serialized); `nif_hash` blind
  index (HMAC over APP_KEY) keeps NIF searchable — set in a `saving` hook, searched in
  the list query. Codes generated `E{companyId}-{seq}` via `Employee::nextCode()`.
- **EmployeeService**: create/update pipeline — code generation, append-only encrypted
  `employee_salary_history` on wage-field changes, effective-dated `employee_wage_rates`
  (consumed by attendance/payroll later).
- **Wage/bank visibility**: gated server-side by `payroll.view || employees.edit` — the
  controllers null out wage/bank fields in props for users without it (not UI hiding).
- **Table framework (server-driven)**: `EmployeeQueryFilter` is shared by the list and
  the Excel/PDF exports (so "export the filtered view" is literal); column visibility in
  `user_column_settings` via `PUT /column-settings`; sort whitelist in the controller.
- **Documents engine**: `DocumentController` handles both employee + company entities;
  files on the **`local` disk** under `storage/app/private/{type}s/{id}/documents`,
  randomized names, original kept as metadata; re-upload of a type creates a new
  **version** (prior row `is_current=false` — note `is_current`/`file_path` are NOT
  fillable, set them directly); every upload/download/delete audited. `Company` is not
  tenancy-scoped, so `DocumentController::resolveEntity` guards company docs explicitly.
- **DocumentStatus service**: the single traffic-light authority (ok/warn/danger/
  neutral/exempt) + compliance scoring; monthly company types get month-end logic.
  `App\Support\DocumentTypes` is the registry; labels in `lang/*/ui.php` under
  `doc_types.*`. **The registry mirrors the client's own workbook `DATOS OBLIGATORIOS
  EMPRESA.xlsx` (16 company docs + the worker/prevención set) — corrected 2026-07-16,
  replacing the earlier spec-inferred list; frequencies client-confirmed the same day.
  Do not "tidy" these keys; see DECISIONS.md → "Document types". Certificado SS +
  Hacienda are MONTHLY (not expiry-driven); REA is a 3-year periodic renewal; DNI and
  NIE are separate slots; the contract is an uploaded file. Pinned by
  `tests/Unit/DocumentTypesTest.php`.**
- **Notifications**: DB + mail via `DocumentAlertNotification`; bell shares unread count
  + latest 8 through `HandleInertiaRequests`; `verto:scan-documents` (scheduled daily
  07:00 Madrid in `routes/console.php`) implements the confirmed schedules — annual
  90/60/30 + expiry-day to Company Admins; monthly 5/2-days-before + 1st-of-month
  overdue, with a cross-company summary to Super Admins. **WhereBetween date bounds use
  `toDateString()`** so same-day rows match under both MySQL and SQLite.
- **Excel/PDF**: `maatwebsite/excel` + `barryvdh/laravel-dompdf` installed;
  `EmployeesExport`/`EmployeesImport` (bilingual headers, per-row validation with a
  failure report), PDF via `resources/views/exports/employees-pdf.blade.php`.

## Scaffolding decisions made in Phase 2 (not in DECISIONS.md)

15. Models carry `@property` PHPDoc for enum + date casts so Larastan level 6 resolves
    `?->value` / `?->toDateString()` on cast attributes.
16. Employee notes are editable/deletable (per spec); project notes will be immutable
    (Phase 3) — different rule, don't copy this pattern there.
17. `documents.warn_days` setting (default `[90,60,30]`) drives both the warn window in
    `DocumentStatus` and the milestone days in the scan command.
18. The queue runs on the database driver, processed by the scheduler
    (`queue:work --stop-when-empty` every minute) — no daemon on cPanel.

## Phase 3 additions (map for future phases)

- **Shared models** (Client, Vendor, Proposal): NO `BelongsToCompany` — every company
  sees the same rows; still `Auditable` + permission-gated. Clients soft-delete; vendors
  and proposals do not. `client_communications` and `client_contacts` are editable
  (CRM data), unlike immutable project notes.
- **Projects** (company-owned): `BelongsToCompany`; codes `P{companyId}-{seq}` via
  `Project::nextCode()`. `ProjectController::row()` is the shared list-row shape used by
  both the paginated table and the kanban grouping. Per-project wage overrides in
  `project_employee_rates` (encrypted `project_rate`, hidden, wage-gated in props).
- **Immutable project notes** (`ReportRemark`): blocks update AND delete at the model
  layer (like `AuditLog`); `const UPDATED_AT = null`. Alerts (`alerts`) are the
  scheduled project email alerts (sending wired in Phase 8).
- **Proposals**: totals are computed server-side in `ProposalController::withTotals()`
  from line items — never trust client-sent totals; `number` is server-generated
  (`Proposal::nextNumber()`, in `$fillable` but never in the Form Request). Optional VAT
  via `VatRate`; blank = no VAT line. PDF via `resources/views/exports/proposal-pdf`.
- **Documents engine** now accepts `entity_type=project` (+ `DocumentTypes::project()`);
  `DocumentController::resolveEntity` scopes projects via the global scope.

## Scaffolding decisions made in Phase 3 (not in DECISIONS.md)

19. Shared models carry no `company_id`; a `ClientsTest` asserts the column's absence so
    the "shared pool" contract can't silently regress.
20. Projects are NOT soft-deleted (not in the REQUIREMENTS soft-delete list — only
    employees + clients are); a test pins this.
21. Client communication types: call/meeting/email/note; project note types:
    internal/client_call/client_email/meeting/message.

## Phase 4 additions (map for future phases)

- **Attendance** (`attendance`, singular table; company-owned): `AttendanceService` is
  the create/update pipeline. It FREEZES `wage_type_snapshot`/`wage_rate_snapshot`/
  `hourly_rate_snapshot` at entry (a later raise never rewrites history — tested), and
  computes the day total from the snapshot + the employee's overtime policy, unless
  `manual_wage_override`. Hourly mode derives hours from check-in/out − break;
  project-based takes manual hours. Decimal columns are assigned as strings (matches the
  `numeric-string` @property so Larastan is happy). `attendance_logs` records edits.
- **Calendar grid**: `AttendanceController::index` builds `grid[employee][day]` + a
  monthly `summary`. **The summary counts status via `$r->status->value`** — `status` is
  an AttendanceStatus enum cast, so `whereIn('status', ['present'])` on the collection
  silently matches nothing (bug found + fixed + pinned by a test in Phase 4). Cell edit
  loads via a partial reload (`?edit=ID`, Inertia `only: ['editing']`).
- **Measurements** (company-owned): approve/reject sets `approved`/`approved_by`/
  `approved_at` DIRECTLY (not mass-assignable — like `Document::is_current`); approved
  rows feed project billing in Phase 6. `measurements.approve` gates the action.
- **Overtime policies**: `OvertimePolicy` gets an `OvertimePolicyType` cast; managed in
  Settings (Admin only). Percentage → OT × (1+rate/100); fixed_hourly → fixed rate;
  accumulate/none → OT not paid as cash.
- **Importer**: `AttendanceImporter` remaps employee + project ids and carries wage
  snapshots + totals over VERBATIM (historical facts, never recomputed).

## Scaffolding decisions made in Phase 4 (not in DECISIONS.md)

22. Attendance is one row per employee per day (unique index). The UI prevents duplicate
    cells; a raw duplicate POST surfaces as a 500 (acceptable — a friendly guard can be
    added if the import path ever needs it).
23. Employee Asistencia/Nómina and Project Asistencia/Mediciones/Facturas/Gastos detail
    tabs remain "coming soon" placeholders — the standalone Screens 11/24 are the
    canonical surfaces; wiring the tabs to the same data is a cheap later pass.

## Phase 5 additions (map for future phases)

- **Deployments** (`employee_deployments`, `deployment_charges`): span TWO companies, so
  the model does NOT use `BelongsToCompany`. Visibility is home-OR-host (or Super Admin)
  via `EmployeeDeployment::scopeVisibleTo($companyId)`; `employee()`/`project()` relations
  use `->withoutGlobalScopes()` (they cross the tenant scope by design). Still `Auditable`
  (`$auditModule = 'deployments'`) + permission-gated. Decimal columns
  (`rate_during_deployment`, `split_pct`) assigned as strings (`numeric-string` @property).
- **DeploymentController**: `host_company_id` is ALWAYS the acting company (never accepted
  from input); the project must belong to the host, the employee to the chosen home
  company (both re-checked server-side after validation). `availableEmployees` is the
  gated cross-company lookup (returns id/name/designation only). `store` runs the overlap
  guard; `complete`/`cancel` mutate status, `complete` generates the cross-charge.
- **Option A cross-charge engine** (`DeploymentChargeService`): `accruedUnits` reads the
  employee's HOST-project attendance within the window (hours, or day count for a daily
  rate); `accruedAmount` = units × rate × split% (live, shown on the list for
  payroll/approve viewers); `generateCharge` persists a `DeploymentCharge` on completion.
  **Returns null for any billing method other than Option A** — the engine itself refuses
  to automate Option B/C (belt-and-braces with the `StoreDeploymentRequest` `Rule::in`).
- **Attendance integration**: `AttendanceController::index` appends employees deployed
  INTO the active company for the shown month (`deployedInEmployees`) with a `deployed`
  flag + `home_company`; the grid renders a `VBadge status="info"` "Desplegado/Deployed"
  and shows the home company in place of the designation. The records query is explicit
  (`withoutGlobalScopes()->where('company_id', …)->whereIn('employee_id', …)`) so deployed
  rows (logged under the host `company_id`) are included.
- **No legacy importer**: the legacy system was single-company, so it never modelled a
  deployment between companies — net-new feature, nothing to migrate (noted in
  `config/legacy-import.php`).

## Scaffolding decisions made in Phase 5 (not in DECISIONS.md)

24. `billing_method` is validated to Option A only (`Rule::in([BillingMethod::OptionA])`)
    AND the charge engine returns null for non-A — Option B (cesión ilegal) is refused at
    two layers, never merely hidden in the UI. A test pins both.
25. Deployments are NOT soft-deleted and have no in-place edit — the lifecycle is
    create → complete/cancel (status transitions), matching how a real posting is closed
    out. `deployment_charges` are keyed 1:1 on the deployment (`updateOrCreate`).
26. The Deployments screen (Screen 12) lives in the "Más módulos" secondary nav; a
    `deployments` AppIcon was added. Per Phase-4 decision 23, the project-detail deploy
    tab stays a placeholder — the standalone screen is the canonical create surface.

## Phase 6 additions (map for future phases)

- **Payroll** (`payrolls`, `advances`, `advance_categories`, `locked_periods`):
  `PayrollService` computes a month ONLY from the wage snapshots frozen on attendance
  (Phase 4) — never the live rate. The breakdown mirrors REQUIREMENTS.md Screen 12
  exactly. Per-employee pay is **encrypted at rest AND in `$hidden`**, so it cannot leak
  into an Inertia payload; controllers opt each figure in behind `payroll.view`.
- **Cross-company (the subtle one)**: under Option A a deployed worker's attendance is
  written under the HOST `company_id` but the HOME company pays. `PayrollService`
  therefore gathers attendance **per employee, `withoutGlobalScopes()`**, and the home
  payroll carries a "Deployed to X — cost transferred" note. Don't "fix" that to a
  company-scoped query.
- **`App\Support\PeriodLock`** is the single authority for closed months, enforced in
  `AttendanceService` + the attendance delete path too — that is what makes "locked
  months reject edits SYSTEM-WIDE" true rather than a payroll-screen courtesy. Bound as
  a **singleton** so the memo and `forget()` are shared.
- **Invoices** (`invoices`, `invoice_line_items`, `payments`, `invoice_reminders`):
  `InvoiceTotals` is the single authority — subtotal − discount = base; **IVA on the
  base; retención withheld from the base**. Client-sent totals are ignored;
  `payment_status` is always re-derived from the payment records.
- **VAT is a `VatRate` enum everywhere** (invoices/expenses/proposals), never a raw
  percent. Blank = NO VAT line, never 0% — the PDF omits the row entirely.
  `VatRate::fromPercent()` maps a legacy percentage back; a non-official rate is flagged
  rather than rounded (DATA_MIGRATION.md §3.5b).
- **Expenses**: an expense carrying BOTH `employee_id` and `project_id` is a "worker
  project expense" and is paid back through that worker's payroll for the month. Those
  two fields together are load-bearing, not tags.
- **Commissions**: invoice total × the employee's `commission_percent`, only for
  employees assigned to the project. Finalizing is a one-way door; `original_amount` is
  never overwritten — (original, adjusted + reason) IS the audit story.
- **i18n**: `resources/js/translate.js` provides global `$t()` / `$tPair()` for slots
  that cannot hold markup (tab titles, aria-labels, placeholders, `<option>` text).
  `<Bilingual k>` still covers two-line labels. **Never write `lang.es.*` in a component
  or hardcode a `<Head title>`** — `tests/Unit/TranslationCoverageTest.php` fails the
  build on the pattern.
- **Importers**: invoices/expenses/payrolls/advances migrate every figure VERBATIM —
  historical money is a fact and is never recomputed by the new engines. Commission
  entries have no importer (derived; the legacy settlement engine is dead code).

## Scaffolding decisions made in Phase 6 (not in DECISIONS.md)

27. Company-scoped finance actions use `ResolvesCompanyContext` (redirect to Welcome),
    not a bare 403: an invoice/payroll always belongs to ONE issuing company, and a
    Super Admin browsing "all companies" has none. Found in the browser as a 500 — the
    suite missed it because a company admin always HAS a company.
28. Invoice/expense money is NOT encrypted (company books, aggregated in SQL); only
    per-employee pay is. Two different rules, deliberately.
29. `filteredQuery()` is shared by the invoices screen and its Excel export so "export
    the filtered view" (§10) is literal and the two cannot drift.
30. Export routes register BEFORE `{param}` routes (`/invoices/export` must not resolve
    as `/invoices/{invoice}`) — pinned by a test, not left to ordering luck.
31. `payroll.export` additionally requires `payroll.view`: the sheet is nothing but
    wages, so exporting without the right to see pay would leak the whole payroll.

## Phase 7 additions (map for future phases)

- **Leave** (`leaves`, `leave_balances`, `leave_categories`): keyed on
  `employee_id`, NOT `user_id` as legacy had it — approved leave writes attendance
  and therefore reaches payroll, and both are keyed on employees. `LeaveService`
  owns the lifecycle. **The pricing rules are load-bearing**: unpaid leave, and
  paid leave for monthly/per-meter workers, book 0 hours and 0 pay (a salary
  already covers the day — paying it again through attendance pays it twice); paid
  leave for daily/hourly workers is priced from the wage snapshot frozen at write
  time. **Both keep `total_amount == hours × rate`** because `PayrollService`
  splits every attendance row as `overtime = total − base` — a row with pay but no
  hours silently becomes OVERTIME on a payslip. Tests pin all of it.
- `approve()` refuses when ANY attendance exists in the span (a worker cannot be
  on site and on leave) and names the dates. That guard is also what makes
  `cancel()` safe to withdraw rows by status + range. PeriodLock is asserted for
  **every month the span touches**, not just the start.
- Pending days are held against the balance immediately, so two requests that each
  fit cannot both be approved when together they do not. `remaining()` is computed,
  never stored.
- **Vehicles**: `VehicleCompliance` grades insurance/ITV on the same traffic light
  as documents and **reuses `DocumentStatus::warnDays()`** — widening the window in
  Settings must widen it everywhere. `verto:scan-documents` sweeps the fleet on the
  same 90/60/30 + expiry-day schedule. A missing expiry is `neutral`, never `ok`.
  `maintenance_cost_total` is RECOMPUTED from the log (so deleting a record reduces
  it); an odometer never runs backwards; `assign()` closes the open history row
  before opening the next, and the edit form routes through it rather than bypassing
  tab 2. `employee_vehicle_assignments` is NOT a duplicate of `vehicle_history` —
  its `vehicle_id` is nullable because `type='own'` records a worker's own car.
- **Inventory**: `equipment_stock_movements` is the authority; `total_stock` /
  `available_stock` are its cached tail and are **not mass assignable**. total =
  owned, available = in the store, so `total − available` = out with workers. An
  adjustment sets the STORE and moves total by the same delta, so what is out with
  workers is not rewritten. `balance_after` is frozen per movement; the item row is
  locked for update so two concurrent issues of the last helmet cannot both succeed.
  Opening stock is a `stock_in` movement, not a column write.
- **Call Panel**: builds on the Phase 2 `employee_call_logs`. The indicator reads the
  SOONEST OUTSTANDING follow-up, not the latest call's (a newer call with no
  follow-up must not hide an older overdue one); "this week" means since the start of
  the working week, not a rolling 7 days.
- **Importers**: `LeavesImporter` reconstructs the user→employee link neither schema
  has, **by name**, and reports every row it cannot resolve to exactly one employee
  rather than guessing (DATA_MIGRATION.md §3.7 — the riskiest mapping in the
  migration; expect to resolve some by hand at cutover). Vehicles/inventory carry
  figures over verbatim; the stock ledger is NOT replayed (§3.9).

## Scaffolding decisions made in Phase 7 (not in DECISIONS.md)

32. Leave keys on `employee_id`. The legacy `user_id` design cannot feed attendance
    or payroll, which is the whole point of the module.
33. Leave books weekdays only. **There is no public-holiday calendar in the schema**,
    so a Spanish national/regional holiday inside a span still books a (zero-cost)
    cell — worth a `holidays` table later.
34. `total_days` is entered by hand (half days are real) but is validated against the
    weekdays the span actually covers, so a 2-day request cannot burn 20 days.
35. `ita_expiry_date` keeps the legacy name (ITV is the usual Spanish abbreviation —
    a likely legacy typo). The UI already labels it ITV; renaming the column is a
    one-line change once confirmed.
36. Plate numbers and SKUs are unique **per company**, not per group.
37. Leave/equipment categories follow the `ExpenseCategory` shape (NULL `company_id`
    = group-wide default), so they are not tenancy-scoped. The 8 leave categories are
    seeded as reference data (idempotent, safe in production).

## Open, in priority order

**Unpaid leave does not reduce a MONTHLY worker's salary** — the pro-rata divisor
(30 days vs the calendar month) is a real Spanish nómina choice DECISIONS.md does not
record, so it was flagged rather than invented. A clerk handles it through the payroll
screen's `other_deductions` until the client confirms. **Ask the client.**

Not built in Phase 7, though the plan lists them under Screen 26:

- **Settings → leave categories**: the 8 defaults are seeded and the API respects a
  per-company category, but there is no Settings UI to add or edit one yet. Inventory
  categories ARE manageable (inline on the Inventory screen, "Categorías" tab).
- **Settings → teams**: not started. Phase 1 scaffolding decision 11 deferred the teams
  tables to "the Settings→Teams section in a later phase" — they still do not exist,
  so this is a schema + UI pass, not just a screen.

Still open from Phase 6:

- ~~**Finance detail tabs**~~ — DONE (commit 7ac2e3a): employee Nómina, project
  Facturas/Gastos, client Facturas, vendor Gastos wired as read-only, permission-gated
  views (one `VFinanceRows` component). Pay data gated behind the wage right; a shared
  client/vendor shows only the acting company's rows. The standalone screens stay
  canonical (decision 23).
- **Settings sections** for advance categories, expense categories and company cards.
- ~~**`deployment_charges` → expense lines**~~ — DONE (commit 356f205): completing an
  Option A deployment now posts an `internal_deployment` expense on the HOST company
  (company_id set explicitly to the host, never the session), keyed off the new
  `deployment_charges.expense_id` so a re-run refreshes rather than double-charges.
  No vendor + no VAT per PAYROLL_DEPLOYMENTS.md decision 2 — the missing vendor is what
  keeps it out of vendor reports. There is deliberately NO home-side invoice: the client
  confirmed no inter-company VAT invoice; the receivable lives in the cross-company report.
- **Invoice reminders** are stored but not sent — sending lands in Phase 8 with the other
  scheduled mail.
- **Commission base** is the invoice TOTAL, not the amount collected
  (`CommissionService::baseFor()` — one line to change if the client wants otherwise).

Legacy dump validation (2026-07-17): **the live DB dump arrived and the importers
were validated against it** — `vertocrm-313539dae2.sql` (88 tables) restored locally
as `vertocrm_legacy`, a full `verto:import-legacy` run exercised, money reconciled to
the cent (attendance 333.692,44 € · expenses 39.685,80 € · payroll net 119.494,36 €).
It surfaced **6 real bugs 387 tests missed** — a dry-run framework flaw (each importer
rolled back its own transaction, so every dependent importer saw nothing), two enum
values that crashed the whole run (`expenses.payment_method='employee'`,
`attendance.wage_type='day'`), and three mapping bugs (payroll `payroll_month`,
expense `reimbursed`→paid, inventory item entity_type). All fixed + pinned
(`LegacyRealDataShapeTest`, `LegacyImportersTest`); DATA_MIGRATION.md §6 records it.
**Still outstanding**: `storage/app` upload set from the live server (document/photo
files) — but the dump has zero `documents` rows, so nothing to import there yet.
