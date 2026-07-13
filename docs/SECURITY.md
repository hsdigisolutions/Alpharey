# VertoCRM — Security Approach

The system stores employee personal data, salaries, IBANs, legal documents, and company
financials. Security is treated as a first-class feature, built in Phase 0/1 and enforced
by automated tests for every module that follows — not bolted on at the end.

---

## 1. Authentication

- Session-based auth (Laravel's native guard) — no tokens in the browser
- Argon2id password hashing; rate limiting on login and password reset
- Session timeout configurable (default 120 min); "remember me" 30 days
- Password reset by signed, expiring email link
- Architecture 2FA-ready: Laravel Fortify TOTP can be enabled later without rework
- Session fixation protection (regenerate on login), secure/httpOnly/sameSite cookies

## 2. Company data isolation (the critical control)

- Every company-scoped model uses a `BelongsToCompany` trait with a **global Eloquent scope**:
  queries are filtered to the user's company automatically — isolation is the default,
  not something each developer must remember.
- The active company context comes **only from the authenticated session**, never from
  request input. A `company_id` sent by the client is ignored.
- Super Admin cross-company access is an explicit, audited context switch (Welcome screen).
- Route-model binding is scoped: requesting `/employees/{id}` for another company's
  employee returns 404, not 403 (no information leak that the record exists).
- Shared entities (clients, vendors, proposals) are explicitly unscoped by design;
  projects are visible only to their assigned company (plus Super Admin).
- **Automated tenancy test suite**: for every module, tests assert that a Company B user
  cannot read, edit, delete, export, or download Company A data — by ID guessing, by
  filter manipulation, or through relations (e.g. reaching another company's employee via
  a shared client's project). These tests run in CI on every commit.

## 3. Authorization

- Every request passes: authentication → company scope → role check → module permission
  check (`user_module_permissions`: view/create/edit/delete/upload/download/export/approve).
- Enforced server-side via Policies and Gates on every controller action — hiding a
  button is never the control; the action itself is guarded. Inertia page props are
  filtered server-side so unauthorized data never reaches the browser.
- Permission changes take effect immediately (checked per request, cached per request only).
- All permission changes are themselves audited (who granted what to whom).

## 4. Input validation

- Form Request validation classes on every write endpoint — types, ranges, formats
  (NIF/NIE/CIF checksum validation, IBAN format), enum whitelists, max lengths.
- Inertia submits hit the same Form Request rules — there is no separate API surface to
  validate differently.
- Eloquent parameter binding everywhere (no raw SQL with interpolation) → SQL injection off the table.
- Blade auto-escaping → XSS; CSRF protection on all state-changing requests (Laravel default).
- Mass-assignment protection: explicit `$fillable` on every model.

## 5. File security

- All uploads stored under `storage/app/private` — physically outside the webroot;
  no file is ever URL-addressable directly.
- Downloads only via authenticated, policy-checked streamed responses
  (company scope + `can_download` permission + document-level check).
- Upload validation: extension + MIME allowlist, size limits, randomized stored filenames,
  images re-encoded; original filename kept only as display metadata.
- Every upload/download/delete is written to the audit log.

## 6. Audit trail

- Observer-based logging on every model: created / updated / deleted / viewed / exported,
  with old and new values, user, IP, user agent, request method, timestamp.
- **Append-only**: no update or delete route exists for audit logs; the application DB user
  can additionally be denied UPDATE/DELETE on the table at the MySQL grant level.
- Visible only to Super Admin and Company Admin (Company Admin sees own company only).
- Volume plan: high-write table — indexed by (company, user, module, created_at);
  yearly archive-to-cold-table strategy (archived, never deleted) to keep the UI fast.

## 7. Encryption and data protection

- Encrypted Eloquent casts (AES-256 via APP_KEY) for: NIF/DNI/NIE/passport numbers, IBAN,
  bank name, salary/wage fields on the employee record.
- **Searchable encrypted fields** (e.g. search employees by NIF) get a blind-index column
  (HMAC hash) — search works without storing plaintext.
- **APP_KEY is a crown jewel**: losing it makes encrypted data unrecoverable. Key is stored
  in `.env` (never in git) and backed up in a separate secure location (password manager).
  Key rotation procedure documented.
- Employees and clients soft-delete only — never hard-deleted.
- HTTPS enforced: AutoSSL certificate, `Strict-Transport-Security` header, all HTTP → HTTPS.
- Security headers middleware: CSP (no inline/CDN scripts — everything bundled),
  X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy.
- `.env`, `storage/`, and `vendor/` unreachable from the web (doc root = `public/` only).

## 8. Operational security

- `composer audit` in CI — builds fail on known-vulnerable dependencies
- Debug mode hard-off in production; errors logged, never displayed
- Nightly encrypted backups (DB + documents) shipped **off the VPS**; restore tested quarterly
- Login throttling + notification on repeated failures
- Staging environment is HTTP-auth-gated and uses anonymized data

## 9. Pre-go-live security gate (Phase 9)

A release is blocked until all pass:

1. Full OWASP Top 10 checklist walkthrough
2. Tenancy isolation suite green across all modules
3. Permission fuzz tests green (every module × every action × every role)
4. Direct file-access probing returns nothing
5. `composer audit` + `npm audit` clean
6. TLS configuration verified (SSL Labs grade A)
7. Backup restore drill completed successfully

---

## Areas that need special attention (flags for the client)

1. **Hosting location vs GDPR** — client is confirming the Mukhost datacenter location
   directly. Development proceeds now; **EU hosting must be confirmed in writing before
   the Phase 9 go-live gate** — all data EU-hosted at launch (DECISIONS.md).
2. **APP_KEY custody** — agreed: client's password manager (Super Admin) + secure note
   with the dev team lead; rotation procedure documented in the Phase 9 admin handbook.
3. **Official nóminas vs internal payroll** — resolved: internal management payslips
   only; official nóminas (IRPF/SS) stay with the gestoría (DECISIONS.md).
4. **Disk growth** — agreed: email alert to Super Admin at 70% disk usage; usage review
   at the 6-month mark with plan upgrade if needed.
5. **Email deliverability** — decided: cPanel SMTP on the alpharey.com domain with
   SPF + DKIM. Monitor inbox placement during Phase 2 testing; switch to a transactional
   provider if deliverability disappoints.
6. **Audit log immutability vs size** — agreed: yearly archive of records older than
   12 months to a cold table; never deleted; archived-log query procedure documented in
   the admin handbook. Migrated legacy audit logs land directly in the archive.
