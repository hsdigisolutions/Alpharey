# AlphaReyCRM — Design Plan

Design direction: minimal and professional, in the spirit of Claude.ai / Notion / Linear.
Simple surfaces, clear typography, generous white space. Light and dark mode from day one.
Every label bilingual: Spanish primary, English secondary. Tool: Figma.

Eleven phases, D0–D10. Design runs ahead of development: D1–D3 must be finished before
development Phase 1 builds its first screen, and each screen batch (D4–D8) is signed off
before the matching development phase starts.

All 26 screens from the requirements are covered; the mapping table at the end shows
where each one lands.

---

## Phase D0 — Inputs and brand intake (2–3 days)

- Brand confirmed: **AlphaRey**. Companies use dummy placeholder names + initials avatars
  until real names, CIFs, and logos arrive — design must treat company name/logo as
  fully dynamic (admin-editable from Settings), never baked into artwork
- Reference board: agreed examples from Claude.ai / Notion / Linear — what "minimal"
  means concretely for this client (density, contrast, warmth)
- Accessibility target set: WCAG 2.1 AA (contrast, focus states, touch targets)
- Device targets confirmed: desktop-first, fully usable on phone and tablet

**Delivered:** brand asset kit + agreed visual direction, one page, signed off.

---

## Phase D1 — Design tokens (1 week)

- **Color**: neutral surface scale (light + dark), single restrained accent, semantic
  set — success/valid green, warning/expiring amber, danger/expired red, leave/info
  blue, missing/disabled grey — the traffic-light language used across compliance,
  attendance, and statuses everywhere
- **Typography**: typeface selection + full scale. The defining problem of this system
  is the **bilingual label pair** — Spanish primary with smaller, muted English below.
  Exact size/weight/color/line-height pairs defined for every context: sidebar item,
  page title, section heading, table column header, form field label, button, KPI card,
  badge, tab, modal title
- **Spacing** grid (4px base), container widths, corner radii, borders, elevation
  (minimal shadows), focus rings
- **Iconography**: single line-icon set (e.g. Lucide), sizing rules
- **Charts**: chart palette derived from tokens, consistent in both themes
- **Motion**: durations/easings for modals, slide panels, toasts (subtle)
- Tokens exported as CSS variables / Tailwind config so design and code share one source

**Delivered:** Figma token library + styleguide page, light and dark, signed off.

---

## Phase D2 — Core component library (2 weeks)

Every component with all variants, both themes, desktop + mobile behavior, and
empty/loading/error/disabled states where applicable:

- **Bilingual label** component (the atom everything else uses)
- Buttons: primary / secondary / ghost / danger, sizes, icon, loading
- Form controls: text, textarea, select, searchable select, date, time, month-year
  picker, currency (EUR), number, toggle, checkbox, radio, file dropzone (drag-drop +
  camera on mobile), show/hide password, inline validation and error states
- **Table system** (the workhorse): sortable headers, filter row, live search, column
  visibility menu, bulk-select bar with actions, pagination (25/50/100), inline-edit
  cell state, export buttons, sticky header, horizontal-scroll mobile mode, empty and
  loading states
- Modal and **slide-over panel** (all create/edit lives here), full-screen on mobile,
  confirmation dialog (including typed-confirmation destructive variant)
- Tabs (detail pages), badges (status, company, "Desplegado" deployment badge with home
  company), status dots, traffic-light indicators
- Cards: KPI card (label pair, value, delta), company card (Welcome), list cards
- Charts: bar, donut, line — styled to tokens
- Calendar grid cell: status dot + hours + project, all five color states, mobile day view
- Timeline (notes/communication), avatar + initials, toasts, notification panel item
- Permission matrix cell: toggle + not-applicable state; preset buttons
- Progress bars (budget, compliance score bar), stat bars, kanban card + column
- Search results dropdown (grouped by entity type)

**Delivered:** Figma component library — the complete kit; nothing in D4–D8 may invent a
new component without adding it here first.

---

## Phase D3 — App shell and navigation (1 week)

- Sidebar: 10 primary items (bilingual pairs), active states, company/role context,
  secondary "apps" menu for Vendors/Vehicles/Proposals/Commission/Leave/Inventory/
  Measurements/Compliance/Audit/Settings
- Header: company name + province, user + role, date, global search bar, notification
  bell + panel, dark/light toggle, ES/EN switcher
- **Mobile**: bottom navigation bar (one click to any module, no sub-menus), header
  condensation, drawer for secondary items
- Page templates: list page, detail page with tabs, single-page report layout,
  two-column layout (Call Panel), settings layout
- Breakpoint behavior documented for every template

**Delivered:** app shell + page templates, both themes, desktop/tablet/mobile — the
frame every screen drops into. Development Phase 1 can start after D3 sign-off.

---

## Phases D4–D8 — Screen designs

Every screen batch includes, per screen: desktop and mobile layouts, all modals and
slide panels belonging to it, empty/loading/error states, dark-mode verification, and
bilingual labels written out (real Spanish/English copy, not lorem ipsum — a shared
copy sheet is maintained from D4 onward).

### Phase D4 — Access & administration (1 week)
- **01 Login** (+ forgot password, reset flows, language switcher)
- **02 Welcome / Company Selector** (greeting, company cards with compliance +
  deployment indicators, group stats bar)
- **04 Companies** (cards grid + 4-tab slide-over: Información inline-edit, the
  13-document Documentos tab, Empleados, Estadísticas; remove-company confirmation flow)
