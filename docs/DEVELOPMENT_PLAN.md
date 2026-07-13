# VertoCRM — Development Plan

Ten phases, 0–9. Each phase lists what gets built, the screens and database tables it
covers, and what is delivered at the end. Every phase ends with a deploy to staging for
client review. Nothing ships to a later phase silently — if scope moves, it moves by
agreement.

**Cross-cutting rules applied in every phase (built once in Phase 0, enforced always):**

- Bilingual labels (Spanish primary / English secondary) on every UI element — no
  hard-coded strings, everything through the translation layer from the first screen
- Company scoping via global scope on every company-owned model
- Module permissions checked server-side on every action
- Audit logging on every model
- Pest feature tests per module — including tenancy-isolation and permission tests
- Create/edit always in modals or slide panels; dark/light mode; soft deletes for
  employees and clients

**Prerequisites status (2026-07-12):** the Section 15 answers and payroll decisions are
received and logged in `DECISIONS.md`. The one outstanding external input is the legacy
database dump + `storage/app` files from the live alpharey.com server
(`DATA_MIGRATION.md` §1). Design phases D1–D3 (tokens, components, shell) must land
before Phase 1 screens are built.

**Legacy data migration** runs as a parallel workstream: importer framework in Phase 0,
then one importer per module built in the same phase as the module itself, and a final
delta import at cutover — schedule and full mapping in `DATA_MIGRATION.md`.

Indicative durations assume one experienced full-time Laravel developer; two developers
roughly halve the calendar time from Phase 2 onward. Treat them as planning figures, not
promises.

---

## Phase 0 — Foundations (≈ 1.5–2 weeks)

**Infrastructure**
- Git repository, branching model, CI pipeline (tests, Larastan, Pint, `composer audit`,
  Vite asset build)
- Laravel + Vue 3/Inertia + Tailwind + Pest skeleton (stack change 2026-07-13, DECISIONS.md)
- Mukhost verification checklist from `STACK.md` (PHP version, SSH, cron, doc root,
  AutoSSL, datacenter location) — resolved with the host
- Staging subdomain and production environments; automated deploy (build → rsync →
  `migrate --force` → cache warm); HTTPS enforced on both
- Database/session/cache/queue on database drivers; scheduler cron installed
- Legacy data: obtain live DB dump + `storage/app` copy (DATA_MIGRATION.md §1); local
  read-only replica; importer framework + `legacy_id_map` table

**Application plumbing (the things every later phase depends on)**
- Bilingual label system: es/en translation files + `<x-bilingual>` Blade component
  (Spanish primary, English secondary, muted)
- `BelongsToCompany` trait + global scope + current-company resolver (session-based)
- Audit logging engine: model observers + request context (IP, user agent, method)
  writing to `audit_logs`
- Module-permission engine: `user_module_permissions` table, Gate integration,
  `authorize()` helpers for controllers and Inertia page props
- Settings service backed by `settings` table
- App shell from design system: sidebar (10 items), header, mobile bottom nav,
  dark/light toggle
- Security headers middleware, forced HTTPS, error pages

**Tables:** users, sessions, password_reset_tokens, cache, jobs, failed_jobs,
personal_access_tokens, settings, audit_logs, user_module_permissions (structure),
brands, companies (structure)

**Delivered:** a deployable, CI-tested skeleton on staging — login placeholder, app shell,
dark mode, and passing tests proving company scoping, permission gating, and audit logging
work. This is the foundation everything else stands on.

---

## Phase 1 — Identity, companies, and access control (≈ 2–3 weeks)

**Screens:** 01 Login · 02 Welcome/Company Selector · 04 Companies (Información +
Estadísticas tabs) · 17 Permission Matrix · 25 Audit Logs · 26 Settings (General + Email
sections)

**Built**
- Full authentication: login, remember me (30 days), password reset by email, session
  timeout (configurable, default 120 min), rate limiting, post-login routing by role
- Roles: Super Admin / Company Admin / Custom User; user management per company
- Company CRUD: create, edit inline, deactivate; **remove-company flow with safety
  checks** (blocks removal while active employees/projects/unpaid invoices exist;
  typed-confirmation step; fully audited)
