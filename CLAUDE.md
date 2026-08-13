# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Status: Phase 9 in progress — hardening (2026-08-01)

### Salary structure build (2026-08-12, in progress — spec confirmed by client)

Building the confirmed salary-structure spec in 8 ordered increments. **Fix 1
(done):** `project_designation_rates.worker_rate` is OUT of the pay path — the
old `AttendanceService::applyProjectDesignationRate()` (which froze the project
worker_rate over the profile rate and rewrote day_type) is deleted; every day is
priced from `WageRateService::ratesForDate` (wage history → profile fallback).
worker_rate is now REFERENCE ONLY ('Solo referencia — no afecta al salario' hint
on the Project rates card); designation client_rate still drives daily-P&L
income. `ProfitabilityService::unitWorkerRate` now reports the real frozen
snapshot rate, never worker_rate, so displayed rate = summed cost. Cutover per
client: rows frozen under the old rule keep their historical totals (history is
never rewritten); only new/repriced-unpaid rows use the corrected logic.
DesignationRateTest inverted (acceptance test 6: profile 50 beats worker_rate
80); ProfitabilityDailyTest now pins acceptance test 7 (income 600 from client
rates, cost 160 from profile rates).

**Fixes 4+5+8 (done):** (4) partial-day fallback — a daily worker with no
hourly rate on a partial (hourly-graded) day prices at `daily ÷ 8 × hours`,
never 0 € (`applySnapshots` derives `$partialHourly`). (5) Tarifa — the payroll
row's `wage_rate` now stores the rate matching the worker's OWN wage type
(daily→daily_wage, monthly→base_salary, per-meter→per_meter_rate) instead of
the hourly column (null for those types after wage-history sync → showed 0);
same fix on the Employees list payload, Excel export and PDF via a new
`Employee::displayRate()` (display-only — pricing still goes through
WageRateService). (8) zero-rate validation — the four employee-form rate fields
are `gt:0` when filled (acceptance test 11); empty stays allowed; wage-history
already rejected 0.

**Every screen 01–26 is built (Phase 8 complete).** Phase 9 is hardening, UAT, and
launch — no new screens. Current: **781 Pest tests / 4526 assertions passing (1
skipped) · Pint clean · Larastan level 6 clean · `composer audit` + `npm audit`
clean · production Vite build working.**

### Expenses & Invoices parity + Measurements audit (2026-08-12)

Brought the new Expenses/Invoices modules up to (and past) the legacy VertoCRM
reference, after a code + live-staging audit of the old system. Six committed
increments (all gate-green; **792 Pest tests / 4588 assertions, 1 skipped**):

- **Invoice PDF** now renders the issuing company's **logo** (base64-embedded
  when `companies.logo_path` is set — DomPDF can't fetch remote assets) and the
  full **address** block beside the CIF. *Open follow-up: no UI uploads a company
  logo yet — a Settings company-logo upload would make the logo actually appear.*
- **Admin expense receipt** made usable end to end: a file field on the form
  (the `storeAttachment` backend was already wired) + a gated, audited download
  route (`GET /expenses/{expense}/receipt`) + a per-row link (Rule 10 pattern).
- **Expense list filters** expanded to match the legacy: search + Type +
  Category + Payment-status (via a shared `filteredQuery`).
- **Expense categories CRUD** — `ExpenseCategoryController` (store/update/destroy,
  gated `expenses.edit`, tenancy-aware: own rows only, group defaults read-only;
  a referenced category deactivates instead of deleting). Managed inline on the
  Gastos screen ("Categories" modal). `ExpenseCategory::scopeForCompany`.
- **"Bearable By"** (`App\Enums\BearableBy`: company/client/employee/unbillable,
  migration `2026_08_12_000001`) replaces the plain reimbursable checkbox. The
  controller derives `is_reimbursable` from the bearer; a `deduct_from_salary`
  flag on an employee-borne cost comes OFF payroll in a new encrypted
  `payrolls.expense_deductions` bucket (`PayrollService::expenseSalaryDeductionsFor`,
  shown on the payslip + breakdown). Only CLIENT-bearable costs feed invoicing.
- **Expense Excel + PDF export** (`ExpensesExport` + `exports.expenses-pdf`) of
  the filtered view, gated `expenses.export` + audited; routes registered before
  `/expenses/{param}` (decision 30).
