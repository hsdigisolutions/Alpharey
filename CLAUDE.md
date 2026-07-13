# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status: Phase 0 complete (2026-07-13)

Development Phase 0 (Foundations) from `docs/DEVELOPMENT_PLAN.md` is built and verified:
**55 Pest tests / 182 assertions passing · Pint clean · Larastan level 6 clean ·
`composer audit` clean · production Vite build working · migrations + seed run on MySQL.**

Next up: **Development Phase 1** (identity, companies, access control) — see "Ready for
Phase 1" at the bottom. Design phases D1–D3 (tokens, components, shell) should land
before Phase 1 screens are styled for real.

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

## Ready for Phase 1

Phase 1 (screens 01, 02, 04, 17, 25, 26 — see DEVELOPMENT_PLAN.md) builds directly on:

- **Auth flow**: session guard + `/login` placeholder exist; build login POST, remember
  me (30 days), password reset, rate limiting, session timeout from Settings, post-login
  routing by role
- **Companies**: model/table/factory ready; build CRUD + safety-checked removal +
  Welcome screen using `CurrentCompany::select()`
- **Permission Matrix**: engine and gates done — the screen only writes
  `user_module_permissions` rows (presets: full/read-only/none/copy-from-user)
- **Audit Logs screen**: data already flows; build filters/statistics/CSV export
  (read-only, no delete routes)
- **Settings screen**: `SettingsService` ready (general + SMTP sections + mail test)
- **Legacy importers**: register users/companies/settings importers in
  `config/legacy-import.php` (DATA_MIGRATION.md §3.1–3.2)

External blockers: legacy DB dump + `storage/app` copy from the live server
(DATA_MIGRATION.md §1) — needed for importer validation, not for Phase 1 UI; Figma
D1–D3 tokens/components for final styling.