- Super Admin Welcome screen with company cards + group-wide stats bar (deployment
  counters appear as zeros until Phase 5)
- Permission Matrix screen: user list, per-module × per-action toggle grid, quick presets
  (Full / Read Only / No Access / Copy from user), immediate effect
- Audit Logs screen: filters (action/module/user/date), statistics panel, CSV export,
  no edit/delete capability anywhere
- Settings: app name, language default, timezone (Europe/Madrid), session timeout,
  SMTP configuration + mail test. (No default-VAT setting — VAT is optional system-wide
  and blank by default per DECISIONS.md)

**Tables:** roles, permissions, role_permission, user_role, user_module_permissions
(live), teams, team_user, team_permission

**Delivered:** a working multi-company system — Super Admin creates companies and Company
Admins; Company Admins create users and set granular permissions; every action audited.
Tenancy-isolation test suite established as the template for all later modules.

---

## Phase 2 — Employees and document management (≈ 3–4 weeks)

**Screens:** 05 Employees List · 06 Employee Detail (Tab 1 Información, Tab 2 Documentos,
Tab 5 Notas, Tab 6 Llamadas) · 04 Companies Documentos tab · 16 Compliance Center

**Built**
- **The standard table framework** (built once here, reused by every list screen after):
  sort any column, multi-filter, live search, column visibility saved per user
  (`user_column_settings`), bulk select + actions, 25/50/100 pagination, export
  filtered view to Excel and PDF, inline editing (Enter/Escape)
- **The modal / slide-panel create-edit framework**
- Employee CRUD: full Información tab (personal, employment, wage, overtime policy links,
  bank — IBAN/NIF encrypted with blind index for search), auto-generated employee codes,
  Excel import with downloadable template, salary/wage-rate history recording
- **Documents engine** (polymorphic `documents` table — the single system used by
  employees, companies, projects, vehicles): upload (drag-drop; camera on mobile),
  versioning, issue/expiry dates, traffic-light status, Yes/No confirmation fields,
  custom document slots, private storage, permission-checked download, delete (audited)
- Employee document sets: personal / employment / training / medical / custom, per spec
- The 13 official Spanish company document types, seeded with frequencies; company
  Documentos tab with per-row status, versioning, notes
- Compliance Center: summary cards (valid / 30 / 60 / 90 / expired / missing),
  per-company score bars, filterable detail table, upload-renewal / send-reminder /
  mark-exempt actions
- **Notifications core**: bell + unread count, notification storage, email channel;
  scheduler jobs per the confirmed schedules (DECISIONS.md) — annual/event documents at
  90/60/30 days before expiry + critical alert on the expiry date; monthly documents at
  5 and 2 days before month-end + overdue alert on the 1st, sent to the company's
  Company Admin with a cross-company overdue summary to the Super Admin; Settings →
  document alert thresholds
- Employee notes timeline (general/reminder/issue/call, attachments) and call log tab
- Custom fields + dropdown options engines (Settings sections)

**Tables:** employees, employee_salary_history, employee_wage_rates, employee_notes,
employee_nicknames, employee_call_logs, documents, custom_fields, custom_field_values,
dropdown_seeder_options, user_column_settings, notifications (core)

**Delivered:** complete employee management and the full document/compliance system with
automatic expiry alerts — the heart of the compliance requirement, live and reviewable.

---

## Phase 3 — Clients, projects, vendors, proposals (≈ 3–4 weeks)

**Screens:** 07 Clients (all 6 tabs) · 08 Projects List (table + kanban) · 09 Project
Detail (Tab 1 Resumen, Tab 2 own workers, Tab 7 Documentos, Tab 8 Notas y Comunicación)
· 20 Vendors (all 4 tabs) · 18 Proposals

**Built**
- Clients: shared pool (explicitly unscoped), full Información, multiple contacts,
  communication timeline; Proyectos/Facturas/Propuestas tabs (populate as their modules
  land)
- Projects: full CRUD, company assignment + badge, types/status/priority/billing type,
  kanban with 5 status columns, project team roles (jefe de obra, encargado, seguridad,
  supervisors, coordinator), invoice rules fields, color code, Drive/document links
