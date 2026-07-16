# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status: Phase 5 complete (2026-07-16)

Development Phase 5 (cross-company employee deployments — the signature feature) is
built and verified:
**224 Pest tests / 814 assertions passing (1 skipped) · Pint clean · Larastan level 6
clean · `composer audit` clean · production Vite build working · deployment create flow
+ Option A cross-charge + host attendance-grid badge verified in the browser against
seeded MySQL.**

Live screen: **12 Deployments** — a host-company admin deploys an employee FROM another
company onto their own project (create modal: home company → cross-company employee
lookup → host project → dates/rate). Option A ONLY is automated (employee stays on the
HOME payroll; the HOST gets an automatic internal cross-charge — `DeploymentChargeService`
computes units × rate × split from the employee's hours logged on the host project).
Overlap guard blocks double-deployment. Deployed employees appear on the HOST attendance
grid with a "Desplegado/Deployed" badge + home-company label (browser-verified: 240,00 €
accrued from 16h). Option B (cesión ilegal) is refused at validation AND in the engine —
never automated (dev skill Rule 13). No legacy importer: the legacy system was
single-company, so there is nothing to migrate.

Next up: **Development Phase 6** (payroll & finance — gated on the Figma prototype review
the client requested before Phase 6). Earlier phases: Phase 0–4, design D1–D3 (approved
2026-07-14). Review findings 1–2 hardened in commit 31ede45.

## Project skills — read them first

Two mandatory skills live in `.claude/skills/` (committed to the repo):

- **`verto5-design`** — read before touching ANY UI file: tokens, typography,
  component rules, dark mode, mobile rules, the "what not to do" list.
- **`verto5-development`** — read before writing ANY PHP/Vue: tenancy, gates,
  audit, VAT enum, bilingual system, form requests, testing requirements.

## What this is

VertoCRM (brand: **Verto5**): a multi-company CRM for a Spanish construction group
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

Seeded local users (password `password`, local only): `admin@verto5.local` (Super
Admin), `empresa1.admin@verto5.local` (Company Admin), `empresa1.user@verto5.local`
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
  replacing the earlier spec-inferred list. Do not "tidy" these keys; see DECISIONS.md
  → "Document types". Renewal frequencies are still an OPEN assumption there.**
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

## Ready for Phase 6

Phase 6 (payroll & finance) is **gated on the Figma prototype review** the client asked
for before Phase 6 (Setup Answers). It will consume Phase 5 outputs: approved measurements
feed project billing, and `deployment_charges` become internal expense (host) + receivable
(home) lines, with Option A payroll note lines per `docs/PAYROLL_DEPLOYMENTS.md`.

External blocker (unchanged): legacy DB dump + `storage/app` from the live server
(DATA_MIGRATION.md §1) — needed to validate importers against real data. Not blocking UI.
