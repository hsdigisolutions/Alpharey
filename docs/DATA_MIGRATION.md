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
