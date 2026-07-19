# VertoCRM (Verto5) — Administrator handbook

Phase 9. For Super Admins and Company Admins running the system day to day. It
covers the controls that are not obvious from the screens: roles, the
permission matrix, locked periods, company removal, the compliance alerts,
`APP_KEY` custody, the audit trail, notifications, and backups. Screen-by-screen
usage lives in the (bilingual) user guide.

---

## 1. Roles

Three levels — there is no separate roles/permissions table; access is the role
plus the per-user module matrix (§3).

- **Super Admin** — sees and does everything across all companies. Picks a
  company to act within from the Welcome screen (or browses "all"). The dashboard
  and finance screens require a company selection (they summarise one company).
- **Company Admin** — full access within their own company; cannot see another
  company's data. Cannot edit other admins (only a Super Admin can).
- **User (custom)** — sees nothing by default. Gets exactly the module × action
  rights granted in the permission matrix, and only within their company.

A user marked **inactive** is denied everything immediately (mid-session too),
whatever their role.

## 2. Company data isolation

Every company's data (employees, projects, attendance, payroll, invoices,
vehicles, inventory, leave…) is fully walled off per company. Clients, vendors,
and proposals are the deliberate exception — they are a shared pool all
companies see. This is enforced server-side; hiding something in the UI is never
the control.

## 3. The permission matrix (Settings → Permissions)

For each custom user, a grid of **module × action** toggles (view / create /
edit / delete / upload / download / export / approve — only the actions that
apply to a module are shown). Notes:

- Admins bypass the matrix (they have everything in-company); the matrix only
  governs the `user` role.
- Changes apply immediately — no cache to clear.
- Granting one action never implies another: `payroll.view` does **not** confer
  `payroll.edit` or `payroll.approve`. Grant each explicitly.
- Wage/pay figures and bank details are additionally gated: a user needs
  `payroll.view` (or `employees.edit`) to see them at all, anywhere they appear.

## 4. Locked periods (closed months)

Once a payroll month is **locked** (Payroll screen), the whole month is closed
**system-wide**: attendance edits, leave approvals, and payroll changes into
that month are all rejected — not just on the payroll screen. Unlock only if you
genuinely need to reopen a month; re-lock afterwards. This is what keeps
historical pay from being rewritten after the fact.

## 5. Company management & removal (Super Admin)

Company names, provinces, CIFs, and logos are **data, editable from Settings** —
never code. To change a company's displayed name/CIF/logo, edit it in Settings;
no deployment needed.

**Removing a company** is guarded. The system refuses while anything is still
attached — **users, employees, projects, or unpaid invoices**. The removal
screen lists every blocker; clear them first. Fully-paid invoices are settled
history and do not block. This prevents orphaning financial or employee history.
Removal is a soft delete (the record is retained, not physically erased).

## 6. Compliance alerts (documents + vehicles)

A nightly scan (`verto:scan-documents`, 07:00 Madrid, driven by the server cron)
sends expiry alerts to the relevant Company Admins:

- **Annual/event documents & vehicle insurance/ITV**: warnings at exactly 90,
  60, and 30 days before expiry, plus a critical alert on the expiry day.
- **Monthly company documents** (Certificado SS, Hacienda, ITA, RNT, RLC…): a
  reminder 5 and 2 days before month-end, and an overdue alert on the 1st if the
  previous month was never uploaded. Super Admins get a cross-company overdue
  summary.

The warning window (default 90/60/30) is one setting — `documents.warn_days` —
and it governs documents **and** vehicles together. Widen it in one place and it
widens everywhere. A vehicle with no insurance/ITV date shows **grey (unknown)**,
never green — an unknown date is a gap, not compliance.

## 7. `APP_KEY` custody and rotation (critical)

Employee NIF/DNI/NIE, IBAN, bank names, and all wage/pay figures are **encrypted
at rest** with the application key. The NIF is also searchable via a blind index
(`nif_hash`) derived from `APP_KEY`.

- The key lives in the server `.env` only. Custody: the client's password manager
  (Super Admin) **and** a secure note held by the dev team lead.
- **Rotating `APP_KEY` invalidates every `nif_hash`** and makes existing
  encrypted values unreadable. Never rotate casually. If it must be rotated, it
  requires a planned re-encryption pass (decrypt with the old key, re-encrypt +
  re-hash with the new one) — a scripted maintenance job, not a config change.
  Back up the current key before any rotation.

## 8. The audit trail (§ and archive)

Every business change (create/update/delete, plus login/logout/export/view) is
written to `audit_logs` with the acting user, IP, user-agent, method, and URL,
including old and new values. The log is **append-only** — the system throws if
anything tries to update or delete a row. Never truncate this table.

**Archive plan** (agreed): records older than 12 months are archived yearly to a
cold table — never deleted. Migrated legacy audit logs land directly in the
archive. Query archived logs from the cold table when investigating older
activity.

## 9. Notification rules (Settings)

A per-role on/off matrix controls which roles receive which notification types
(deployment events, payroll ready, invoice overdue, advance/leave pending,
project alerts). An empty rule falls back to the type's default recipients, so a
fresh install behaves sensibly. Turning a row off genuinely stops delivery — the
matrix drives the dispatcher, it is not cosmetic.

## 10. System health (Settings)

A panel checks the database, storage, the database-queue backlog, and SMTP. Use
it to confirm the queue is being drained (the scheduler runs
`queue:work --stop-when-empty` each minute) and that mail is configured. A failed
mail test here means alerts and password resets will not send — fix before
relying on them.

## 11. Backups

Nightly off-server backups of the database and `storage/app` (the uploaded
documents/photos) are configured on the host. Test restores periodically — a
backup you have never restored is a hope, not a backup. The go-live runbook
includes the first restore drill; repeat it at least quarterly.

## 12. Getting help / escalation

- Configuration and deployment: the dev team (see the deploy workflow + this
  repo's `docs/`).
- Data questions (a figure looks wrong): check the audit trail first — it records
  who changed what and when.
- Never edit the database directly to "fix" money or pay — it bypasses the audit
  trail and the encryption; use the screens.