- **Invoice project auto-calc** (legacy "Method 2") — `GET /invoices/project-costs`
  JSON endpoint with an admin-chosen basis: **costs** (labour + client-borne
  approved expenses, itemised, × margin), **subtotal** (one line), or **meter**
  (approved measured metres × the project's client meter rate). A "Calculate from
  project" panel in the invoice slide-over prefills the editable line items;
  InvoiceTotals still re-derives on save.
- **Invoice reminders** wired at last — `NotificationType::InvoiceReminder`
  (→ Company Admin) + a `notifications:scan` sweep that flags projects worked
  this month but not yet invoiced, using the dormant `invoice_reminders` table as
  the cadence guard (`reminder_days`, 0/null treated as 7).

**Deep-review pass (2026-08-12).** A full re-review of the six increments caught
one real defect: `PayrollService::projectExpensesFor` reimbursed any approved
employee+project expense regardless of `bearable_by`, so a CLIENT-borne project
expense would be double-paid (reimbursed to the worker AND billed to the client).
Fixed — it now requires `is_reimbursable` (symmetric with `reimbursementsFor`);
pinned by a "no double-pay" test. Everything else verified working; added Method 1
/ Method 3 / cross-company tests for the invoice auto-calc and a cross-company
receipt-download test. **797 Pest tests / 4599 assertions, 1 skipped.** Note:
`GET /invoices/project-costs` rejects another company's project with a 422
(OwnCompanyProject rule) rather than a 404 — the input-validation convention used
across the finance controllers; the security property (never acts on another
company's project) holds either way.

**Measurements — investigated, REPORTED ONLY (client decision, not yet fixed).**
The audit found production tasks / task templates / daily-production exist only as
three empty Phase-4 tables (`production_tasks`, `task_templates`, `task_progress`)
with zero code, and — the real gap — **approved measurements do NOT feed billing
or P&L**: `ProfitabilityService` computes per-meter revenue from **Attendance**
(`day_type=per_meter`), never from the `measurements` table, despite docblocks
claiming otherwise. No measurement export; no reject state (boolean approve only);
no task categories/weightage. The client chose "report only" for now — building
the production-task subsystem + wiring measurements→billing is a future module.

### Worker privacy CONSENT — full legal-evidence system (2026-08-11)

Reworked the worker "privacy notice" from a single acknowledgement (a timestamp +
version on the employee row) into a proper **consent-as-evidence** system (GDPR
art. 7 & 13, LOPDGDD 3/2018, RD-ley 8/2019). The legal model is now **hybrid**:
the attendance time record is a legal obligation (a mandatory *acknowledgement*,
not refusable) while **GPS and the selfie are OPTIONAL consents** — the app works
without them, so consent is a valid basis (no detriment) and is freely revocable.

**Evidence table** `worker_consents` (migration `2026_08_11_000002`), **append-only**
— a change/revocation writes a new row and stamps `revoked_at` on the superseded
one, never destroying history. Columns: employee/user/company id, `consent_version`
(e.g. `v1.0-2026-08`), `ip_address`, `user_agent`, `consented_at`, `timezone`,
`consent_attendance`/`consent_gps`/`consent_photo`, `consent_text_shown` (the FULL
exact text, snapshotted server-side by `WorkerPrivacyNotice::canonicalText()`),
`language`, `revoked_at`, `revoked_reason`. `WorkerConsentService` is the single
writer (`record` / `updatePreferences` / `revoke`); `activeConsent` = latest,
non-revoked, current-version row. `WorkerConsent` is `Auditable`.

**Worker PWA:** the consent screen (`PrivacyNotice.vue`) now has THREE checkboxes
(attendance mandatory · GPS optional · selfie optional) and the Accept button is
disabled until the worker scrolls the whole notice AND ticks the mandatory box.
Check-in respects consent — no GPS consent → no fix requested, `location_captured
=false`, no GPS-missing alert; no selfie consent → no camera / no selfie. A
"Privacy & consent" panel on the home lets the worker revoke GPS/selfie any time
(`POST /worker/consent`). `hasAcknowledgedPrivacyNotice()` + `consentGps()` /
`consentPhoto()` on Employee delegate to the service.

**Admin:** Employee Detail shows a consent card (status, date/time, IP, device,
ticked boxes, version, full history) with a **per-record PDF** (`exports.worker-consent`
blade, gated + audited) and a **Reset** button (`POST …/consent/reset`) to force
re-acceptance. **Settings → Legal** holds the current version (`legal.consent_version`,
`PUT /admin/settings/legal`); bumping it re-gates every worker. The old
`employees.privacy_notice_ack_*` columns are now legacy (left in place; the consent
table is authoritative). Tests: `WorkerPrivacyNoticeTest` (rewritten, 10 — full
record, mandatory-box, GPS/selfie skip, revoke, version re-gate) +
`WorkerConsentAdminTest` (5 — admin view, reset, PDF, cross-employee 404, version
bump). `EmployeeFactory::privacyAcknowledged(gps,photo)` now seeds a consent row.

### GPS check-out mismatch — accuracy guard + configurable distance (2026-08-11)

Investigated a report of the >500 m "check-out far from check-in" alert firing
spuriously. **The distance logic was already correct** — it compares check-in vs
check-out (Option A), the threshold was 500 m (not 5 m), and `haversine()` returns
metres. **The real bug: the reference point.** A check-in fix with ±50 000 m
accuracy (an IP-based / mock location, not real GPS) was being used as the anchor,
so any real check-out read as >500 m away.

Fixes: **(1) Accuracy guard** — `WorkerAttendanceService::applyMismatch` now skips
the comparison entirely when the check-in accuracy is missing OR worse than
`LOCATION_ACCURACY_LIMIT` (1000 m). An untrustworthy anchor never raises the alert.
**(2) Configurable distance** — the 500 m threshold is now a per-company Setting
(`attendance.max_location_distance.{id}`, default 500, read via
`AttendanceService::maxLocationDistance()`, edited on Screen 26; `PUT
/admin/settings/attendance` validates it). **(3) Genuine-mismatch alert** — when a
TRUSTWORTHY check-in is truly beyond the limit, `NotificationType::
WorkerLocationMismatch` (→ Admin + Manager) fires with the metres in the body; the
worker sees a `warning` flash ("Your check-out location is far…"). A poor-accuracy
check-in also flashes a "GPS signal weak" warning (the punch always stands — GPS is
evidence, not a gate). **(4) Accuracy badges** — the admin attendance modal shows
the ± accuracy as a coloured pill (green ≤50 m · amber ≤500 m · red >500 m) so an
IP-based fix is obvious. Tests: `WorkerAttendanceTest` (+3 — inaccurate anchor
skipped, accurate-far alerts admins, near-is-fine), `SettingsScreenTest` (distance
save). The `flash` shared prop gained a `warning` channel.

### Worker PWA — required check-out attachment + UI polish (2026-08-11)

**Proof-of-work attachment required at check-out.** A worker cannot close the day
without uploading a site photo OR a document. Migration
`2026_08_11_000001` adds `attendance.check_out_attachment_path` +
`check_out_attachment_name` (server-set, NOT fillable). `CheckOutRequest` (extends
`PunchRequest`) makes `work_attachment` required (`mimes:jpg,jpeg,png,webp,pdf,doc,docx`,
max 8 MB); `WorkerController::checkOut` uses it and `WorkerAttendanceService::checkOut`
stores the file on the PRIVATE disk (`attendance-checkout/{company}/{employee}`,
randomized name) and records the original name. The check-out sheet has a required
file field (camera-capable), the Confirm button is disabled until a file is picked,
and the server enforces it too. Admins download it through a gated + audited route
(`GET /attendance/{attendance}/checkout-attachment`, mirrors the selfie route) —
surfaced as a download link in the attendance modal. Tests: `WorkerAttendanceTest`
(+3 — required/refused, stored, admin download audited) and the existing check-out
tests now supply a fake attachment.

**UI polish (worker PWA):** the "Day complete · X h" banner no longer shows in the
checked-out state (the day-summary card covers it); "Day finished. Nice work!" →
**"See you tomorrow"** (`done_for_today`); the day-summary "Hours worked" label →
**"Hours"** (`summary_hours`); the whole worker column now sits in a rounded
`border-line` frame (`WorkerLayout`, page-surface fill so inner cards keep contrast).

### Worker PWA hardening — no money, locale labels, short-shift alert (2026-08-11)

A worker-side pass following the notification build.

**No financial data on the worker payload (defence in depth).** The "Pending
deductions" panel is gone from `Worker/Home.vue`, and `WorkerController::home`
no longer ships `pending_advances` OR `recent_expenses` (the latter carried
expense `amount`s and wasn't even rendered). Workers get attendance +
worker-direct notifications only — nothing with a euro figure reaches the wire,
not merely the UI. Tests in `WorkerFeaturesTest` now assert those props are
`missing` from the payload.

**Calendar labels + legend.** `Worker/Components/MonthCalendar.vue` no longer
shows bare Spanish letters (C/M/P). Each cell renders a short code — **the SAME
in both languages** (client request 2026-08-11): full → **PF** (green), half →
**PH** (amber), partial hours → the actual hours e.g. `2h` (blue), absent → **A**
(light red, lighter when auto-generated or computed), leave → **L** (blue),
weekend worked → **WE** (purple), weekend-no-work → empty grey. The legend words
under the grid stay locale-aware (Completo/Full day …). Keys: `worker.cal_*` +
`worker.legend_*`.

**Absent count is live, not cron-dependent — across ALL calendars.** A PAST
weekday the worker was employed for (≥ `joining_date`, not a weekend, before
today) with NO attendance row is shown as an **absence** immediately, without
waiting for the nightly `attendance:auto-absent` sweep (which later writes the
real row; the two agree). The rule lives in ONE place — `App\Support\
AttendanceAbsence::isUnrecordedAbsence()` — and all three calendars call it so
they **agree by construction**: the worker PWA (`WorkerDashboardService`), the
standalone admin grid (`AttendanceController::index` — adds a virtual `id:null`
absent cell + folds the count into the summary's Absences), and the Employee
detail → Asistencia tab (`EmployeeController::attendanceTabPayload`). A computed
absence carries `is_auto = true` so the cell shades lighter; its `id` is null so
clicking it opens "new entry" (record what really happened). Deployed-in workers
are excluded from the host grid (their HOME company owns their absences). This
reverses the earlier "never mark an unrecorded day absent" rule. Tests:
`WorkerDashboardTest`, `AttendanceTest` (grid cell + summary), and
`EmployeeAttendanceTabTest` each pin it with a fixed clock + joining date.

**Locale-aware weekday headers.** The calendar column headers were hardcoded
Spanish single letters (`L M X J V S D`) — unreadable in English (X = Wednesday).
Now driven by shared `weekdays.*` keys (Mon-first: Lun–Dom / Mon–Sun) in every
calendar: the worker `MonthCalendar`, the Employee-detail Asistencia tab, AND the
admin Attendance grid (Screen 11) — where each day column now shows its weekday
abbreviation above the date number. Verified live in both languages.

**Day-type thresholds** already read from per-company Settings
(`AttendanceService::dayTypeThresholds` → `attendance.full/half_day_threshold.{id}`,
default 6/3) and freeze onto each row — unchanged, confirmed. Partial-hours =
the `hourly` grade below the half threshold.

**Short-shift alert (new `NotificationType::ShortHours`, role → Admin+Manager).**
On check-out, `WorkerAttendanceService::notifyIfShortShift` fires when
`0 < hours < half_day_threshold` — the admins/managers get "Jornada corta —
{name}" + a bilingual body ("… solo 2h 30m … no alcanza media jornada"), linking
to `/attendance`. Pinned by two `WorkerAttendanceTest` cases (fires under the
threshold, silent for a full day).

**Notifications render in the VIEWER's language (Fix 7).** Notifications store
BOTH languages (title_es/title_en, and now optional body_es/body_en) — never a
single app-default string. `NotificationPresenter` adds a `title`/`body`
resolved to the signed-in user's saved locale (`app()->getLocale()`), used by
the worker PWA (one language); the bilingual CRM bell/page still show both. The
dispatcher + `SystemNotification` carry the optional bilingual body through to
the DB + mail. The worker PWA now also **polls the bell every 60 s** (same
WebSocket-less fallback as the CRM — Reverb is not installed). Verified live:
the same two worker notifications render Spanish under ES and English under EN.

**⚠️ Money-in-worker-notifications conflict (client decision needed).** The Fix 6
spec text included euro amounts in the worker's advance/expense notifications
("Tu anticipo de 200 € …"). That contradicts Fix 1 + the standing rule that
workers never see money (2026-08-08). The stronger, repeated rule won: worker
notifications stay **amount-free** ("Tu anticipo fue aprobado"). Flip only if the
client explicitly wants amounts shown to workers.

### Notification system — wired end to end (2026-08-09)

The bell + notifications were mostly plumbing before (dispatcher/rules/matrix/
mark-read existed, but almost nothing CALLED the dispatcher). This pass wires
every trigger, builds the standalone page + a worker bell, and normalises the
payload shape so the bell and the page agree.

**Delivery mechanism.** Reverb/WebSockets are NOT installed and the cPanel host
can't run a daemon, so the bell **polls every 60 s** (the spec's sanctioned
fallback) via an Inertia partial reload of the shared `notifications` prop
(`AppLayout.vue`, paused while the tab is hidden). No real-time socket in v1.

**One presenter, one shape.** `App\Support\NotificationPresenter::present()`
flattens any stored notification — `SystemNotification` OR
`DocumentAlertNotification` — to `{id, type, icon, category, title_es, title_en,
url, read, created_at}`. `NotificationType::icon()` (emoji) + `category()`
(documents/payroll/invoices/vehicles/workers/other) are the single source; the
bell (`HandleInertiaRequests`), the page, and the worker payload all go through
it. `DocumentAlertNotification` payloads now carry a `type` + `url` too, so
document/vehicle alerts render with the right glyph and land in the right filter.

**Event triggers wired** (all through `NotificationDispatcher`):
Advance request→`AdvancePending` (admin+manager) · advance decided→`AdvanceDecided`
(worker bell) · Leave request→`LeavePending` · leave decided→`LeaveDecided`
(worker) · Worker-expense submit (PWA + fuel)→`ExpensePending` · expense
approved/rejected→`ExpenseDecided` (worker) · Payroll calculate→`PayrollReady`
(admin) · approveAll→`PayrollApproved` (SA) · Invoice fully paid→`InvoicePaid`
(SA, once, guarded on the Unpaid→Paid transition) · Weekend offer
published→`WeekendOffer` to the invited workers (`dispatchToEmployees`). GPS-missing
(`WorkerGpsMissing`) already fired from `WorkerAttendanceService`.

**Time-based sweep** — new `notifications:scan` command (scheduled `dailyAt('08:00')`
Madrid in `routes/console.php`): overdue unpaid SALE invoices (once, via a new
`invoices.overdue_notified_at` flag — migration `2026_08_09_000004`, server-set,
not fillable), vehicles out >24 h (reuses the `vehicle_sessions.overdue_alerted`
flag), and call follow-ups due today (`CallFollowUp`→the caller, worker-direct).
Nightly `attendance:auto-absent` now also emits a per-company `AutoAbsent` summary.
Document + vehicle-expiry alerts stay in `verto:scan-documents` (unchanged
schedule). **All console sweeps eager-load tenant-scoped relations with
`withoutGlobalScopes()`** — a cron run has no current company, so a scoped
relation resolves to null (caught by the new tests).

**Worker-direct family.** `AdvanceDecided`/`ExpenseDecided`/`LeaveDecided`/
`WeekendOffer`/`CallFollowUp` are `isWorkerDirect()` — delivered to ONE user via
`dispatchToUser`/`dispatchToEmployees`, excluded from the Settings role matrix
(`NotificationRules::matrix()` skips them). Default recipient roles
(`NotificationType::defaultRoles`) follow the spec: payroll-ready / invoice-overdue
/ GPS-missing / auto-absent / vehicle → Company Admin; advance/expense/leave
pending → Admin+Manager; document-expired / deployment → SA+Admin; payroll-approved
/ invoice-paid → SA. (Pinned by `NotificationRulesTest`'s default-fallback test.)

**Surfaces.** (1) Header bell (`AppLayout`) — red unread badge, dropdown of the
latest 10 with emoji + bilingual title + time-ago, mark-all-read, "Ver todas" →
`/notifications`. (2) New **`/notifications`** page (`Notifications/Index.vue`,
`NotificationController@index`) — filter tabs (Todas/No leídas/Documentos/Nóminas/
Facturas/Vehículos/Trabajadores, category filter at the DB level on `data->type`
so pagination stays right), unread=coral left-border rows, click→mark-read+navigate,
"Marcar todo como leído" + "Eliminar leídas" (`deleteRead` → `readNotifications()`),
25/page. (3) Worker PWA bell (`Worker/Home.vue`) — collapsible panel of
worker-direct notifications, own read routes under `/worker/notifications/*` (the
CRM routes are `not_worker`-gated). Tests: `NotificationTriggersTest` (10 — event
triggers, the scan command's 3 sweeps, page render + presenter icon/category,
delete-read). **Also fixed:** the attendance edit modal's note heading said "Voice
note" even for a text-only note — now shows a mic + "Voice note" only when there is
audio, else a file glyph + "Note".

### Payroll auto-calc hardening + vehicle fines (2026-08-08)

Fixes to the monthly payroll run and how vehicle fines reach it.

**Zero-priced jornada repair.** `PayrollService::rowAmount()` re-derives a
full/half day left at `total_amount = 0` by the day-type back-fill migration
(the reclassification set `day_type = full` on old zero-priced rows without
re-pricing) from the DAILY rate in force on that date (`WageRateService::ratesForDate`,
wage history else live field). It is **freeze-safe**: a correctly-priced row
(total ≠ 0) is untouched, so `PayrollTest`'s "uses the snapshot rate even after
a raise" still holds. Used by both the gross and `dayTypeSummary()`; empty
summary lines (a 0-hour open check-in) are filtered out.

**Vehicle fines never auto-deduct.** Migration `2026_08_08_000005` adds
`deduct_from_salary` + `deduction_month` to `vehicle_fines` (both server-set, not
fillable). `PayrollService::vehicleFinesFor()` now sums ONLY fines the admin has
explicitly flagged (`deduct_from_salary` + matching `deduction_month` +
`employee_id`) — the old `charged_to = 'employee'` auto-deduction is gone.
`VehicleController::deductFine()` (`PUT /vehicles/{v}/fines/{fine}/deduct-salary`)
flags/unflags per fine; Vehicles Show → Fines tab has a "Deducir de nómina"
button + month picker and an undo. A company-charged fine still creates its
Expense as before.

**UI polish.** Payroll hours render `28h 57m` (not 28.95); an amber warning shows
when net pay is negative; the breakdown modal renders the day-type detail line
("Jornadas completas: 4 días × 50 €"). Worker Expenses table normalises the
category to its label (`fuel → Combustible`), never a raw `worker.expense_cat_*`
key (added the missing `fuel` key). Tests: `PayrollTest` (+3 — fine
never/only-when-flagged, zero-jornada re-derivation), `VehicleExtensionTest` (+1
flag/unflag).

**Follow-up (same day).** (1) **Advances from Payroll** — a "Nuevo anticipo"
button on Screen 12 opens a modal (employee · importe · mes · fecha · motivo) that
POSTs the existing `/advances` endpoint; the advance is created Pending and
deducts once approved (the approval control is unchanged). (2) **Fuel
reimbursement** — a `vehicle_fuel_records.payment_method = 'reimburse'` (worker
paid, added to the fuel form) folds into the payroll reimbursements line via
`PayrollService::fuelReimbursementsFor()`; company-card fuel stays a company cost.
(3) **Two bug fixes:** the `settings.attendance` route (day-type thresholds) was
never registered → Save 404'd — added `PUT /admin/settings/attendance`; and the
Inertia error page rendered raw `errors.404_title` keys because a 404 from an
unmatched route skips the web middleware (so `HandleInertiaRequests::share()`
never ships the dictionary) — the exception respond hook now passes `lang` +
`locale` explicitly, fixing every error page CRM-wide. Tests: `PayrollTest`
(+1 fuel), `SettingsScreenTest` (+2 thresholds save/validate).

**Follow-up 2 (same day).** (1) **Advance from Payroll now deducts.** The advance
quick-add posted `status = Pending`, but payroll only deducts Approved/Deducted
advances — so a just-added advance showed nothing. `AdvanceController::store()`
now accepts `approve` (the Payroll modal sends `true`); when set AND the user has
`payroll.approve` it creates the advance Approved and re-runs `calculateMonth` so
the deduction shows immediately. Without the flag it stays Pending (unchanged).
(2) **Admin "Nuevo gasto" on Worker Expenses.** `WorkerExpenseAdminController::store()`
(`POST /worker-expenses`, gate `expenses.create`) lets an admin add a worker
expense from the CRM on behalf of a worker — created Approved, so it folds into
that month's payroll reimbursements like a phone-submitted one. Index now ships
`employees` + `categories` + `can.create`; the page has a New-expense modal.
(3) **Leave** New Request auto-fills "Total days" from the working days between
the dates (was a manual field whose blank/mismatch silently failed the save).
Tests: `PayrollTest` (+2 advance approve/pending), `WorkerFeaturesTest` (+1 admin
worker-expense → payroll).

### Subcontractor (thaekedar) module (2026-08-07)

New module `subcontractors` (sidebar "Más módulos"). Migration
`2026_08_07_000006_create_subcontractor_tables` adds three tables: `subcontractors`
(company-owned, project-linked), `subcontractor_workers` (free-text name +
optional link to one of our employees; `total_agreed` = days × rate, computed by
the service, never client), `subcontractor_payments` (a schedule of payments with
an `expense_id` link). Enums: `SubcontractorStatus` (active/completed/cancelled),
`SubcontractorPaymentStatus` (pending/partial/paid). Added `Module::Subcontractors`
— the permission matrix, gate registration, and PermissionFuzz suite are all
data-driven over `Module::cases()`, so it extends automatically (view/create/edit/
delete/export). Tests: `SubcontractorTest` (10 tests).

**`SubcontractorService::markPaid()`** posts a Gasto (ExpenseType Other,
PaymentStatus Paid) on the **subcontractor's OWN company + project** — never the
browsing session's active company (client decision) — and links `expense_id`;
idempotent (a payment already carrying an expense is left alone, so re-marking
cannot double-charge). `markPending()` / `deletePayment()` remove the expense.
Mirrors the vehicle-fine auto-expense pattern. Screens: `Subcontractors/Index`
(list) + `Subcontractors/Detail` (record card · workers table w/ add-edit modal ·
payment schedule w/ mark-paid/pending + running totals: total acordado / pagado /
pendiente). Nested worker/payment routes re-check `subcontractor_id` ownership
(→ 404) since those child models are reached through the tenant-scoped parent.

### Weekend / optional work days (2026-08-07)

Voluntary Saturday/Sunday work. Migration `2026_08_07_000005_add_weekend_to_attendance`
adds `is_weekend` (server-detected, NOT `$fillable` — set in `AttendanceService::recompute`
from `date->isWeekend()`, never from the client), `weekend_rate_type`
(`App\Enums\WeekendRateType`: normal / x1.5 / x2 / custom) and `weekend_rate_amount`;
existing rows are backfilled from the stored date. `applyWeekendPremium()` multiplies
the day total (×1.5 / ×2) or replaces it with the custom flat amount, only when the
day is actually a weekend AND a rate type is set. Tests: `WeekendAttendanceTest`
(7 tests — detection, client-ignore, ×1.5 / ×2 / custom, weekday no-op, payroll split).

`PayrollService::dayTypeSummary()` groups weekend days into their own line
(`weekend` flag on each entry); payslip PDFs + the on-screen breakdown render them as
"Días fin de semana". Entry + bulk modals show the weekend notice (LOPD-style: only
record volunteers; non-attendance is NOT an absence) and a rate selector when the
chosen date is a weekend; the live preview applies the premium. Calendar cells mark
weekend work **FS** in coral on both the standalone grid and the employee tab. **Note:**
the bulk "Vino / No vino / No convocado" distinction has no data difference (No
vino/No convocado both = no record, no penalty), so selection = "came"; the notice
explains it. Follow-up if the client wants those states persisted.

### Weekend offers + auto day-type + PWA money-hiding (2026-08-08)

Three linked changes to the attendance / Worker PWA.

**1. Worker PWA shows NO money.** Workers must never see wage amounts. Removed
the "Earned this month" card, the check-out "Day total", the pending-advance €
figures (now just "Deducción pendiente"), and per-day amounts from the calendar
detail. Defence in depth, not UI hiding: `WorkerDashboardService` no longer puts
`earned` or per-cell `total` in the payload, `WorkerController::todayPayload()`
drops `amount`, and `pendingAdvances()` drops `amount`.

**2. Automatic day-type detection on check-out.** The worker never picks a type;
`AttendanceService::applyAutoDayType()` grades the day from the hours worked
(called from `WorkerAttendanceService::checkOut` after hours are computed):
`hours >= full → full` · `hours >= half → half` · else `hourly`. Thresholds are
per-company Settings — keys `attendance.full_day_threshold.{companyId}` /
`half_day_threshold.{companyId}` (default 6 / 3), edited on Screen 26. Migration
`2026_08_08_000003` adds `auto_day_type` + `is_auto_detected` (both server-set,
NOT fillable). A weekend worked day (reachable only via an invited offer) IS
graded like a weekday — a full 10 h Saturday is a full day (daily rate), then the
offer's weekend premium rides on top; it is NOT priced hours × hourly (that gave
a ~10× overcharge). Guard: a **purely hourly worker** (no `daily_wage`) stays
hourly — grading to a full day would price a daily rate they don't have. An admin day_type edit through
`AttendanceService::update()` flips `is_auto_detected` off (a manual override) but
keeps `auto_day_type` as the detection record. Admin grid badges: **A** (info) =
auto-detected · **✎** = manually overridden. Tests: `AutoDayTypeTest` (7).

**3. Weekend Work Offers — Sat/Sun are days off by default.** A worker cannot
punch in on a weekend unless an admin has published an offer for that date AND
invited them. Migration `2026_08_08_000004` adds `weekend_work_offers`
(company_id, project_id, offer_date, weekend_rate_type, weekend_rate_amount,
`invited_employee_ids` JSON, created_by; unique per company+date). Admin publishes
from the Attendance screen ("Oferta de trabajo fin de semana" modal → date must be
a weekend, project, rate ×1/×1.5/×2/especial, invited workers). `WorkerController`
sends the PWA a `weekend` payload: on a rest day it shows *"Hoy es día de
descanso"* and hides check-in; on an invited offer it shows *"Trabajo disponible
hoy (fin de semana)"* + project and unlocks check-in.
`WorkerAttendanceService::checkIn()` enforces it server-side (weekend + no invite
→ ValidationException) and carries the offer's weekend rate onto the row so the
premium prices automatically. Invited-membership is checked in PHP
(`WeekendWorkOffer::invites()`) for MySQL/SQLite portability. Tests:
`WeekendOfferTest` (9 — blocked/allowed/not-invited/weekday/tenancy + admin
store/weekday-reject/cross-company destroy).

### Project Profitability report — P&L per project (2026-08-08)

Revenue − cost per project, all server-side + company-scoped. Migration
`2026_08_08_000002_add_billing_rates_to_projects` adds `client_hour_rate`,
`client_meter_rate` (revenue side) and `outsource_cost` to `projects` (nullable,
plain decimals — not pay data; in `$fillable`, form rules on
`StoreProjectRequest`, form fields in `ProjectFormModal`).

**`App\Services\Reports\ProfitabilityService`** is the single authority:
- **Revenue** by `billing_type`: `hourly` → `client_hour_rate × Σ hours`;
  `per_meter` → `client_meter_rate × Σ metres` (metres = `Σ quantity WHERE
  day_type=per_meter`); everything else (`fixed`/`milestone`) → `Σ paid SALE
  invoices`.
- **Cost** = labour (`Σ attendance.total_amount`) + approved project expenses
  (`expenses.total WHERE approved`) + paid subcontractor payments. An
  **outsourced** project swaps its own labour for the flat `outsource_cost`
  (attendance labour ignored). Subcontractor auto-expenses are created
  `approved=false`, so summing *approved* expenses never double-counts them —
  paid subcontractor payments are added separately.
- **Margin** = profit/revenue×100; health **> 15 green · 5–15 amber · < 5 or
  negative red** (no revenue with a cost = red; nothing = neutral).
- Cached per company (`Cache::remember`, 600s) behind a signature = MAX(updated_at)
  of attendance/expenses/projects/subcontractor payments, so a new punch or
  expense mints a fresh key and the P&L recomputes — no observers.

**Surfaces:** (1) Project Detail → **Resumen** tab profitability card (gated by
`payroll.view || employees.edit`, colour-coded margin). (2) Reports → new
**`profitability`** module (gate `payroll.view`) with company + client + project
filters; company-wide colour-coded table, and a day + month drill-down when a
project is selected (breakdowns are labour-basis, matching the spec examples).
PDF + Excel export both handle it (`ReportExport` + `report-pdf.blade`; the PDF
drops the internal `project_id`). (3) Dashboard → profitability widget
(profitable/at-risk/loss counts) via `DashboardService` +
`ProfitabilityService::dashboardCounts()`. Tests: `ProfitabilityTest` (10 —
hourly/per-meter/fixed/outsourced/subcontractor math, margin traffic light,
dashboard tally + tenancy, day/month breakdown, report gating). The worked
example (client 20/h · 320 h · labour 4.640 · gastos 450 → ingresos 6.400 ·
beneficio 1.310 · margen 20,5 %) is pinned by the hourly test's ratios.

### Permission matrix cleanup — no dead toggles (2026-08-09)

Audited every module × action in the matrix against real gate usage. The Module
enum's 19 cases cover every gated prefix (no orphan gates), but `Module::actions()`
was offering **11 toggles no code checked** — each one misled an admin into
thinking they had granted something. Removed:

- `employees.approve`, `invoices.approve` — neither module has an approval
  workflow (invoices are drafted → paid).
- `deployments.delete` — deployments are create → complete/cancel, never deleted
  (decision 25).
- `export` on Documents / Vendors / Vehicles / Leave / Measurements / Inventory /
  Deployments / Subcontractors — those modules have no export feature.

New guard: `PermissionFuzzTest` "offers no dead toggle" reads every app + resources
source file and asserts each matrix-applicable ability appears in the code, so a
future non-functional toggle fails the suite. (Gate REGISTRATION is unchanged —
all 19×8 gates still exist; only the grantable/visible set in the matrix shrank.)

Not gaps (verified): Compliance is gated `documents.view`; Today's Report follows
the Dashboard pattern (open to authenticated users, pay data stripped without
`payroll.view`).

### Project Detail: Attendance + Measurements tabs (2026-08-09)

The two remaining project-detail placeholders ("Available in a later phase") are
now built, reusing the existing Attendance/Measurement models + endpoints.

- **Attendance tab.** `ProjectController::show` ships `projectAttendance` (this
  project's rows for the selected month via an `att_month` partial-reload param,
  with employee name + designation + day type + check-in/out + hours + rate +
  total, wage-gated) and a period summary (workers / days / hours / labour cost).
  Month nav + client-side employee/day-type filters; a "New Entry" button opens
  the shared `AttendanceModal` with this project preselected (new `presetProject`
  prop). Reloads `projectAttendance` + `dailyPnl` + `profitability` after saving.
- **Measurements tab.** `projectMeasurements` (rows + approved/pending quantity
  totals + `billing_linked` = per_meter project) drives a table with
  approve/reject/edit/delete (existing `/measurements*` endpoints) and an
  add/edit modal (employee · date · quantity · unit m²/m/m³/kg/units · type ·
  notes). Approved measurements already feed profitability + per-meter billing.
- Both tabs are permission-gated (`attendance.view/create`,
  `measurements.view/create/edit/delete/approve`) and wage-gated for money.
  Tests: `ProjectsTest` (+2 — attendance summary, measurement approved/pending).

### Deep audit pass (2026-08-09)

A full security / database / performance / calculation sweep (three parallel
read-only audits + the test suite). Findings and fixes:

- **Security.** Real tenancy bug: `StoreFineRequest.employee_id` had no
  own-company rule — an admin could fine an employee of ANOTHER company, which if
  later flagged deduct-from-salary would hit a foreign payslip. Now `new
  OwnCompanyEmployee`. Hardening: `AdvanceController.store` category `exists` now
  constrained to null-default/own-company; `LeaveController::download` now audits
  the access (medical certificates); `SettingsController::updateAttendance` gained
  an explicit super/company-admin check (was middleware-only). All other download,
  gate, company_id-from-input, nested-ownership, and SQL-injection checks came
  back clean.
- **Database.** All 70 models use explicit `$fillable`; money is `decimal` not
  float; FKs indexed; sensitive fields encrypted + hidden. Added two composite
  indexes the P&L/payroll filters wanted (`attendance[company_id,status]`,
  `expenses[project_id,approved]`, migration `2026_08_09_000003`).
- **Performance.** One real N+1: `CallPanelController::employeeRow` fired 2
  queries per active employee (last call + follow-up) — now the `callLogs` are
  eager-loaded and derived in memory. Every other list/show controller + export
  view verified already eager-loading. Dashboard (120s) and profitability
  (signature) stay cached.
- **Design note (client decision):** the `designations` table stores a single
  Spanish `name`, not `name_es`/`name_en` — consistent with the "Spanish-only for
  new data" rule; flagged rather than forced bilingual.

Tests added: cross-company fine rejection, leave-attachment download audit,
auto-absent skips approved leave.

### Worker designations + project rates + daily P&L (2026-08-09)

Three linked features around per-trade pricing.

**1. Designations (Feature 1).** New `designations` table (migration
`2026_08_09_000001`, group-wide defaults with `company_id` null, same shape as
leave/expense categories) seeded with 14 trades (Maestro/Mistri … Otro).
Employees gain a nullable `designation_id` FK (the free-text `designation` is
kept for display). The employee form's Designation field is now a dropdown of the
catalogue (`Designation::scopeForCompany`); `EmployeeController` ships
`designationOptions`, `StoreEmployeeRequest` validates `designation_id`.

**2. Project rates per designation (Feature 2).** `project_designation_rates`
(migration `..._000002`; one row per project+designation: `client_rate`,
`worker_rate`, `rate_type` = `App\Enums\ProjectRateType` per_hour/per_day/
per_meter). CRUD via `ProjectDesignationRateController`
(`POST/DELETE /projects/{project}/designation-rates`, `projects.edit`); UI is a
table + inline add row on the Project **Resumen** tab. **`AttendanceService::
applyProjectDesignationRate()` is the load-bearing bit**: when a row is on a
project AND a rate exists for that worker's designation, it FREEZES the project
`worker_rate` (by rate_type: per_hour→hourly, per_day→full jornada / half kept,
per_meter→metres) onto the attendance snapshot — so payroll (which reads the
frozen total) automatically pays the project rate. No project rate → the profile
rate, unchanged (every existing test still passes). Same freeze guarantee: a
later rate change never rewrites a priced day.

**3. Daily production P&L (Feature 3).** `ProfitabilityService::dailyPnl()`
returns per-day rows (workers / hours / client income / labour cost / expenses /
profit / margin + traffic-light health), each expandable to a per-worker
breakdown, plus monthly rollups, totals, and KPI figures (today / this month /
project total / days remaining). INCOME uses the CLIENT rate (project-designation
rate for the worker's designation, else the project's single `client_hour_rate`);
COST is the worker's frozen day total + that date's approved project expenses.
Surfaced on the Project **Rentabilidad** tab (wage-gated) with a daily/monthly
toggle, coloured rows, and KPI cards. Tests: `DesignationRateTest` (7 — pricing
per type, fallback, freeze, CRUD, tenancy), `ProfitabilityDailyTest` (1 — the
worked example: 4 workers, income 600 · cost 424 · profit 176).

### Nightly auto-absent sweep (2026-08-08)

`attendance:auto-absent` (scheduled `dailyAt('23:59')->timezone('Europe/Madrid')`
in `routes/console.php`) books an automatic absence for every active employee in
every active company (company = not soft-deleted) that has no attendance row on a
WEEKDAY. Migration `2026_08_08_000001` adds `is_auto_generated` (NOT fillable —
set only in the command). Skips: weekends; a day that already has any row
(approved leave writes a Leave row, so it's covered); inactive employees;
new hires whose `joining_date` is after the day. The row is `status=absent`,
0 hours / 0 pay, `notes = 'Ausencia automática — sin registro'`. Editing it
through AttendanceService clears `is_auto_generated` (a human now owns it).
Calendars shade an auto-absence lighter red than a manual one (grid + employee
tab); the employee-tab absences card shows the auto count. `--date=Y-m-d` runs a
back-date for testing. Tests: `AutoAbsentTest` (6).

### Worker check-in/out flow rework (2026-08-08)

No migration — UI + one notification type. `WorkerController::todayPayload()`
enriched: `project` (loaded with `CompanyScope` DROPPED — a worker has no CRM
session, so a scoped read returns null), `location_captured`
(`check_in_lat`/`check_in_lng` both set), and `amount` (`total_amount`, only once
`check_out` is set). `Worker/Home.vue`:
- **Check-in confirmation card** (state `checked_in`): entry time, project, a LIVE
  "tiempo trabajado" counter ticking from `check_in` (`setInterval`, cleared on
  unmount), and a green "Ubicación capturada" line OR an amber "Ubicación no
  capturada — el administrador será notificado" warning.
- **Check-out day summary**: the check-out sheet shows entry + project + live
  worked time BEFORE confirming (button relabelled "Confirmar salida"); the closed
  state (`checked_out`) shows the full summary — entrada / salida / horas / proyecto
  / **importe del día** (the worker's own pay, shown once the day is closed).
- **GPS-missing admin alert**: `WorkerAttendanceService::checkIn()` dispatches
  `NotificationType::WorkerGpsMissing` (new case, default role Admin, appears in the
  Settings matrix automatically via `NotificationRules::matrix()`) through
  `NotificationDispatcher` when the punch recorded `location_denied`. GPS stays
  EVIDENCE not a gate — the punch always stands; the alert just stops an admin being
  blind to a location-less check-in. Recipients resolved by the matrix (never by
  hand). Tests: two added to `WorkerAttendanceTest` (admins notified on denied /
  nothing sent when a fix lands) + one to `WorkerDashboardTest` for the enriched
  calendar cell. **702 pass / 4094 assertions.**

### Worker PWA calendar polish (2026-08-07)

- **Language mix fixed**: the month label (`Agosto 2026`) was hardcoded to Spanish
  while the stat labels followed `$t()`. Now the label is formatted client-side
  from the locale (`monthLabel` computed), so the whole dashboard is one language.
- **Salary earned this month** surfaced (the service already computed `earned`,
  the UI never showed it) — coral card under the stats.
- **Hours** render as `28h 57m` (a `hoursHM` helper), not the raw decimal `28.95`.
- **`MonthCalendar`** cells now show a day-type marker (C / M / hours / metres / A)
  coloured by type, and tapping a day reveals its detail (hours / project /
  amount). `WorkerDashboardService` enriches each cell with day_type/hours/quantity/
  project/total. **Real bug caught by the new test**: the eager-loaded `project`
  was tenant-scoped, so in the worker's no-company context it resolved to null —
  now loaded with the scope dropped. Tests: `WorkerDashboardTest` (+1 cell-detail).

### Attendance UI polish — cells + modal live preview (2026-08-07)

Follow-up review fixes on the admin attendance side (client screenshots):
- **Calendar cells** now show four corners: day number (top-left), the day-type
  marker (centre), project name short (bottom-left, first 10 chars), and the day
  amount (bottom-right, wage-gated). Employee-tab summary gained a "Medias
  jornadas" (half-day count) card and an `h` suffix on hours.
- **Entry/edit modal**: the live pay preview now shows for BOTH create and edit,
  labelled "Importe calculado", with hours as `7h 00m` (not a decimal); a wage
  snapshot line "Tarifa aplicada: 80,00 €/día (desde 01/01/2025)" reads the
  current rate's `effective_from`. A manual override now requires a reason
  (`override_reason` column, migration `2026_08_07_000007`, `required_if` in
  StoreAttendanceRequest). Tests: `AttendanceOverrideTest` (2) + a half-days
  assertion on `EmployeeAttendanceTabTest`.

### Employee detail — Asistencia tab (2026-08-07)

Screen 06's Asistencia tab (was "coming soon") is now a per-employee month
calendar. `EmployeeController@show` builds `attendanceTab` (Monday-first grid +
monthly summary: present / hours / overtime / absences / leaves / total wage),
gated by `attendance.view`; the wage total is null without `payroll.view ||
employees.edit`. Month navigation and cell-edit use Inertia PARTIAL reloads
(`only: ['attendanceTab']` / `['attendanceEditing']`, `att_month` / `att_edit`
query params) so the tab updates without a full page load. A deployed worker's
host-logged days count too (query drops the tenant scope, pins to `employee_id`).
The shared `AttendanceModal` is reused (this employee pre-selected); "Nueva
Asistencia" creates, clicking a day edits. Cell colours reuse the day-type
palette + purple for weekend work + grey for empty weekends. Tests:
`EmployeeAttendanceTabTest` (3 tests — grid+summary, wage-gating, view-gating).

### Day types for daily workers — dehadi (2026-08-07)

A daily (dehadi) worker is paid by the JORNADA, not the hour. Attendance now
carries a `day_type` (`App\Enums\DayType`: full / half / hourly / per_meter) that
drives the day's pay, server-side and never trusted from the client:

    full      total = daily rate × 1.0
    half      total = daily rate × 0.5
    hourly    total = hours × hourly rate  (+ overtime)
    per_meter total = quantity × per-meter rate

Migration `2026_08_07_000004_add_day_type_to_attendance` adds `day_type` +
`quantity` to `attendance` and `day_type_summary` (encrypted JSON) to `payrolls`,
and backfills existing rows (hourly-snapshot → hourly, else full; a legacy full
row seeds `wage_rate_snapshot` from its frozen total so a later edit recomputes
to the same amount). Tests: `DayTypeTest` (8 tests / 17 assertions).

**AttendanceService** freezes the base rate by day type in `applySnapshots`
(`WageRateService::ratesForDate` returns `{daily, hourly, per_meter}` — the wage
history governs whichever rate matches its own wage_type, the others come from the
employee columns), re-snapshots when day_type changes, and `recompute` prices the
day by its type. The **capture mode follows the day type** (hourly clocks in/out;
the rest are manual/quantity) — the frontend derives `mode` from `day_type`, so
`mode` stays valid but is no longer the visible selector.

**PayrollService** now sums earnings by day type over worked **and paid-leave**
rows (`effectiveDayType()` falls back to `wage_type_snapshot` when `day_type` is
null, so leave/legacy rows still price — the fix for a leave-to-payroll
regression), and builds `day_type_summary` (grouped by type × rate, reconciles to
gross to the cent): *Jornadas completas / Medias jornadas / Por horas / Por
metros*. Both payslip PDFs and the on-screen breakdown modal render it, replacing
the generic días/horas lines when present. Per-meter attendance is its own gross
term (NOT folded into base_salary — that would double-count against the summary
line).

**Frontend:** the entry + bulk modals lead with a "Tipo de jornada" selector
(quantity field for per-meter; live per-type pay preview); the calendar cell shows
**C** (green, completa) · **M** (amber, media) · hours (blue) · metres (coral) ·
**A** (red, absent) · **V** (leave). The employee form now shows **all** rate
fields at once with unit labels (€/día, €/hora, €/m², €/mes) — a worker can carry
several — reverting the earlier by-type hiding. **EN toggle fixed** on the Salary
History panel/modal (real English values, no longer Spanish-in-both).

### Employee Wage History — automatic rate switching (2026-08-07)

A worker's wage rate can change over time (50 €/day → 70 €/day from a date); the
system now prices each worked day from the rate in force ON that day, automatically,
across any number of changes. Migrations: `2026_08_07_000001_extend_employee_wage_rates_effective_dating`
(+`effective_to`, `reason`, `created_by`), `..._000002_backfill_employee_wage_rates`
(seed/reconcile), `..._000003_add_rate_periods_to_payrolls` (encrypted JSON breakdown).
Tests: `WageRateTest` (16 tests / 45 assertions). **UI is Spanish-only** (client rule
2026-08-07): new keys live under `wage_rates.*` in BOTH dictionaries with identical
Spanish text, so the label stays Spanish under any locale.

**The table existed since Phase 2 but was dormant** — it stored an `is_default` flag
with `effective_from` only, and nothing read it. This wires it up as a proper
effective-dated history: each row owns a closed `[effective_from, effective_to]`
range; exactly ONE row per employee is open (`effective_to = null`) = the rate in
force today. The single-open invariant lives in **`WageRateService`** (not a DB
constraint — a partial unique index is not portable to the SQLite test DB, and MySQL
treats multiple NULLs as distinct anyway).

**`WageRateService` is the single writer.** `rateForDate()` is the spec lookup
(`effective_from <= date <= effective_to|∞`, newest wins, unscoped by company so a
deployed worker's home-company rates resolve). `snapshotValues()` is what
AttendanceService now freezes onto each day — the dated rate if one covers the day,
else a FALLBACK to the employee's live wage fields that replicates the pre-history
`applySnapshots` EXACTLY (so factory-made employees with no rate row still price as
before — this is why every existing attendance/payroll test still passes).
`createRate()` closes the open row the day before the new one, opens the new one,
syncs the employee's cached wage columns to today's rate, and reprices UNPAID/unlocked
attendance from the new date (edge case 2 — a paid month or `PeriodLock`ed month is
never touched). `deleteRate()` refuses if any attendance falls in the row's range.

**AttendanceService** now delegates `applySnapshots` to `WageRateService::snapshotValues($employee, $date)`
and re-snapshots on employee OR date change; `recalculateRow()` is the public entry the
back-dated reprice calls. **EmployeeService** no longer writes wage-rate rows itself —
create seeds the first rate; a direct wage-field edit on the employee form updates the
OPEN row in place (a correction to the current rate), never a new dated period. A new
DATED period is created ONLY through "Nueva Tarifa".

**PayrollService** groups the month's worked days into contiguous rate periods
(`ratePeriods()`) whenever the frozen rate changes, stored on the new encrypted
`payrolls.rate_periods` JSON column; period amounts are the SAME `hours × frozen hourly`
the gross uses, so they reconcile to `days_amount`/`hours_amount` to the cent. Null
unless ≥2 periods (a real change). Both payslip PDFs render a "Períodos de tarifa"
section. **Screen 06 Información tab** gained a "Historial de Salario" timeline (coral
current row + `Actual` badge, muted history) and a "Nueva Tarifa" modal (previous rate
shown read-only; a back-dated start warns + requires confirmation before repricing).
Endpoints: `POST /employees/{employee}/wage-rates` · `DELETE …/wage-rates/{wageRate}`
(both `employees.edit`-gated, tenant-scoped route binding → cross-company 404).

### Vehicle module extension (2026-07-30)

The vehicles screen (Screen 21) was extended with 9 new features. Migration:
`2026_07_28_000001_extend_vehicle_module.php`.

**New fields on `vehicles`:** `vehicle_type` (enum: car/van/truck/motorcycle/other,
`App\Enums\VehicleType`) and `road_tax_expiry_date` (date). Both optional. Both shown
in the create/edit modal and in the Show info tab.

**`vehicle_daily_assignments` table** (Feature 2 — MOST CRITICAL): one row per
(vehicle, date); UNIQUE constraint; `updateOrCreate` so same-date re-entry replaces
rather than stacks. `VehicleService::logDailyAssignment()` / `deleteDailyAssignment()`.
`driverOnDate()` queries this table to auto-populate fine's employee. Routes:
`POST /vehicles/{v}/daily-assignments` · `DELETE …/{assignment}`.

**`vehicle_fines` table** (Feature 3): `charged_to` = `company` | `employee`. When
`company`, `VehicleService::logFine()` auto-creates an `Expense` (type `Other`,
`PaymentStatus::Unpaid`) on the vehicle's company (never the session) and stores the
`expense_id` FK. Deleting the fine cascades-deletes the expense. Driver is auto-resolved
from the daily assignment log when `employee_id` is not supplied. Routes:
`POST /vehicles/{v}/fines` · `DELETE …/{fine}`.

**`vehicle_fuel_records` table** (Feature 5): `total_cost` stored verbatim from the
form (not recomputed) to preserve receipt-level rounding. Routes:
`POST /vehicles/{v}/fuel` · `DELETE …/{fuelRecord}`.

**`VehicleCompliance` extended**: `road_tax` added to `EXPIRY_FIELDS` constant alongside
`insurance` and `ita`. `worst()` now grades all three; the 90/60/30 + expiry-day
`verto:scan-documents` sweep hits road_tax automatically at zero new scheduler code.
A missing expiry is `neutral` — `worst()` now returns `neutral` when any expiry is
unset and the others are fine.

**`VehicleMaintenanceHistory`** (Feature 4): `vendor_name` column added (string 100,
nullable). Shown in the maintenance tab and the log-maintenance modal.

**Show.vue rebuilt** with 6 tabs: info · assignments · maintenance · fuel · fines ·
mileage. Info tab has cost summary cards (maintenance total / fuel total / fines total)
and three compliance expiry cards (insurance / ITV / road tax). Assignments tab has two
sub-sections: long-term history + daily assignment log.

**Form requests added**: `StoreDailyAssignmentRequest`, `StoreFineRequest`,
`StoreFuelRequest`. **Factories added**: `VehicleDailyAssignmentFactory`,
`VehicleFineFactory`, `VehicleFuelRecordFactory`.

**Tests**: `VehicleExtensionTest` (27 tests / 61 assertions) covering all new features,
tenancy isolation, and permission gates. One existing test in `VehicleTest` updated
(`is comfortably ok when both expiries are far out` → now sets all three expiries
because adding road_tax changed the `worst()` semantics for a vehicle with two-of-three
set).

### Worker PWA feature extensions (2026-08-01)

Three capabilities added on top of the Phases A–E core (check-in/check-out/attendance/
dashboard). Migration: `2026_07_30_000001_add_worker_features.php`. Tests:
`WorkerFeaturesTest` (25 tests / 78 assertions).

**Voice/text notes at check-out** (`AttendanceVoiceNote`): one note per attendance row
(upsert on `attendance_id` — a second post overwrites, never stacks). Worker posts text
+ optional audio clip (≤5 min / 5 MB, stored on private disk under
`attendance-voice-notes/{company}/{employee}/`). CRM admin downloads via
`AttendanceVoiceNoteController` (gate: `attendance.view`, audited). Worker downloads
their own note via `WorkerVoiceNoteController`. Audio validation uses
`mimetypes:audio/webm,...` NOT `mimes:webm` — `audio/webm` maps to extension `weba`
under `mimes:`, causing silent false rejections. Routes: `POST /worker/attendance/{id}/note` ·
`GET /worker/voice-notes/{note}/download`.

**Worker vehicle sessions** (`VehicleSession`, `VehicleSessionService`): worker takes /
returns a company vehicle. `takeVehicle()` sets `vehicles.is_available = false`;
`returnVehicle()` closes the session (sets `returned_at`, computes `km_driven`, restores
availability). `logFuel()` records litres mid-session. `resolveRouteBinding` on
`VehicleSession` bypasses `CompanyScope` — workers have no CRM session so the scope
yields 0 rows; ownership is validated in the controller via
`abort_unless($session->employee_id === $employee->id, 403)`. Routes:
`POST /worker/vehicles/{v}/take` · `POST /worker/vehicle-sessions/{s}/fuel` ·
`POST /worker/vehicle-sessions/{s}/return`.

**Worker expenses + admin management** (`WorkerExpense`, `WorkerExpenseStatus`):
worker submits receipt photos at check-out; `receipt_path` is NOT mass-assignable (same
pattern as `AttendanceVoiceNote::audio_path`). CRM admin approves / rejects / downloads
receipts via `WorkerExpenseAdminController` (gates: `expenses.view` / `expenses.approve`).
Approved expenses are summed into the payroll `reimbursements` line for the month
(`PayrollService::pwaExpensesFor`, approved + date-in-month). Admin page:
`Pages/WorkerExpenses/Index.vue`. Worker page: `Pages/Worker/Home.vue` expense panel.

**Review pass (2026-08-01) — what it caught and fixed:**
- **Feature 4 was unreachable in production.** `employees.can_use_vehicles` (the PWA
  vehicle-module gate) had no grant path — it was `$fillable` but absent from
  `StoreEmployeeRequest` rules and the employee form, so `validated()` never carried it
  and every worker stayed `false` forever. Fixed: rule added (Update inherits), a
  `VCheckbox` added to `EmployeeFormModal.vue` next to `active`, bilingual
  `employees.can_use_vehicles` key. Pinned by two `EmployeesTest` cases (grant/revoke +
  default-off).
- **Worker's own voice-note download was not audited** (Rule 10 says every private-file
  download is). Added the `AuditLogger` `viewed` entry to `WorkerVoiceNoteController`,
  matching the admin route. Two new `WorkerFeaturesTest` cases cover the worker download
  (own → 200 + audited; another worker same-company → 403).
- **Tenancy re-verified:** admin voice-note + expense routes are scoped by `CompanyScope`
  on route-model binding (cross-company → 404); worker vehicle-session / voice-note /
  expense actions drop the scope but re-check `employee_id` ownership (→ 403). Payroll,
  advance deduction, and net-pay math confirmed correct.
- **Admin voice-note surfacing — now built.** `AttendanceController::index` loads the
  set of attendance ids carrying a note (one query, keyed by `attendance_id`, no N+1) and
  each grid cell gets `has_voice_note`; `Pages/Attendance/Index.vue` renders a small
  `mic` marker (new `AppIcon` glyph) in the corner of those cells. The cell edit modal
  (`AttendanceModal.vue`) shows the text note and an `<audio controls preload="none">`
  pointing at the gated download route — so the admin plays the clip / reads the note in
  place. `payload()` carries `voice_note {id, text_note, has_audio, duration_seconds}`.
  Pinned by two `WorkerFeaturesTest` cases (grid cell flag + edit payload). **Still needs
  a real-device / staging browser pass** to confirm audio playback across phones.
- PWA expenses fold into the payslip `reimbursements` line rather than a separate line
  (matches the documented breakdown); rejection shows on the worker dashboard by pull —
  there is no push notification (no push infra in v1).

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