- **17 Permission Matrix** (user list, toggle matrix, presets, copy-from-user picker)
- **25 Audit Logs** (table, filters, statistics panel, export)
- **26 Settings** (all sections: general, email, document alerts, notification rules,
  overtime policies, advance categories, leave categories, locked periods, company
  cards, dropdown options, custom fields, teams, system health)

### Phase D5 — People (1.5 weeks)
- **05 Employees List** (full table framework, import/export, new-employee modal)
- **06 Employee Detail** — all six tabs: Información (personal/employment/wage/
  overtime/bank sections), Documentos (four document groups + custom, per-row controls),
  Asistencia (calendar + monthly summary), Nómina (6-month table + breakdown popup),
  Notas (timeline + add form), Llamadas (history + log form)
- **13 Call Panel** (two-column, filter tabs, indicator system, stats bar, mobile
  click-to-call)
- **22 Leave Management** (requests table, review flow, balances view, adjust balance)

### Phase D6 — Work (1.5 weeks)
- **07 Clients** — list + all six detail tabs (Información, Contactos, Proyectos,
  Facturas, Propuestas, Comunicación)
- **08 Projects List** — table view and kanban view (5 columns, cards)
- **09 Project Detail** — all eight tabs: Resumen (contact cards, budget progress,
  own vs deployed worker counts), Empleados (own + deployed blocks, deploy-from-
  another-company flow), Asistencia, Mediciones (approve/reject), Facturas, Gastos,
  Documentos, Notas y Comunicación (+ project alerts setup)
- **20 Vendors** (list + 4 tabs), **24 Measurements** (list, filters, approval),
  **23 Inventory** (categories, items, movements, issues, project assignments),
  **21 Vehicles** (list + 4 tabs: Información, Asignación, Mantenimiento, Kilometraje)

### Phase D7 — Time & money (1.5 weeks)
- **11 Attendance** — the calendar grid (employees × days, five cell states, deployed-
  employee badges), entry/edit modal with hourly vs project-based modes, monthly summary,
  import flow, mobile one-tap check-in and simplified day view
- **12 Payroll** — main table, breakdown modal (exact layout from the requirements),
  calculate/approve/lock flows, advances section, deployed-employee note lines,
  payslip PDF template
- **10 Invoices** — Ventas/Gastos tabs, detail slide panel with line items and totals
  (optional VAT — blank by default — discount, retention), new-invoice type selector,
  reminders, **invoice PDF template**
- **18 Proposals** (table + detail panel + **proposal PDF template**)
- **19 Commission Reports** (table, adjust/finalize/paid flows)

### Phase D8 — Visibility (1 week)
- **03 Dashboard** (8 KPI cards, 3 charts, quick actions, expiring-documents +
  activity panels)
- **14 Reports** (single-page layout, filter bar, all ten module views incl.
  cross-company deployments; export states; **report PDF template**)
- **15 Today's Report** (6 KPIs, live table with deployed column, pending-actions
  panel, auto-refresh indicator)
- **16 Compliance Center** (summary cards, per-company score bars, detail table,
  row actions)
- Global search results dropdown; notification panel with all notification types;
  email templates (expiry warnings, reminders, overdue, project alerts) — bilingual

---

## Phase D9 — Prototype and client review (1 week)

- Clickable Figma prototype of the core journeys:
  1. Login → Welcome → enter company → Dashboard
  2. Add employee → upload documents → see compliance status change
  3. Log attendance → run payroll → view breakdown → payslip
  4. Deploy an employee cross-company → see badges in attendance → see cross-charge
  5. Create invoice → mark paid → see it in reports
  6. Set permissions for a user → see their restricted view
- Review sessions with the client; feedback log; **one structured revision round**
  across the full screen set

**Delivered:** client-approved design of all 26 screens. **This approval gates
development Phase 6 (Payroll & Finance)** — confirmed requirement in `DECISIONS.md`.

---

## Phase D10 — Handoff and design QA (3–4 days, then ongoing)

- Annotated specs: spacing, behavior notes, state charts per screen
- Token export as Tailwind config / CSS variables (single source of truth with code)
- Asset export: logos, icons, favicons, email-safe logo versions
- Component → Blade component mapping document (design name ↔ code name)
- Copy sheet handoff: every Spanish/English label pair as translation-file-ready content
- **Ongoing design QA**: a review pass on staging at the end of every development phase
  (2–8), checking built screens against the designs before client review

**Delivered:** complete handoff package + design QA cadence for the build.

---

## Screen coverage map

| Screen | Designed in |
|---|---|
| 01 Login | D4 |
| 02 Welcome / Company Selector | D4 |
| 03 Dashboard | D8 |
| 04 Companies | D4 |
| 05 Employees List | D5 |
| 06 Employee Detail (6 tabs) | D5 |
| 07 Clients (6 tabs) | D6 |
| 08 Projects List (table + kanban) | D6 |
| 09 Project Detail (8 tabs) | D6 |
| 10 Invoices | D7 |
| 11 Attendance | D7 |
| 12 Payroll | D7 |
| 13 Call Panel | D5 |
| 14 Reports | D8 |
| 15 Today's Report | D8 |
| 16 Compliance Center | D8 |
| 17 Permission Matrix | D4 |
| 18 Proposals | D7 |
| 19 Commission Reports | D7 |
| 20 Vendors | D6 |
| 21 Vehicles | D6 |
| 22 Leave Management | D5 |
| 23 Inventory | D6 |
| 24 Measurements | D6 |
| 25 Audit Logs | D4 |
| 26 Settings | D4 |

Total design effort: ≈ 9–10 weeks end to end; D1–D3 (≈ 4 weeks) gate development
Phase 1, after which design stays one to two batches ahead of the build.
