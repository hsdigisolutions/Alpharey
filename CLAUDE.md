# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status: Phase 1 complete (2026-07-14)

Development Phase 1 (identity, companies, access control) is built and verified:
**115 Pest tests / 429 assertions passing · Pint clean · Larastan level 6 clean ·
`composer audit` clean · production Vite build working · full login→welcome→matrix
flow verified in the browser against seeded MySQL.**

Live screens: **01 Login** (throttled, remember-me 30d, password reset, inactive
lockout, routing by role) · **02 Welcome/Company Selector** (SA) · **04 Companies**
(CRUD + typed-name removal with safety checks) · **17 Permission Matrix** (presets,
copy-from, user management, immediate effect) · **25 Audit Logs** (filters, stats,
CSV export) · **26 Settings** (General + SMTP with encrypted password + mail test).
Legacy importers registered: users, settings (validated against an in-memory legacy
stand-in — the live dump is still pending, DATA_MIGRATION.md §1).

Design D1–D3 were approved by the client 2026-07-14 (styleguide at `/styleguide`,
non-production). Next up: **Development Phase 2** (employees & document management) —
see "Ready for Phase 2" at the bottom.

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

## Ready for Phase 2

Phase 2 (screens 05, 06, 16 + documents engine — see DEVELOPMENT_PLAN.md) builds on:

- **Employees table**: use `BelongsToCompany` + `Auditable`; encrypted casts + blind
  index for NIF/IBAN/salary land here (dev skill Rule 8); `TenancyTest` is the template
- **Standard table framework**: VTable/VTableToolbar/VPagination/VBulkBar are
  presentational — Phase 2 adds server-driven sort/filter/live-search/column
  visibility (`user_column_settings`), bulk actions, Excel/PDF export (install
  maatwebsite/excel + dompdf here)
- **Documents engine**: polymorphic `documents` table, private storage +
  permission-checked downloads (dev skill Rule 10), versioning, expiry traffic lights;
  `VFileDrop` supports camera capture already
- **Compliance Center** feeds off document statuses; 13 company doc types seeded
- **Notifications core**: bell UI placeholder exists in the shell; build storage +
  email channel + the confirmed alert schedules (DECISIONS.md: monthly 5/2 days before
  EOM + overdue on the 1st; annual 90/60/30 + expiry-day)
- **Importers**: register employees/wage/documents importers following the
  UsersImporter pattern

External blocker: legacy DB dump + `storage/app` copy from the live server
(DATA_MIGRATION.md §1) — needed to validate Phase 2 importers against real data.
