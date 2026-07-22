# AlphaReyCRM — Confirmed Decisions Log

Client answers received 2026-07-12 (Section 15 + follow-ups) and 2026-07-13 (stack,
hosting, VAT). This file is the record of agreed decisions; changes to any of these must
be discussed and agreed before implementation.

## Technology stack — UPDATED 2026-07-13

**Frontend is Vue 3 + Inertia.js — not Livewire.** Client decision, replacing the
earlier recommendation. Rationale: the old system already ran Vue 3 on this exact
hosting; Inertia gives one codebase with no separate API, no CORS, session auth, and an
SPA-smooth UI; easier future mobile app. **No SSR** (no Node on the server) — Vite
assets are built in CI and deployed as static files. Rest of the stack unchanged:
Laravel 12 on PHP 8.3, Tailwind CSS, MySQL, Pest, DomPDF, Maatwebsite/Excel, Chart.js,
GitHub → SSH → Mukhost deploys.

## Hosting — CONFIRMED READY 2026-07-13

| Item | Status |
|---|---|
| Datacenter | **London, UK** — GDPR-acceptable (EU adequacy decision for the UK). The former "confirm before Phase 9" blocker is **resolved**. |
| PHP | 8.3 available, will be set on the account |
| SSH | Working (previously used for deployment) |
| GitHub deploy | Working (previously used) |
| MySQL | Unlimited databases |
| Disk | Unlimited (the 70% usage alert still ships as agreed) |
| SMTP | `smtp.alpharey.com` available |
| Domain | alpharey.com — new system replaces the old one there |

## Identity & setup

| Topic | Decision |
|---|---|
| Brand name | **AlphaRey** |
| Company names | Dummy/placeholder names at build time. Real names, CIFs, and logos provided later. **Company name, logo, and details must be editable by admin from the UI with no code change.** |
| Domain | **alpharey.com** — the new system replaces the old system currently live there. Clean cutover at go-live; old system taken down. |
| Session timeout | 120 minutes default, changeable by Company Admin in Settings |
| Email service | cPanel SMTP on the alpharey.com domain, SPF + DKIM configured. Switch to a transactional provider only if testing shows deliverability problems. ⚠ Flag: cPanel SMTP has sending-rate limits and weaker reputation than transactional providers — acceptable for this volume (tens of notification emails/day), but monitor spam placement during Phase 2 testing. |

## VAT (IVA) — dropdown policy, UPDATED 2026-07-13

VAT on every invoice, expense, and proposal is a **dropdown of the official Spanish IVA
rates** (Agencia Tributaria), never a free default:

| Option | Rate | Label (ES / EN) | Construction usage |
|---|---|---|---|
| General | 21% | IVA General 21% / VAT Standard 21% | Most invoices — materials, equipment, commercial works |
| Reducido | 10% | IVA Reducido 10% / VAT Reduced 10% | Residential renovation and repair works |
| Superreducido | 4% | IVA Superreducido 4% / VAT Super-reduced 4% | Rarely used in construction |
| Exento | 0% | Exento 0% / Exempt 0% | Exempt cases |
| *(blank)* | — | No aplica / Not applicable | **Default** |

Rules:
- **Default is blank** — the user selects the rate manually; nothing is pre-filled
- VAT amount auto-calculates when a rate is selected
- The selected rate is saved on each invoice/expense/proposal record
- Reports and totals show the VAT amount separately from the subtotal
- Blank means "no VAT line," not a displayed 0%
- Historical migrated data keeps its stored VAT values untouched (financial records are
  never altered in migration)
- Implemented as the shared `App\Enums\VatRate` enum + bilingual labels — every module
  that touches VAT must use it

## Document alert schedules — confirmed

**Monthly documents (4 types):**
- 5 days before end of month → first reminder
- 2 days before end of month → urgent reminder
- 1st of the new month, if still missing → overdue alert
- Recipients: the company's Company Admin; Super Admin gets a summary of all overdue
  monthly uploads across companies

**Annual / event-based documents (other 9 company types + all employee documents):**
- Warnings at 90 / 60 / 30 days before expiry
- Critical alert on the expiry date itself

## Payroll & deployments — confirmed

| Topic | Decision |
|---|---|
| Deployment billing | **Option A** — employee stays on home company payroll; host company gets an internal cross-charge |
| Charge basis | Employee's standard daily/hourly rate. No overhead/margin. No inter-company VAT invoice for now (revisit if the gestoría requests it). |
| Payslips | **Internal management payslips only** — hours, rates, deductions, net pay for management visibility. Official nóminas (IRPF, Seguridad Social) remain with the gestoría. The system will never calculate IRPF or SS contributions. |
| Labor-law posture | Client confirms group deployments operate under proper group company arrangements; gestoría and labor advisor are aware. |

## Security & operations — confirmed

| Topic | Decision |
|---|---|
| Hosting location | ~~To be confirmed~~ **CONFIRMED 2026-07-13: London, UK** — GDPR-acceptable under the UK adequacy decision. Blocker cleared. |
| APP_KEY custody | Two copies: client's password manager (Super Admin) + secure note with dev team lead. Rotation procedure documented in the admin handbook (Phase 9). |
| Disk alert | Email alert to Super Admin at **70% disk usage**; usage review at the 6-month mark. |
| Audit archiving | Yearly archive confirmed: records older than 12 months move to a cold table; never deleted. Admin handbook documents how to query archived logs. |

## Existing data — migration required

