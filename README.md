# VertoCRM (Verto5)

Multi-company CRM for a Spanish construction group — 5 companies, one brand.
Employees, documents & compliance, attendance, payroll, invoicing,
cross-company employee deployments, and reports across 26 screens.

**Stack:** Laravel 12 · Vue 3 + Inertia.js · Tailwind CSS · MySQL · Pest.
Deploys to a Mukhost cPanel VPS (alpharey.com) via GitHub Actions over SSH.

## Start here

- **[CLAUDE.md](CLAUDE.md)** — current status, every install/run/test command,
  conventions, and what to build next
- [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) — full product requirements
- [docs/DECISIONS.md](docs/DECISIONS.md) — confirmed client decisions
  (overrides any spec default)
- [docs/DEVELOPMENT_PLAN.md](docs/DEVELOPMENT_PLAN.md) — the 10-phase build plan
- [docs/SECURITY.md](docs/SECURITY.md) — security architecture and go-live gate
- [docs/DATA_MIGRATION.md](docs/DATA_MIGRATION.md) — legacy system migration plan

Every UI label renders Spanish first, English below. Company data is isolated
per company by a default-deny tenancy scope. Every model write is audited,
append-only. These are non-negotiable conventions — see CLAUDE.md before
contributing.
