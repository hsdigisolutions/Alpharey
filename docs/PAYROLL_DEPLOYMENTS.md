# VertoCRM — Cross-Company Deployment: Payroll Recommendation

The requirements (Section 3) define three billing methods and ask which is cleanest to
implement for a Spanish construction business. Recommendation below, requested before
implementation begins.

---

## Recommendation: Option A, with a split percentage that also covers Option C

**Option A — home company pays, host company is cross-charged — should be the default and
the only fully automated path.** Implement the cross-charge with a configurable percentage
(default 100% to the host), which makes Option C ("split cost") the same mechanism with a
different number. **Option B — host company runs the payroll — should not be implemented
as specified**, for the legal reasons below. Keep `billing_method` in the schema as spec'd
so the door stays open, but gate B off in v1.

## Why Option A is the right answer in Spain

**1. Legal reality.** In Spain the employment relationship — contract, Seguridad Social
account (TGSS), the official nómina — sits with the employing company and cannot move to
another company for a few weeks. Lending workers between companies is only lawful through
authorized temp agencies (ETTs) or genuine subcontracting (Art. 42–43 Estatuto de los
Trabajadores; construction additionally under Ley 32/2006 and the Libro de Subcontratación).
Anything that looks like Company A processing the nómina of Company B's employee walks
straight into *cesión ilegal de trabajadores* territory. Option B as written would have the
system produce exactly that paper trail. Option A matches the lawful structure: the employee
stays employed and paid by their home company, and the host company is billed an
inter-company service charge — which is also how group companies normally invoice each
other for works/services.

**2. Software cleanliness.** With Option A, the payroll engine never changes: every company
always pays its own employees, calculated from attendance as normal. A deployment adds only
two things on top:

- a **note line** on the home company's payroll rows for those dates
  ("Desplegado a Empresa A — Proyecto X"), and
- an **auto-generated internal expense** on the host company (and a matching receivable
  line in the cross-company report) for the deployment cost.

No date-splitting of a month across two payrolls, no partial nóminas, no edge cases when a
deployment starts or ends mid-month, no conflicts with locked periods in two companies at
once. Option B creates every one of those problems.

**3. Reporting stays truthful.** The cross-company cost summary ("what each company owes
the others") falls straight out of the cross-charge records. Under Option B there is no
charge to report — cost visibility is actually worse.

## How it works mechanically (Option A)

1. Admin creates a deployment: employee, home company, host company, project, dates,
   `rate_during_deployment` + `rate_type`, charge split % (default 100% host).
2. Attendance is logged normally against the **host project**; the grid shows the home
   company badge and "Desplegado" indicator.
3. Home company payroll includes those days at the deployment rate, each row annotated
   with the deployment note.
4. When the payroll period is approved (or nightly), the system generates the internal
   cross-company expense on the host: hours/days × deployment rate × split %. Marked
   `internal_deployment` so it's excluded from vendor expense reports but included in
   project cost and cross-company reports.
5. Deployment ends (or is cancelled) → charges stop; history and cost summary reports
   read directly from `employee_deployments` + the generated charges.

## Decisions — CONFIRMED by client 2026-07-12 (recorded in DECISIONS.md)

1. **Option A confirmed** as the operating model.
2. **Charge basis:** the employee's standard daily/hourly rate; no overhead or margin;
   no inter-company VAT invoice for now (revisit if the gestoría requests it — the
   split-% field still ships so Option C remains available).
3. **Scope of "payroll":** internal management payslips only — hours, rates, deductions,
   net pay for management visibility. Official nóminas (IRPF withholding, Seguridad
   Social contributions) stay with the gestoría; the system never calculates them.
4. **Labor advisor:** client confirms deployments operate under proper group company
   arrangements; the gestoría and labor advisor are aware of the practice.

*(Software-planning input, not legal advice — the employment-law side rests with the
client's gestoría/asesoría laboral, who they confirm are informed.)*
