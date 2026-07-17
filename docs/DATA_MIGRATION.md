# VertoCRM — Legacy Data Migration Plan

The old VertoCRM (Laravel 12 API + Vue 3 SPA, 87 tables, ~85–90% complete) holds real
production data that seeds the new system. This document covers: where the data is, how
the migration runs, the old→new mapping, and every known conflict with its proposed
resolution.

Source references:
- Old code copy: `C:\Users\Super\Downloads\VertoCRM-main\VertoCRM-main\`
- Full schema/code review of the old system: `C:\Users\Super\Downloads\VertoCRM-main\CODEBASE_REVIEW.md`

---

## 1. Where the data actually is (verified 2026-07-12)

> **UPDATE 2026-07-17 — the live dump arrived and the importers were validated
> against it. The external blocker below is CLEARED.** The dump
> (`vertocrm-313539dae2.sql`, 88 tables, 24 MB) was restored locally as
> `vertocrm_legacy` and a full `verto:import-legacy` run was exercised against
> it. Results and the bugs it surfaced are in **§6**. In short: employees (264),
> clients (34), vendors (221), projects (64), attendance (4 911), expenses (687),
> payrolls (156), vehicles (12) and the inventory ledger all import, and the
> money reconciles to the cent (attendance 333 692,44 €, expenses 39 685,80 €,
> payroll net 119 494,36 €). The `storage/app` upload set (item 2 below) is still
> outstanding, so document/photo *files* cannot be validated yet — but no legacy
> `documents` rows exist in the dump anyway (that store was empty).

The downloaded copy was inspected on this machine:

- `database.sqlite` in the download is **empty (0 bytes)**.
- The download's `.env` points to a MySQL database (`vertocrm-35303034b22b` on
  127.0.0.1) that **does not exist on this machine** — the local Laragon MySQL was
  checked; none of its databases contains the VertoCRM schema.
- The download **does** contain real uploaded files under
  `storage/app/private/employees/{id}/…` (~2 MB — likely a partial set).

**Conclusion:** the authoritative database (and the full uploaded-file set) lives on the
server where the old system is running (alpharey.com hosting). It is not in the download.

### What we need from you (blocking for migration work, not for development)

1. **A full database dump** from the live server: cPanel → phpMyAdmin → Export
   (format: SQL, include structure + data), or via SSH:
   `mysqldump --single-transaction --routines <dbname> | gzip > vertocrm_live.sql.gz`
2. **The complete `storage/app` directory** from the live server (zip via cPanel File
   Manager is fine) — this holds every uploaded document/photo.
3. Confirmation that the live alpharey.com database is the single authoritative source
   (no second environment with newer data).

Until then, migration importers are developed and tested against the schema (fully
documented) and a seeded replica; the real dump slots in whenever it arrives, and a
fresh delta dump is taken at cutover.

---

## 2. Migration approach: importers per module, not a big-bang script

A `verto:import-legacy` artisan command in the **new** application, with one importer
class per module, run in dependency order. Properties:

- **Reads the legacy DB directly** (second read-only DB connection) — no CSV middle step
- **Idempotent**: a `legacy_id_map` table (entity type, legacy id, new id) makes re-runs
  safe and enables the final delta import at cutover
- **Dry-run mode**: full pass with validation and an exceptions report, zero writes
- **Writes through Eloquent models** so the new system's encrypted casts (NIF, IBAN,
  salary), blind indexes, and (bypassed-but-logged) audit hooks apply correctly
- **Exceptions report** (CSV per run): every row that needed a judgment call — nothing
  silently guessed
- Built **incrementally alongside the build phases** (see §5), so each new module is
  exercised with real data as soon as it exists, instead of one risky migration at the end

**Validation after every run:** row-count reconciliation per table; financial totals
(invoice totals, payments, payroll net per month) old vs new; attendance hours per
employee per month old vs new; document file-existence check (every DB row has its file).

---

## 3. Old → new mapping and the conflicts, with resolutions

### 3.1 Single company → five companies (the structural conflict)

The old system has **no company concept** — it is one company's data throughout.

**Resolution:** create the 5 companies (dummy names per DECISIONS.md); assign **all**
legacy company-scoped data (employees, attendance, payroll, expenses, vehicles,
documents) to **Company 1**, which represents the company the old system served.
Admins reassign employees to their real companies from the UI after go-live (bulk
company-reassign tool included in the Employees list for this purpose). Clients,
vendors, and proposals are shared in the new design, so they migrate without company
assignment. Projects (company-owned in the new design) also go to Company 1.
**Confirm with client:** does the legacy data really all belong to one of the five
companies, or is it mixed? If mixed, we need a mapping list (e.g. by project or by
employee) before the final import.

### 3.2 Users, roles, permissions

Old: 4 overlapping layers (legacy `users.role` enum, RBAC roles/permissions, team
permissions, field-level permissions). New: 3 levels + per-user module permission matrix.

**Resolution:** map `super_admin`/`admin` → Super Admin; `manager`, `accountant`,
`viewer`, `team_leader` → Custom Users with a module-permission preset equivalent to
their old role (manager ≈ full minus admin; accountant ≈ finance modules; viewer ≈ read
only). All migrated non-super users are attached to Company 1 pending reassignment.
Old field-level permission rules are **not migrated** (the new system's module matrix
supersedes them); the old rules are kept in the archived dump if ever needed.
Password hashes are bcrypt in both systems and **carry over** — users keep their
passwords; admins are advised to rotate at cutover.

### 3.3 Three document stores → one

Old: `employee_documents` (legacy), `employee_files` (newer), `documents` (uuid,
unified) — three coexisting stores.

**Resolution:** merge all three into the new polymorphic `documents` table, in order of
age (legacy first), de-duplicating on (employee, file name, size): later stores win and
earlier duplicates become **prior versions** of the same document (the new system has
versioning). `document_type`/`file_type` values map to the new employee document types
(DNI, NIE, contract, PRL training, medical…), with unmapped types imported as "custom"
documents — listed in the exceptions report for admin re-classification. The two legacy
varchar columns on employees (`employee_doc_1/2`) are imported as custom documents too.
Physical files copy from the live `storage/app` into the new private disk, re-keyed to
the new randomized-name convention; original names preserved as display metadata.
Rows whose physical file is missing on the server are imported with a "file missing"
flag and listed in the exceptions report — not dropped (the metadata itself has value).

### 3.4 Duplicate/ambiguous financial columns

Old schema carries duplicated semantics: invoices `total_amount` vs `total` and
`vat_percent` vs `vat`; expenses `vat_percent` vs `iva_percent` and `category` (string)
vs `category_id`; payments `vat_amount` vs `vat_withheld`; attendance `hours` (legacy)
vs `hours_worked`.

**Resolution:** one canonical column each in the new schema, filled by precedence:
- invoices: `total_amount` wins; `vat_percent` wins (fall back to the duplicate only
  when canonical is null/zero and the duplicate isn't)
- expenses: `iva_percent` wins over `vat_percent` when they differ (it was the later,
  actively-used column); `category_id` wins over the string `category`, and strings
  without a matching category create one (flagged in exceptions)
- payments: `vat_withheld` + `vat_withheld_percent` are canonical (vat_amount was a
  backfilled duplicate)
- attendance: `hours_worked` wins; `hours` only where `hours_worked` is null
Any row where the two candidates disagree by more than rounding goes to the exceptions
report with both values — resolved by a human, not by the script.

### 3.5 VAT now optional (new rule) vs old 21% defaults

Old rows default `vat_percent` to 21.00 even where VAT may never have applied.

**Resolution:** historical financial records migrate **exactly as stored** — migration
never alters financial history. The optional-VAT rule applies to new records only.

### 3.5b VAT is an enum in the new schema, a percentage in the old one

Phase 6 stores `vat_rate` as an `App\Enums\VatRate` value ('general', 'reducido',
'superreducido', 'exento'), matching proposals, so that every VAT field in the system
is the one official dropdown. The legacy schema stores a raw `vat_percent` decimal.

**Consequence:** a legacy row at a NON-official percentage (e.g. 17%) has no
representation in the new schema.

**Resolution:** `VatRate::fromPercent()` maps the four official rates across
(21/10/4/0). Anything else:
- keeps its `vat_amount` and `total` EXACTLY as stored — the money is never touched,
  so the books still reconcile (§3.5)
- imports with `vat_rate` left blank
- lands in the exceptions report with the original percentage, for a human

The importer never rounds an unofficial rate into a nearby official one: that would
silently rewrite what a client was charged. Pinned by
`tests/Unit/VatRateFromPercentTest.php`.

**Open until the dump arrives:** whether any legacy row actually uses a non-official
rate. If the report comes back empty, this is a non-issue; if not, the client decides
per row.

### 3.6 Wage system (5 overlapping mechanisms)

Old wage data lives in: employee columns (wage_type/wage_rate/base_salary/daily_wage/
per_meter_rate), `employee_wage_rates`, `employee_salary_history`, `project_employee_rates`,
and per-row attendance snapshots.

**Resolution:** the new schema keeps the same four structures (per the requirements)
plus snapshots, so migration is mostly 1:1 — with normalization: wage_type value
`meter` → `per_meter` everywhere; the old duplicate model situation (two models over
`employee_salary_history`) is irrelevant since only the table migrates. Attendance
snapshots migrate untouched (they are historical facts). Where an employee's column
rates disagree with their current `employee_wage_rates` default, the wage-rates table
wins and the difference is flagged.

### 3.7 Leave attached to users, not employees

Old leaves/balances reference login **users** only; field employees never had leave
records. New system tracks leave per employee/user per the spec.

**Resolution:** migrate existing user leaves as-is (matched to employees by email where
possible, noted in exceptions where not). Balances for 2026 migrate; prior years import
as history. Field-employee leave starts fresh in the new system.

### 3.8 Modules that exist in old data but NOT in the new scope

- **Chat** (`chat_conversations/participants/messages`) — not in the new requirements.
  **Not migrated**; preserved in the archived dump. ⚠ Confirm dropping chat is intended.
- **AI assistant tables** (`ai_memories`, `ai_user_quotas`, `ai_prompt_usages`,
  `ai_knowledge_entries`) — not in the new requirements. **Not migrated.** The old
  settings row containing an AI API key must be **excluded** from settings migration
  (secret hygiene).
- **Dead settlement engine** (`invoice_settlement_works`, `employee_settlements`) — in
  the old system these have schema but zero application logic. The new requirements
  list both tables, so the new schema includes them; data migrates if any rows exist
  (expected: none).

### 3.9 Identifiers

**Resolution:** numeric primary keys are **preserved** for core entities (employees,
clients, projects, invoices, expenses, attendance…) — the new DB is fresh so there are
no collisions, FK remapping becomes trivial, and file folders (`employees/{id}/…`)
stay aligned. Documents keep UUIDs. The `legacy_id_map` covers the few cases where IDs
must change (merged duplicates).

### 3.10 Audit history

Old `audit_logs` (and `attendance_logs`) are historical record.
**Resolution:** import directly into the new audit **archive** table (per the yearly
archive design) — visible through the archived-logs query path, never mixed with new
live logs, never deleted. Old audit rows keep their original timestamps and user names
even where the user no longer exists.

### 3.11 Everything that maps cleanly (no conflict)

clients + client_contacts · vendors + contacts + payment_terms · proposals ·
invoice_reminders · commission_report_entries · payrolls + advances + advance_categories
· overtime_policies · locked_periods · measurements · production_tasks + task_progress +
task_templates · nickname system + reconciliations · vehicles + 3 history tables +
assignments · all 5 equipment tables · expense_categories tree · company_cards ·
custom_fields + values · dropdown_seeder_options · notifications (recent only, 90 days)
· report_remarks · settings (minus AI/secret keys, remapped to new keys) ·
soft-deleted employees/clients migrate **with** their `deleted_at` intact.

Known old-schema drift that migration simply ignores (documented in CODEBASE_REVIEW.md):
the broken `attendances` index migration, Vehicle model fillable/schema mismatch, empty
no-op migrations — none of these affect data content.

### 3.7 Leave hangs off `user_id` in the old schema, `employee_id` in the new one

**The single riskiest mapping in the migration**, because nothing in either
schema can settle it.

The legacy `leaves` and `leave_balances` tables key on `user_id` — a *login*.
The new tables key on `employee_id` — a *member of the workforce* — because
approved leave writes attendance rows and therefore reaches payroll, and both
of those are keyed on employees. Keeping `user_id` would have made Phase 7's
headline deliverable ("leave feeds attendance and payroll") impossible.

There is **no `users.employee_id`, and no `employees.user_id`, in either
schema**. The bridge has to be reconstructed, and `LeavesImporter` does it by
normalised name (case- and accent-insensitive, so *José García* matches
*Jose Garcia*):

| Name matches | Result |
|---|---|
| exactly one employee | imported and mapped |
| zero, or more than one | **NOT imported** — reported with the legacy user id, leave type and start date |

An ambiguous row is left for a human rather than guessed at: attaching a
worker's holiday to the wrong person corrupts *their* balance and *their*
attendance, and neither is obvious afterwards.

**Before cutover:** run `--dry-run`, read the exceptions CSV, and expect to
resolve some rows by hand. Two workers with the same name in a 5-company
construction group is not a hypothetical.

Imported leave is **not** replayed through `LeaveService`: it never re-books
attendance and never re-derives a balance. The legacy system already booked
those days; re-running the engine would either double-book the grid or refuse
on its own attendance-conflict guard. Balances migrate as stored, never
recomputed from the imported leaves — that would silently "correct" a figure
the client has been running on.

### 3.8 Vehicles: column names and the maintenance total

- `assigned_emp_id` → `assigned_employee_id`
- legacy `tire` → `tyre` (the spec's spelling): `last_tire_change_date` →
  `last_tyre_change_date`, same for the mileage column
- `ita_expiry_date` **keeps its legacy name** even though the Spanish
  roadworthiness test is normally abbreviated *ITV*. It looks like a legacy
  typo; the UI already labels it ITV. Renaming the column is a one-line change
  once the client confirms — not worth diverging from the dump before then.
- `maintenance_cost` → `maintenance_cost_total`, **verbatim**. The new system
  recomputes that total from the maintenance log, but the legacy log has no
  per-record cost at all, so recomputing on import would reset every vehicle to
  0. The stored figure is what the client reads; the rollup takes over from the
  first maintenance record the new system writes.

### 3.9 Inventory: why the ledger is not replayed

`StockMovementService` is the authority for stock, and an item's counters are
its cached tail — but the importer carries `total_stock`/`available_stock` over
**verbatim** and does not replay the movements through the service.

That is deliberate, not an oversight. Replaying would recompute every
`balance_after` from an opening stock the legacy data does not record, and any
gap in the old ledger (a movement someone deleted, a counter corrected by hand)
would surface as a stock figure that disagrees with the warehouse shelf. What
is physically on the shelf today is the fact worth preserving. The ledger
becomes the authority from the first movement the new system writes.

---

## 4. Cutover sequence (Phase 9, on alpharey.com)

1. Announce freeze window to users; old system set read-only (or short downtime)
2. Final `mysqldump` + `storage/app` sync from the live server
3. Delta import into the new production DB via `legacy_id_map` (only changed/new rows)
4. Run the full validation suite (counts, financial totals, hours, file existence)
5. Client spot-checks a prepared checklist (known employees, a month's payroll, key invoices)
6. Switch alpharey.com document root/DNS to the new system; HTTPS verified
7. Old system taken offline; final dump + files retained as the **permanent archive**
   (stored off-server, encrypted)
8. Hypercare: exceptions report worked through by admins in the first week

---

## 5. When migration work happens (ties into DEVELOPMENT_PLAN.md)

| Build phase | Migration activity |
|---|---|
| Phase 0 | Obtain dump + files (§1); stand up read-only legacy replica locally; build the importer framework + `legacy_id_map` |
| Phase 1 | Import users/roles (§3.2); companies created; settings remap |
| Phase 2 | Import employees, wage data (§3.6), the three document stores (§3.3), notes, call logs |
| Phase 3 | Import clients, vendors, projects, proposals, project rates |
| Phase 4 | Import attendance (+logs), measurements, production tasks |
| Phase 6 | Import expenses, invoices, payments, payrolls, advances, commissions, locked periods (§3.4 precedence rules live here) |
| Phase 7 | Import leaves (§3.7), vehicles, inventory |
| Phase 8 | Import audit archive (§3.10), notifications; full-system validation run |
| Phase 9 | Freeze → delta import → validation → cutover (§4) |

---

## 6. First real-dump validation run (2026-07-17)

The live dump (`vertocrm-313539dae2.sql`, 88 tables) was restored locally and
`verto:import-legacy` was run against it — first `--dry-run`, then a committed
run into a throwaway database. It surfaced **six defects that 387 green tests
never could**, because the in-memory test stand-in only ever held the shapes we
imagined, not the ones the production database actually contains. All six are
fixed and pinned (`tests/Feature/LegacyRealDataShapeTest.php` +
`LegacyImportersTest.php`).

### 6a. The dry-run framework flaw (the important one)

Each importer wrapped itself in its own transaction and, in `--dry-run`, rolled
that transaction back at the end. So the employees importer's `legacy_id_map`
rows vanished *before* the attendance importer ran, and **every dependent
importer reported 100 % "not imported — run the X importer first"** while X
reported a clean run. The dry-run was lying about what a real run would do —
exactly when you most need it to be honest (attendance: 0 imported / 4 911
exceptions; payrolls 0/156).

Fix: in `--dry-run` the **command** now holds ONE transaction around the whole
sequence and rolls it back at the end; importers commit into it (their
savepoints release, so a later importer resolves the ids an earlier one
recorded). The importer only rolls back its own work when it *owns* the
transaction (a standalone/test run) — signalled by an explicit
`ownsTransaction` flag, because a test's `RefreshDatabase` transaction is
indistinguishable from the command's by nesting level alone.

### 6b. Two enum values that CRASHED the whole migration

A `ValueError` on an enum cast aborts the entire run on the first offending row
— the worst failure mode for a one-shot cutover. Two were hiding in real data:

- **`expenses.payment_method`** answers *who* paid, not *how*: its values are
  `employee` (510), `company_card` (145), `bank` (26), `not_paid` (6) — none of
  which is a `PaymentMethod`. Only `bank` maps (→ `bank_transfer`); the other
  three are facts the new schema already records elsewhere (`is_reimbursable`,
  `company_card_id`, `payment_status`) and map to a blank method. The old code
  passed the column straight into the cast and died on the first `employee` row.
- **`attendance.wage_type`** is spelled `day`/`hour` in the dump, `daily`/
  `hourly` in the new `WageType` enum — the same fact, a different spelling.
  4 888 + 23 rows died on the cast. Translated, not recomputed; the frozen rate
  is untouched.

### 6c. Three quieter mapping bugs

- **`payrolls`**: the period lives in `payroll_month` (`'YYYY-MM'`), which the
  importer never read — it looked only at `month`/`year`, so all 156 rows
  reported "could not resolve the payroll month". Now prefers `payroll_month`.
- **`expenses.payment_status = 'reimbursed'`** (a settled worker refund) fell
  through a whitelist to `unpaid` — silently *re-opening* a debt the company had
  already paid. Now maps to `paid`; any genuinely unknown status is flagged, not
  defaulted.
- **inventory**: items were recorded in `legacy_id_map` under the importer's
  default entity type (`inventory`) but looked up under `equipment_items`, so
  every stock movement, issue and assignment orphaned. Keyed correctly now, and
  the downstream steps increment the imported counter (they under-reported).

### 6d. Noise that is working as designed, not a bug

`expenses` reports 459 exceptions on a clean run. 436 are "conflicting
iva_percent/vat_percent" — but `vat_percent` in the real dump is a **dead column,
uniformly 21.00**, while `iva_percent` carries the real varied rates. The
importer correctly picks `iva_percent` and preserves `vat_amount`; the flag is
informational and the row imports. The other 22 are genuine non-official
effective rates (`vat_amount/base`, e.g. 9,99 %) that a human should eyeball
(§3.5b). No change made — flagging-not-guessing is the deliberate contract.

### 6e. What could not be validated yet

The `storage/app` upload set (§1 item 2) has not arrived, so document/photo
*files* are unverified — but the dump contains **zero `documents` rows** (that
legacy store was empty), so there is nothing to import there regardless. Legacy
`leaves`, `advances`, `invoices` and `employee_call_logs` are also all empty in
this dump; their importers are exercised by the unit stand-ins, not this run.
