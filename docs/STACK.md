# VertoCRM — Technology Stack Recommendation

**Constraint:** Laravel backend (client decision). Must run cleanly on a managed cPanel VPS
(Mukhost, cp.mukhost.uk). No Docker, no AWS, no expensive infrastructure. Easy to maintain
long term by any competent Laravel developer.

---

## The Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | Laravel (latest stable at kickoff, 12.x+), PHP 8.3+ | Client decision; mature, batteries included |
| Frontend | **Vue 3 + Inertia.js** (client decision 2026-07-13, replaces the earlier Livewire recommendation) | The old system already ran Vue 3 successfully on this exact hosting. Inertia bridges Laravel and Vue with no separate API, no CORS, no token auth — session-based, single codebase, SPA-smooth UI. Deploys as plain PHP + prebuilt assets on cPanel. **No SSR** (no Node on the server) — assets built in CI. |
| CSS | Tailwind CSS v4 | Design tokens map directly to a Tailwind config; dark mode built in |
| Database | MySQL 8 / MariaDB (whatever the cPanel VPS provides) | Zero extra cost, fully supported by Laravel |
| Cache / Sessions / Queue | **Database drivers** (Laravel defaults) | No Redis required. At this scale (5 companies, tens of users) the database handles all three comfortably. |
| Queue worker | Cron: `php artisan schedule:run` every minute; queue processed via `queue:work --stop-when-empty` on the scheduler | Works on any cPanel host — no supervisor daemon needed |
| File storage | Local disk under `storage/app/private` (outside webroot) | Private by construction; downloads only through permission-checked streamed responses. No S3 cost. |
| PDF generation | `barryvdh/laravel-dompdf` | Pure PHP — payslips, invoices, proposals, report exports. No headless Chrome (won't run reliably on cPanel). |
| Excel import/export | `maatwebsite/excel` (PhpSpreadsheet) | Employee/attendance imports with templates; all list exports |
| Charts | Chart.js, bundled at build time (not CDN) | Dashboard bar/donut/line charts; CSP-friendly |
| Audit trail | Custom observer-based logger (own `audit_logs` table per spec: IP, user agent, method, old/new values) | The spec's audit table is richer than off-the-shelf packages; observers give full coverage automatically |
| Permissions | Custom module-permission layer on `user_module_permissions` (per spec) + Laravel Policies/Gates | The spec defines the exact model; generic packages don't fit the per-user per-module per-action matrix |
| Assets | Vite — **built in CI or locally, compiled assets deployed** | Node never runs on the server |
| Testing | Pest + Larastan (static analysis) + Pint (code style) | Tenancy-isolation and permission tests are non-negotiable for this project |
| Backups | `spatie/laravel-backup` (DB + files, shipped off-server) + cPanel backups | Two independent backup paths |
| Deployment | Git (GitHub) → GitHub Actions (tests + asset build) → SSH/rsync to VPS → `migrate --force` | Repeatable, no manual FTP. Staging subdomain + production. |

## Why Vue 3 + Inertia (client decision, 2026-07-13)

- The legacy system ran Vue 3 on this exact hosting successfully — proven combination.
- Inertia keeps one codebase with **no separate API**: controllers return Inertia pages,
  auth stays session-based, validation stays in Form Requests, no CORS, no token juggling.
- SPA-smooth navigation without page reloads; Vue's ecosystem for the calendar grids,
  kanban, and permission matrix ahead.
- Easier path to a future mobile app.
- **No SSR**: there is no Node on the server, so Inertia runs client-side only; Vite
  assets are built in CI and deployed as static files. Deploys as plain PHP on cPanel.
- Trade-off accepted: server-side authorization now pairs with client-side page props —
  every prop payload is filtered server-side (see SECURITY.md); UI hiding is never the
  control.

## Mukhost verification — CONFIRMED by client 2026-07-13

1. ✅ PHP 8.3 available and will be set on the account
2. ✅ SSH access working (previously used for deployment)
3. ✅ GitHub deploy working (previously used)
4. ✅ MySQL: unlimited databases
5. ✅ Disk: unlimited (70% usage alert still ships — DECISIONS.md)
6. ✅ **Datacenter: London, UK** — GDPR-acceptable under the EU adequacy decision for
   the UK; recorded as the confirmed hosting location (DECISIONS.md)
7. ✅ SMTP: `smtp.alpharey.com` available (SPF/DKIM to be configured; monitor
   deliverability in Phase 2)
8. Still to check during first deploy (minor): required PHP extensions enabled
   (intl, gd, zip, pdo_mysql), cron at 1-minute intervals, document root → `public/`,
   AutoSSL active