- Project workers: assign own-company employees, per-project rate overrides
  (`project_employee_rates`)
- Project Resumen: contact cards, budget fields, hours/meters placeholders (light up in
  Phases 4/6), outsourced flag
- Project documents tab (reuses documents engine); notes/communication timeline —
  **notes immutable once saved** (audit requirement)
- Project alerts: budget/deadline/progress/custom, scheduled emails with recipients/CC,
  pending/sent/failed status
- Vendors: full CRUD, contacts, payment terms, expenses tab (populates in Phase 6)
- Proposals: line items, VAT, estimated vs total, Draft/Sent/Approved/Rejected lifecycle,
  PDF export

**Tables:** clients, client_contacts, projects, project_employee_rates, alerts,
report_remarks, vendors, vendor_contacts, vendor_payment_terms, proposals

**Delivered:** the full commercial structure — shared clients, company-owned projects
with teams and alerts, vendors, and quotations with PDFs.

---

## Phase 4 — Attendance and measurements (≈ 2.5–3 weeks)

**Screens:** 11 Attendance (calendar grid) · 24 Measurements · 06 Tab 3 Asistencia ·
09 Tab 3 Asistencia + Tab 4 Mediciones · 26 Settings (overtime policies section)

**Built**
- Attendance calendar grid: employees × days, status dots + hours + project per cell,
  color coding (present/late/absent/leave/weekend), click-to-edit modal, month/year
  picker, company filter
- Entry modal with the full field set: hourly mode (check-in/out, auto-calculated hours)
  and project-based mode (manual hours), break handling with deduct toggle, overtime
  override, status, **wage/rate snapshots frozen at entry time**, manual wage override,
  is-paid / is-exception + reason, work mode, notes
- Overtime policies engine: percentage / fixed hourly / accumulate days / none; daily
  threshold; per-employee policy + separate supervisor policy
- Excel bulk import with template; monthly summary table (days, hours, OT, absences,
  leave, total wage)
- Employee detail attendance tab (calendar + monthly summary); project attendance tab
  (date-range table)
- Measurements: entry (quantity, unit, type), approve/reject workflow, approved
  measurements flagged as billable inputs; project Mediciones tab
- One-tap mobile check-in/check-out
- Basic production task tables in place for task-based tracking data
  (production_tasks/task_progress/task_templates), surfaced through project views

**Tables:** attendance, attendance_logs, measurements, overtime_policies,
production_tasks, task_progress, task_templates, nickname_reconciliations

**Delivered:** complete attendance capture in both modes with correct wage snapshots and
OT calculation, plus the measurement approval pipeline — the raw material payroll needs.

---

## Phase 5 — Cross-company deployments (≈ 2 weeks)

