# Go-live runbook — AlphaReyCRM (AlphaRey) → alpharey.com

Phase 9. The ordered steps to take AlphaReyCRM live on the Mukhost cPanel VPS,
replacing the legacy system. Read `LAUNCH_READINESS.md` first — do not start
until its go/no-go rule is green. Every command runs over SSH on the server
unless noted. `$DEPLOY_PATH` is the app root; the document root points at
`$DEPLOY_PATH/public`.

**Rollback is possible at every step before §8.** The old system stays intact
(frozen, not deleted) and its document root can be repointed back in minutes.

---

## 1. Pre-flight (day before)

- [ ] CI green on `main` (Pint, Larastan, composer audit, Pest, Vite build, npm audit).
- [ ] `LAUNCH_READINESS.md` sections 1–4 all ✅; EU-hosting confirmation in writing on file.
- [ ] Deploy secrets present in GitHub: `DEPLOY_SSH_HOST`, `DEPLOY_SSH_USER`,
      `DEPLOY_SSH_KEY`, `DEPLOY_PATH`.
- [ ] Maintenance window agreed with the client; users notified.

## 2. One-time server provisioning (if not already done)

```bash
# In $DEPLOY_PATH on the server:
cp .env.example .env
# Edit .env — see the checklist in §3 below, then:
php artisan key:generate
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

- [ ] cPanel: document root for the domain → `$DEPLOY_PATH/public`.
- [ ] cPanel cron (drives the queue + the 07:00 Madrid document scan):
      `* * * * * php $DEPLOY_PATH/artisan schedule:run >> /dev/null 2>&1`
- [ ] MySQL database + user created; credentials in `.env`.

## 3. Production `.env` checklist (the server keeps its own — never synced)

```
APP_ENV=production            # ← forces HTTPS, CSP, HSTS; hides stack traces
APP_DEBUG=false               # ← MUST be false (verify: never a debug page in prod)
APP_URL=https://alpharey.com
APP_KEY=base64:...            # generated once; back up (see admin handbook)
DB_*                          # production database
MAIL_MAILER=smtp              # cPanel SMTP on alpharey.com, SPF + DKIM published
CACHE_STORE=database  SESSION_DRIVER=database  QUEUE_CONNECTION=database
LEGACY_DB_*                   # set for the migration (§6) only, then blank out
```

- [ ] Confirm `APP_DEBUG=false` — this is the one config that most damages
      security if wrong (leaks stack traces + env). `php artisan tinker --execute="var_dump(config('app.debug'));"` → `false`.

## 4. Deploy the application

Trigger the **deploy** workflow (`workflow_dispatch`, target `production`). It
builds `vendor/` (no-dev) + assets on the runner and rsyncs over SSH (server
needs neither Composer nor Node), then runs `migrate --force` +
config/route/view cache. `.env` and `storage/` are excluded from the sync.

- [ ] Workflow succeeds; site reachable over HTTPS.
- [ ] `php artisan about` shows env=production, debug=false, caches ON.

## 5. HTTPS / TLS

- [ ] AutoSSL certificate issued for alpharey.com (cPanel → SSL/TLS Status).
- [ ] Force-HTTPS working (http → https redirect); HSTS header present.
- [ ] **SSL Labs scan grade A** (gate item 6) — https://www.ssllabs.com/ssltest/.
      Record the grade in `SECURITY_GATE.md`.

## 6. Legacy data cutover (`DATA_MIGRATION.md` §4)

1. [ ] Freeze the old system (read-only / maintenance) — no more writes.
2. [ ] Final dump + `storage/app` sync from the old server to a restore area.
3. [ ] Restore into the `legacy` DB; set `LEGACY_DB_*` in `.env`.
4. [ ] `php artisan verto:import-legacy --dry-run` → review the exceptions CSVs
       under `storage/app/import-exceptions/`.
5. [ ] `php artisan verto:import-legacy` (real run).
6. [ ] **Validation**: row counts + money totals vs the old system
       (attendance/expenses/payroll reconcile to the cent — the §6 baseline).
7. [ ] Resolve leave name-matching exceptions by hand (§3.7 — the riskiest map).
8. [ ] Blank out `LEGACY_DB_*` in `.env`; re-cache config.
9. [ ] Enter real company names / CIFs / logos in Settings when provided.

## 7. Backups & monitoring

- [ ] Nightly off-server database + `storage/app` backup configured.
- [ ] **Restore drill** (gate item 7): restore last night's backup into a scratch
      DB, boot the app against it, confirm login + a money total. Record in
      `SECURITY_GATE.md`.
- [ ] Uptime + error alerting live; disk-usage alert to Super Admin at 70%.

## 8. Switch traffic (point of no easy return)

- [ ] Final delta import if any writes slipped in after §6 (idempotent — the
      `legacy_id_map` makes a re-run safe).
- [ ] DNS / document root now serves the new app as alpharey.com.
- [ ] Smoke test as a real Company Admin: login, dashboard, create an employee,
      run one payroll month, download a payslip, global search.

## 9. Hypercare (2 weeks)

- [ ] Priority-fix rota agreed; monitor error alerts daily.
- [ ] Keep the old system's archive retained and restorable for the full window.
- [ ] Track the `LAUNCH_READINESS.md` §6 follow-ups; schedule into post-launch.

---

## Rollback

Before §8: nothing to undo — the old system is still live; just don't switch.
After §8, if a blocker appears: repoint the document root / DNS back to the old
system (restorable from the retained archive), communicate, and reschedule.
Because financial history migrated verbatim and the import is idempotent, a
second cutover attempt re-runs cleanly.
