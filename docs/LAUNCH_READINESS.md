# Launch readiness — VertoCRM (Verto5) on alpharey.com

Phase 9. This is the single checklist a release manager reads before flipping
the switch. It maps every launch gate to its current state and points at the
runbook step or document that closes it. Items marked **CODE — DONE** are
verified in the repo and CI now; items marked **CUTOVER** can only be done
against the live server and are executed on go-live day per
`GO_LIVE_RUNBOOK.md`.

Status legend: ✅ done · ⏳ pending (blocked on cutover/client) · ⚠ needs a
decision or confirmation.

---

## 1. The 7-point security gate (`SECURITY.md` §9 · evidence in `SECURITY_GATE.md`)

| # | Item | State |
|---|------|-------|
| 1 | OWASP Top 10 walkthrough | ✅ CODE — DONE |
| 2 | Tenancy isolation suite green, all modules | ✅ CODE — DONE (18 suites) |
| 3 | Permission fuzz — module × action × role | ✅ CODE — DONE (`PermissionFuzzTest`) |
| 4 | Direct file-access probing returns nothing | ✅ CODE — DONE (`FileAccessProbeTest`) |
| 5 | `composer audit` + `npm audit` clean | ✅ CODE — DONE (CI re-runs both) |
| 6 | TLS configuration — SSL Labs grade A | ⏳ CUTOVER — verify after AutoSSL, runbook §5 |
| 7 | Backup restore drill | ⏳ CUTOVER — runbook §7 |

## 2. Production configuration (the server's own `.env`)

| Item | State |
|------|-------|
| `APP_ENV=production`, `APP_DEBUG=false` | ⏳ CUTOVER — **must verify**; the app forces HTTPS + CSP + hides stack traces only outside `local` |
| `APP_KEY` set and backed up (client password manager + dev lead secure note) | ⏳ CUTOVER — rotating it invalidates the `nif_hash` blind index (see admin handbook) |
| `LEGACY_DB_*` populated only during migration, then cleared | ⏳ CUTOVER |
| Mail: cPanel SMTP on alpharey.com + SPF + DKIM | ⚠ confirm DKIM published |
| Document root → `public/`, AutoSSL issued | ⏳ CUTOVER — runbook §3/§5 |
| Cron: `* * * * * php $DEPLOY_PATH/artisan schedule:run` | ⏳ CUTOVER — drives the queue + nightly document scan |
| Queue worker via scheduler (`queue:work --stop-when-empty`) | ✅ CODE — scheduled; no daemon needed |

## 3. Data migration (`DATA_MIGRATION.md` §4 · §6)

| Item | State |
|------|-------|
| Importers validated against the real dump | ✅ DONE (2026-07-17, money reconciled to the cent) |
| Final freeze of the old system, delta dump + `storage/app` sync | ⏳ CUTOVER |
| `verto:import-legacy` on production, exceptions report reviewed | ⏳ CUTOVER |
| Post-import validation (row counts + money totals vs old system) | ⏳ CUTOVER |
| Real company names / CIFs / logos entered (Settings, no code change) | ⚠ awaiting client — dummy names until then |
| `storage/app` upload set from the live server | ⚠ not yet received (dump had zero `documents` rows) |

## 4. Hosting & GDPR (`SECURITY.md` flags)

| Item | State |
|------|-------|
| EU hosting confirmed in writing before go-live | ⚠ client confirming Mukhost DC (London); **required before gate** |
| Nightly off-server backups + retention | ⏳ CUTOVER — runbook §7 |
| Uptime + error alerting | ⏳ CUTOVER |
| Disk-usage alert to Super Admin at 70% | ⚠ configure on host |

## 5. UAT & documentation

| Item | State |
|------|-------|
| UAT on staging with the client (two fix rounds budgeted) | ⏳ pending client scheduling |
| Admin handbook | ✅ DONE — `ADMIN_HANDBOOK.md` |
| Go-live runbook | ✅ DONE — `GO_LIVE_RUNBOOK.md` |
| Bilingual screenshot user guide | ⏳ pending — authored on staging with final data/branding |

## 6. Known follow-ups carried into hypercare (not launch-blocking)

- Finance detail tabs (employee Nómina, project Facturas/Gastos) remain
  placeholders — the standalone screens are canonical (scaffolding decision 23).
- Settings sections for advance/expense categories and company cards.
- `deployment_charges` → posted invoice/expense lines.
- Invoice reminders are stored but sending is wired to the dispatcher only for
  deployment events so far.
- Unpaid leave does not yet reduce a MONTHLY salary — the pro-rata divisor is a
  Spanish nómina choice awaiting client confirmation; handled via
  `other_deductions` until then.

---

**Go / no-go rule.** No launch until every ✅/⏳ in sections 1–4 is ✅, and the
two ⚠ items in section 4 (EU hosting in writing, backups live) are closed. The
section 5 UAT sign-off is the client's gate, not ours.