The old VertoCRM (Laravel 12 + Vue SPA, 87 tables) contains real production data:
employees, clients, projects, attendance, payroll, expenses, invoices, and more. This
data is the seed dataset for the new system — no manual re-entry.

- Full migration strategy, schema mapping, and conflict resolutions: `docs/DATA_MIGRATION.md`
- Old codebase copy: `C:\Users\Super\Downloads\VertoCRM-main\` (includes a complete
  schema/codebase review in `CODEBASE_REVIEW.md`)
- ⚠ **The live database is on the production server, not in the downloaded copy** —
  see DATA_MIGRATION.md §"What we need" for the dump + files handover.

## Process — confirmed

| Topic | Decision |
|---|---|
| Mobile | All 26 screens work on phones/tablets; one-tap check-in and camera document upload required. (As already planned.) |
| Prototype gate | **The Figma clickable prototype (D9) must be reviewed and approved by the client before development Phase 6 (Payroll & Finance) begins.** |

## Document types — CORRECTED 2026-07-16 (client workbook is now the source of truth)

Client supplied **`DATOS OBLIGATORIOS EMPRESA.xlsx`** — their real compliance checklist.
It **supersedes** the document list previously inferred from REQUIREMENTS.md, which was
wrong in material ways: it carried the pre-2015 **TC1/TC2** pair (Spain replaced these
with **RLC/RNT** under Sistema RED), and it omitted **REA**, **SPA**, **ITA**, the
**Mutua** document and every **payment receipt** — all of which a CAE audit checks.
Several types it did contain (escritura de constitución, certificado digital, licencia de
actividad, modelo 303, contratos vigentes) are **not** on the client's mandatory list.

Registry: `App\Support\DocumentTypes`. Labels: `lang/{es,en}/ui.php` → `doc_types.*`.
The Spanish names are the client's own wording and are authoritative; English is a gloss.

**Sheet EMPRESA → 16 company documents.** `NOMBRE EMPRESA` and `CIF` on that sheet are
company *data* fields (they live on the Company model / Settings), not documents.

**Sheet TRABAJADORES → worker documents**, split `personal` (NIE fotocopia + caducidad,
foto), `employment` (documento alta SS, documento IDC) and `prevencion` (aptitud +
caducidad, art. 19 + caducidad, art. 18, EPIs, autorización uso maquinaria). The plain
identity/contact columns (nombre, apellidos, NIE, teléfono, mail, nº SS, fecha contrato)
are Employee fields. `REGISTRO HORARIO` is produced by the attendance module (Screen 11);
`ENLACE CARPETA TRABAJADOR` was the legacy shared-drive link this engine replaces.

**Sheet OBRAS** confirms projects are keyed by `CODIGO OBRA` + `NOMBRE` + `DIRECCION` —
already modelled on the Project model.

### Frequencies — CONFIRMED by client 2026-07-16

The workbook lists one `FECHA` per document but no renewal cycle. Frequency decides which
alert schedule fires, so it was put to the client. Their answers:

| Frequency | Documents | Alert schedule |
|---|---|---|
| **Monthly** | ITA, RNT, RLC, Recibo de pago RLC, Justificante de pago salarios, **Certificado SS**, **Certificado Hacienda** | 5 + 2 days before, then 1st-of-month overdue |
| **Annual (expiry)** | Póliza RC + recibo, Póliza accidentes + recibo, Certificado SPA + recibo | 90/60/30 + expiry day |
| **Periodic (expiry)** | **REA (3-year renewal)**, Evaluación | 90/60/30 + expiry day |
| **On change** | Documento Mutua | none |

Certificado SS and Certificado Hacienda are **monthly**, not expiry-driven — this was our
one wrong assumption. They therefore track **no expiry date**: `DocumentStatus` resolves
the monthly rule first, so an expiry on these would be collected and then ignored.

Worker slots the client added on top of the workbook:

- **DNI *and* NIE are separate slots** — part of the workforce is Spanish (DNI), part
  foreign residents (NIE). Each worker fills whichever applies; both carry a caducidad.
- **Contrato de trabajo is an uploaded file**, not just the `FECHA CONTRATO` field —
  the client wants the signed PDF. Its expiry carries a temporary contract's end date.

These rules are pinned by `tests/Unit/DocumentTypesTest.php` rather than left to prose.

### Real company name spotted

The workbook's EMPRESA sheet is filled in for **"PINTURAS SHIZUKANI, S.L."** — one of the
five real companies. Local seed data still uses the dummy "Empresa Uno…Cinco"; company
names are editable data (Settings), so no code change is implied.

## Prototype gate — WAIVED by client 2026-07-16

The D9 prototype gate above ("the Figma clickable prototype must be reviewed and approved
before development Phase 6") is **waived at the client's explicit instruction**. Phase 6
(Payroll & Finance) proceeds without a prior prototype review.

Context offered at the time: 4 of D9's 6 review journeys (login→dashboard, employee→
documents→compliance, cross-company deployment, permissions) are already live and
clickable in the real application, which serves the gate's purpose better than a Figma
file; the two that are not (payroll run, invoice→payment) are Phase 6's own core. The
client chose to proceed rather than review those two layouts first.

**Consequence accepted:** if the Payroll or Invoices screen layout needs rework after the
client sees it, that rework lands in the heaviest phase of the build. Design-change
requests against Phase 6 screens are therefore expected to be handled as normal
follow-up work, not as a defect.