**Screens:** deployment actions inside 09 (Tab 2 deployed-workers block + "Deploy from
another company" flow) · deployment badges in 11 Attendance · deployment KPIs on 02
Welcome · deployment reports (surfaced fully in Phase 8)

**Built**
- `employee_deployments` full lifecycle: create (employee, home/host company, project,
  dates, rate + rate type, billing method, split %, approver, notes) → active →
  completed/cancelled; open-ended deployments supported
- Overlap guards (an employee can't be deployed twice for the same dates) and
  home-company availability warnings
- Project Tab 2: own workers and deployed workers as separate blocks; deployed rows show
  home-company badge, dates, rate, billing method, hours, cost
- Attendance integration: deployed employees appear in the host grid with home badge and
  "Desplegado" indicator; hours log against the host project
- **Cross-charge engine per the agreed model** (`PAYROLL_DEPLOYMENTS.md` — Option A with
  split %): auto-generated internal expense on host company, receivable summary for home
  company, payroll note lines prepared for Phase 6
- Deployment notifications (created / ending / ended)
- Report data: deployment history, cross-company cost summary, active deployments

**Tables:** employee_deployments (+ generated internal expense records)

**Delivered:** the signature feature working end to end — deploy, track, badge, and
auto-charge — deliberately landed *before* payroll so Phase 6 consumes it natively.

---

## Phase 6 — Payroll and finance (≈ 4–5 weeks — the heaviest phase)

**Gate:** starts only after the client has reviewed and approved the Figma clickable
prototype (design phase D9) — confirmed requirement in `DECISIONS.md`.

**Screens:** 12 Payroll · 10 Invoices (both tabs + detail panel) · 19 Commission Reports
· 06 Tab 4 Nómina · 09 Tab 5 Facturas + Tab 6 Gastos · 26 Settings (advance categories,
locked periods, company cards)

**Built — payroll**
- Calculation engine: monthly run per company from attendance snapshots, supporting all
  wage types (hourly / daily / monthly / per-meter via approved measurements), overtime
  pay per policy, reimbursements, **worker project expenses** pulled into the month,
  advance deductions, other deductions, manual additions → gross → net
- Deployed employees handled per billing method: note lines on home payroll, cross-charge
  already generated by Phase 5; (host-pays exclusion logic only if the client insists on
  Option B against recommendation)
- Workflow: Calculate → review per-employee breakdown modal (exact layout from spec) →
  edit manual adjustments → Approve All → mark paid (per payment method) → **Lock Period**
  (locked months reject any attendance/payroll edits system-wide)
- Payslip PDFs (per employee + bulk export), Excel export
- Salary advances: request/approve/reject/deduct lifecycle, 7 seeded categories,
  payroll-month linkage
- Employee Nómina tab: 6-month history + breakdown popup + payslip download

**Built — invoicing and expenses**
- Invoices: Sale and Expense types on one screen, pre/final sub-types, line items,
  optional VAT (blank by default — no 21% default, per DECISIONS.md), discount
  (percent/fixed), retention %, totals engine, Draft/Sent/
  Paid + Unpaid/Partial/Paid payment status, payment records, PDF generation,
  per-project invoice reminders with schedules and sent-tracking
- Expenses: albarán/factura/ticket/other, categories, line items, vendor + project +
  employee links, company-card payment method, file attachment, approval status
- Commission engine: per employee × project × invoice entries, original vs adjusted with
  reason, finalize (locks entry, confirmation required), mark paid, PDF/Excel export
- Client Facturas tab, project Facturas/Gastos tabs, vendor Gastos tab now live

**Tables:** payrolls, advances, advance_categories, locked_periods, expenses,
expense_categories, expense_line_items, invoices, payments, invoice_reminders,
commission_report_entries, invoice_settlement_works, employee_settlements, company_cards

**Delivered:** the complete money flow — attendance → payroll → payslips; projects →
invoices → payments; expenses and commissions — with period locking and full audit.

---

## Phase 7 — Operations modules (≈ 3 weeks)

**Screens:** 13 Call Panel · 22 Leave Management · 21 Vehicles (all 4 tabs) ·
23 Inventory · 26 Settings (leave categories, teams)

**Built**
- Call Panel: two-column layout, employee list with filter tabs (all / pending
  follow-up / not contacted this week) and red/amber/green indicators, call history,
  always-visible log form, click-to-call on mobile, top stats bar, follow-up
  notifications
- Leave: request → approve/reject/cancel with review notes and attachments, 8 seeded
  categories, balances (allocated/used/pending/carried over/remaining), balance
  adjustment, year handling; **leave feeds attendance (blue cells) and payroll**
- Vehicles: fleet CRUD (ownership, fuel, VIN, insurance/ITV expiries, service and tyre
  tracking), assignment history, maintenance history, mileage log; **insurance/ITV
  expiries flow into the documents/compliance alert system**
- Inventory: categories, items (safety/tool/machine, SKU, stock levels), stock movements
  (in/issue/return/adjustment/damaged) with running balance, employee issues with
  expected-return tracking, project assignments

**Tables:** leaves, leave_balances, leave_categories, vehicles, vehicle_history,
vehicle_maintenance_histories, vehicle_mileage_histories, employee_vehicle_assignments,
equipment_categories, equipment_items, equipment_stock_movements,
employee_equipment_issues, equipment_project_assignments

**Delivered:** all supporting operational modules; every entity that can expire now feeds
the compliance system.

---

## Phase 8 — Dashboards, reports, search, notifications (≈ 2.5–3 weeks)

**Screens:** 03 Dashboard · 14 Reports · 15 Today's Report · global search ·
notification rules in 26 Settings

**Built**
- Dashboard: 8 KPI cards (both rows), 3 charts (revenue vs expenses 6-month bar, project
  status donut, 30-day attendance line), quick actions, documents-expiring panel, recent
  activity feed — all per selected company, cached sensibly
- Reports page: single page, persistent filter bar (company/module/date range), all ten
  report modules — Employees, Attendance, Payroll, Financial, Documents, Projects,
  Commission, Timesheet, **Cross-Company Deployments**, Today's link — each with its
  spec'd figures, tables, charts, and PDF/Excel export of the filtered view
- Today's Report: 6 KPI cards, live attendance table (with home-company column for
  deployed workers), pending-actions panel, 5-minute auto-refresh
- Global search: header bar searching all 9 entity types simultaneously, grouped
  dropdown results, direct navigation, permission- and company-scoped
- Notification rules: per-role enable/disable matrix for all notification types;
  remaining types wired (payroll ready, invoice overdue, advance/leave pending,
  project alerts, deployment events)
- System health panel in Settings: queue status, mail test, DB, storage check

**Tables:** notification_role_rules (notifications completed)

**Delivered:** every screen 01–26 built. Feature-complete system on staging.

---

## Phase 9 — Hardening, UAT, and launch (≈ 2.5–3 weeks)

**Built / done**
- **Responsive pass** over all 26 screens against the requirements' mobile rules: bottom
  nav, horizontal-scroll tables, full-screen modals, simplified calendar day view,
  charts→numbers, camera capture, one-tap check-in
- **Performance pass**: query audit (N+1 elimination, eager loading), index review
  against real data volumes, dashboard/report caching, pagination limits
- **Security gate** (the 7-point checklist in `SECURITY.md` §9) — release blocks until
  green; fix everything found: zero known vulnerabilities at go-live
- Final legacy data cutover per `DATA_MIGRATION.md` §4: freeze old system on
  alpharey.com → final dump + files sync → delta import → validation suite → client
  spot-checks; real company names/CIFs/logos entered when provided (dummy names until
  then — all editable from Settings, no code change)
- Backup + monitoring in production: nightly off-server backups, restore drill, uptime
  and error alerting
- **UAT on staging** with the client: structured test scripts per module, two fix
  rounds budgeted
- User guide (bilingual, screenshot-based) and admin handbook (permissions, locked
  periods, company removal, backups)
- Go-live runbook for alpharey.com: document-root/DNS switch, HTTPS verification,
  rollback plan (old system restorable from the retained archive); hypercare window
  (2 weeks of priority fixes post-launch)

**Delivered:** production go-live, trained users, documented system, tested backups.

---

## Summary table

| Phase | Focus | Screens completed | Indicative duration |
|---|---|---|---|
| 0 | Foundations & infrastructure | shell | 1.5–2 wk |
| 1 | Identity, companies, permissions | 01, 02, 04*, 17, 25, 26* | 2–3 wk |
| 2 | Employees & documents | 05, 06*, 16, 04 docs | 3–4 wk |
| 3 | Clients, projects, vendors, proposals | 07, 08, 09*, 18, 20 | 3–4 wk |
| 4 | Attendance & measurements | 11, 24, tabs | 2.5–3 wk |
| 5 | Cross-company deployments | deployment feature set | 2 wk |
| 6 | Payroll & finance | 10, 12, 19, tabs | 4–5 wk |
| 7 | Operations | 13, 21, 22, 23 | 3 wk |
| 8 | Dashboards, reports, search | 03, 14, 15, search | 2.5–3 wk |
| 9 | Hardening, UAT, launch | all, mobile + security | 2.5–3 wk |

\* = partially in that phase, completed in later phases as dependent modules land.

Total: ≈ 26–32 weeks with one senior developer full-time; ≈ 15–19 weeks with two.
Design (see `DESIGN_PLAN.md`) runs ahead of development in parallel and does not add
calendar time after Phase 1.
