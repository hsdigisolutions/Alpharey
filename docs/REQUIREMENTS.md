# VertoCRM — Software Requirements Document
**For:** Fable5
**Project:** VertoCRM — New Build from Scratch
**Version:** Final
**Date:** July 2026

---

# 1. WHAT WE ARE BUILDING

A brand new CRM system built from scratch for a Spanish construction
and field-services group.

The system manages multiple companies, their employees, clients,
projects, documents, attendance, payroll, invoices, and compliance
— all from one clean, simple interface.

**Design direction**
Clean and minimal. Similar feel to Claude.ai, Notion, or Linear.
Simple surfaces, clear typography, generous white space.
Nothing cluttered. Nothing complicated.

**Core UX rule**
Maximum 2 clicks to reach any information.
Single sidebar with no sub-menus.
Tabs inside detail pages instead of separate pages.
Modals or slide panels for all create and edit actions —
never navigate away from the current screen to add or change a record.

**Language rule**
Every single label shows Spanish first, English below it.
Applies to every button, column, badge, form field, and title.

---

# 2. COMPANY STRUCTURE

## Overview

One brand (the group owner) manages multiple companies.

- Currently 5 companies, one per Spanish province
- Admin can add more companies in the future
- Admin can remove a company (serious action — must require
  confirmation step and safety checks before allowing)
- Each company is a construction company operating independently

## What Is Separate Per Company

Each company has its own completely separate:

- Employees and management team
- Company documents (13 official types)
- Attendance records
- Payroll
- Expenses
- Vehicles

## What Is Shared Across All Companies

- Clients — one shared client pool, all companies can work with any client
- Projects — belong to a client, assigned to one company
- Vendors and suppliers
- Proposals and quotations
- Reports — filtered by company, Super Admin sees everything

---

# 3. CROSS-COMPANY EMPLOYEE DEPLOYMENT

## The Situation

Company A (Madrid) has a project but their own employees are
busy, on leave, or not enough people available.

The admin needs to send employees from Company B, C, D, or E
to work on Company A's project temporarily.

## How the System Handles It

- Admin opens a project under Company A
- Admin can assign employees from any other company to that project
- Those employees are clearly marked as "deployed from Company X"
- The project shows its own employees and deployed employees separately
- A deployed employee badge shows their home company

## New Table Required: employee_deployments

Fields:
- id
- employee_id — which employee is being deployed
- home_company_id — the company this employee normally belongs to
- host_company_id — the company receiving the employee
- project_id — which project they are deployed to
- deployment_start (date)
- deployment_end (date, nullable — open-ended if not yet returned)
- billing_method — who pays (see options below)
- rate_during_deployment (decimal) — wage rate applied during deployment
- rate_type — hourly / daily / per_meter
- approved_by (FK → users)
- notes (text, nullable)
- status — active / completed / cancelled
- timestamps

## Salary and Payroll Options During Deployment

Three billing method options. Admin selects one per deployment:

**Option A — Home company pays (suggested default)**
Employee stays on their home company payroll.
Their hours on the host project are logged normally.
An internal cross-company expense is created automatically:
Host company (Company A) is charged the cost of using that employee.
Home company payroll includes those days with a note showing
"Deployed to Company A — Project X."

**Option B — Host company pays**
Employee payroll for the deployment period is processed by
the host company (Company A), not their home company.
Home company payroll excludes those specific dates.
Host company payroll includes those dates.

**Option C — Split cost**
Cost divided between the two companies by percentage or hours.
Each company's payroll or expenses reflects their share.

Fable5 to advise which option is cleanest to implement
for a Spanish construction business context.

## What Appears on Attendance During Deployment

- Employee name
- Home company badge (e.g. "Company B")
- "Deployed" label/indicator
- Project from Company A
- Hours tracked against Company A's project
- Payroll handled per chosen billing method

## Reports for Deployments

- Deployment history: who, from where, to where, dates, cost
- Cross-company cost summary: what each company owes others
- Current active deployments: who is deployed right now

---

# 4. USER ROLES AND ACCESS

## The Three Levels

**Super Admin**
- Full access to all companies with no restrictions
- Can enter any company and view all its data
- Can create and manage Company Admins
- Can add or remove companies from the system
- Sees all reports across all companies

**Company Admin**
- Assigned to one specific company
- Full control within that company
- Creates users inside their company
- Sets and modifies individual permissions for each user
- Cannot see other companies' data

**Custom Users — Managers and Staff**
- Created by the Company Admin
- Each user gets individually configured access
- Two managers inside the same company can have
  completely different permissions from each other
- A user only sees what the Company Admin allows

## Access Limitation — Per User Per Module

Company Admin opens a visual permission panel.
Selects a user. Sees a matrix of modules and actions.
Toggles each cell on or off.

**Modules that can be restricted:**
Employees | Projects | Clients | Invoices | Expenses |
Attendance | Payroll | Documents | Reports | Call Panel |
Proposals | Commission Reports | Vendors | Vehicles |
Leave Management | Measurements | Inventory | Deployments

**Actions per module:**
Ver / View
Crear / Create
Editar / Edit
Eliminar / Delete
Subir / Upload
Descargar / Download
Exportar / Export
Aprobar / Approve

**Example — Company A has two managers:**

| Module           | Manager A        | Manager B       |
|------------------|------------------|-----------------|
| Employees        | Full             | View only       |
| Projects         | Full             | Full            |
| Clients          | Full             | View only       |
| Payroll          | No access        | Full            |
| Invoices         | Full             | View only       |
| Expenses         | Full             | Full            |
| Attendance       | Full             | Full            |
| Documents        | Upload/Download  | View only       |
| Reports          | View only        | Full + Export   |
| Call Panel       | Full             | No access       |
| Deployments      | Full             | View only       |

Admin can change these at any time. Effect is immediate.

**Quick preset buttons:**
- Full Access — enable all toggles at once
- Read Only — view only across everything
- No Access — disable everything
- Copy from another user — clone a user's existing settings

**Database table: user_module_permissions**
- id
- user_id (FK users)
- company_id (FK companies)
- module (varchar)
- can_view (boolean)
- can_create (boolean)
- can_edit (boolean)
- can_delete (boolean)
- can_upload (boolean)
- can_download (boolean)
- can_export (boolean)
- can_approve (boolean)
- granted_by (FK users — who set this)
- timestamps

---

# 5. SCREEN LIST

26 screens total.

| # | Screen Name | Notes |
|---|-------------|-------|
| 01 | Login | Entry point |
| 02 | Welcome / Company Selector | Super Admin only |
| 03 | Dashboard | Per company live overview |
| 04 | Companies | Super Admin only |
| 05 | Employees List | |
| 06 | Employee Detail | 6 tabs |
| 07 | Clients | Shared across companies |
| 08 | Projects List | Table + Kanban |
| 09 | Project Detail | 8 tabs |
| 10 | Invoices | Sale + Expense types |
| 11 | Attendance | Calendar grid |
| 12 | Payroll | Monthly calculation |
| 13 | Call Panel | Two-column layout |
| 14 | Reports | Single page with filters |
| 15 | Today's Report | Live daily snapshot |
| 16 | Compliance Center | Document expiry overview |
| 17 | Permission Matrix | Visual access control |
| 18 | Proposals | Quotations |
| 19 | Commission Reports | Per employee per invoice |
| 20 | Vendors | Suppliers |
| 21 | Vehicles | Fleet management |
| 22 | Leave Management | Requests and balances |
| 23 | Inventory | Equipment tracking |
| 24 | Measurements | Meter-based work |
| 25 | Audit Logs | Activity trail |
| 26 | Settings | System configuration |

---

# 6. SIDEBAR NAVIGATION

Primary (always visible, 10 items max, no sub-menus):

```
Panel           / Dashboard
Empresas        / Companies          (Super Admin only)
Empleados       / Employees
Clientes        / Clients
Proyectos       / Projects
Facturas        / Invoices
Asistencia      / Attendance
Nóminas         / Payroll
Llamadas        / Call Panel
Informes        / Reports
```

Secondary access (via a settings or apps menu):
Vendors | Vehicles | Proposals | Commission Reports |
Leave Management | Inventory | Measurements |
Compliance | Audit Logs | Settings

On mobile the sidebar becomes a bottom navigation bar.
No sub-menus anywhere. One click to any module.

---

# 7. SCREEN SPECIFICATIONS

---

## Screen 01 — Login
URL: /login

Elements:
- Full-screen layout, clean and minimal
- Brand logo centered
- "Bienvenido de vuelta" / "Welcome back" heading
- Email field
- Password field with show/hide toggle
- Recordarme / Remember me checkbox
- Iniciar Sesión / Login button, full width
- Olvidaste tu contraseña / Forgot password link
- Language switcher top right: ES / EN

Post-login routing:
- Super Admin → Welcome screen
- Company Admin → Dashboard (their company pre-selected)
- Manager or Staff → Dashboard (their company pre-selected)

---

## Screen 02 — Welcome / Company Selector
URL: /welcome
Visible to: Super Admin only

Layout:
- "Buenos días, [Name]" greeting
- Current date and time (Spain timezone, Europe/Madrid)
- Cards grid showing all companies (2 or 3 columns)

Each company card shows:
- Company logo or initials avatar
- Company name
- Province
- CIF number
- Active employees count
- Active projects count
- Compliance status indicator: Green / Amber / Red
- Currently deployed employees from other companies (count)
- Acceder / Enter button

Bottom stats bar across all companies:
- Total employees group-wide
- Active projects group-wide
- Documents expiring this month (count)
- Pending invoices total (EUR)
- Active cross-company deployments

---

## Screen 03 — Dashboard
URL: /dashboard

Header bar:
Company name + Province | Logged-in user + role |
Today's date | Notification bell | Dark/Light toggle

KPI Row 1 (4 cards):
- Total Empleados Activos / Active Employees
- Proyectos en Curso / Active Projects
- Facturas Pendientes / Pending Invoices (EUR)
- Documentos por Vencer / Documents Expiring

KPI Row 2 (4 cards):
- Asistencia Hoy / Today's Attendance (present / total)
- Gastos Este Mes / Expenses This Month (EUR)
- Facturación Este Mes / Billing This Month (EUR)
- Empleados Desplegados / Currently Deployed Employees

Charts (3):
- Bar chart: monthly revenue vs expenses, last 6 months
- Donut chart: project status breakdown
- Line chart: attendance trend, last 30 days

Quick Action Buttons:
- Add Employee
- New Project
- Upload Document
- Log Attendance

Bottom panels:
- Left: Documents expiring (list with traffic light + days remaining)
- Right: Recent Activity (audit log feed, last 10 actions)

---

## Screen 04 — Companies
URL: /companies
Visible to: Super Admin only

Cards grid of all companies.

Each card:
- Logo / initials avatar
- Company name and province
- CIF / NIF
- Active employees count
- Active projects count
- Document compliance score as percentage
- Monthly document upload status
- View Details button

Click card → slide-over panel stays on same page.

Company detail panel — 4 tabs:

Tab: Información / Information
Fields:
- Company name
- CIF / NIF
- Province
- Full address (street, city, postal code)
- Phone
- Email
- Website
- Status: Active / Inactive
- Notes
All fields editable inline.

Tab: Documentos / Documents
The 13 official Spanish company document types:

| # | Spanish | English | Frequency |
|---|---------|---------|-----------|
| 1 | CIF / NIF | Tax ID Certificate | One-time |
| 2 | Escritura de Constitución | Company Formation Deed | One-time |
| 3 | Certificado Digital | Digital Certificate | Annual |
| 4 | Seguro de Responsabilidad Civil | Civil Liability Insurance | Annual |
| 5 | Seguro de Accidentes Laborales | Work Accident Insurance | Annual |
| 6 | Plan de Prevención de Riesgos Laborales | Risk Prevention Plan | Annual |
| 7 | Licencia de Actividad | Activity License | Varies |
| 8 | Certificado AEAT — Al Corriente | Tax Compliance Certificate | Monthly |
| 9 | Certificado TGSS — Seguridad Social | Social Security Certificate | Monthly |
| 10 | Declaración IVA — Modelo 303 | VAT Declaration | Monthly |
| 11 | Nóminas y Seguros Sociales TC1/TC2 | Payroll & Social Security Filing | Monthly |
| 12 | Contratos de Trabajo Vigentes | Active Employment Contracts | On change |
| 13 | Formación Artículo 19 PRL | PRL Training Records | Annual |

Per document row:
- Status indicator: Green (valid) / Amber (expiring) / Red (expired) / Grey (missing)
- Upload date
- Expiry date
- Days remaining
- Version number
- Upload button (drag and drop)
- Download button
- Yes / No field where applicable
- Notes

Tab: Empleados / Employees
List of employees in this company.
Click name navigates to Employee Detail.

Tab: Estadísticas / Statistics
KPI cards: headcount, active projects, billing this month.

---

## Screen 05 — Employees List
URL: /employees

Header:
- Search by name, code, NIF
- Filters: Status | Department | Designation | Wage type | Company (Super Admin)
- New Employee button → opens modal
- Import Excel button
- Export Excel / PDF buttons

Table columns (sourced from employees table):
Employee Code | Full Name | Company | Designation |
Wage Type | Wage Rate | Base Salary | Commission % |
Status | Document Status | Actions

Table features:
Sort by any column | Multi-field filter | Live search |
Column visibility per user | Bulk select | Pagination 25/50/100

---

## Screen 06 — Employee Detail
URL: /employees/{id}

Header: Full name | Employee code | Status badge | Company badge | Designation

Six tabs:

### Tab 1: Información / Information

Personal section:
- Full name
- NIF / DNI / NIE / Passport number
- Email
- Mobile
- Phone (landline)
- City
- Full address

Employment section:
- Employee code (auto-generated, editable)
- Company
- Designation / Position
- Team leader (linked to another employee)
- Joining date
- Leaving date
- Active: Yes / No
- Is contracted: Yes / No
- Default check-in time (default 09:00)
- Default check-out time (default 17:00)

Wage section:
- Wage type: daily / hourly / monthly / per_meter
- Wage rate (decimal)
- Base salary
- Daily wage
- Per meter rate
- Commission percent
- Payment method: bank_transfer / cash / cash_via_supervisor

Overtime section:
- Overtime policy (linked to overtime_policies table)
- Supervisor overtime policy (separate policy for when acting as supervisor)

Bank section:
- IBAN
- Bank name

Other:
- Has driving license: Yes / No
- Has company vehicle: Yes / No
- Notes

### Tab 2: Documentos / Documents

Personal documents:
- DNI — Yes/No toggle | Upload | Expiry date | Status
- NIE — Yes/No toggle | Upload | Expiry date | Status
- Pasaporte / Passport — Yes/No toggle | Upload | Expiry date | Status
- Permiso de Conducir / Driving License — Yes/No toggle | Upload | Expiry

Employment documents:
- Contrato de Trabajo / Employment Contract — Upload | Expiry | Status
- Nómina / Salary Slip — Yes/No toggle (received this month?)
- Seguridad Social / Social Security — Yes/No toggle | Upload
- Datos Bancarios / Bank Details — Yes/No toggle

Training documents:
- Formación Artículo 19 — Yes/No | Upload | Expiry | Status
- Formación PRL — Yes/No | Upload | Expiry | Status
- Certificaciones / Other Certifications — Upload | Expiry

Medical documents:
- Aptitud Médica / Medical Fitness Certificate — Yes/No | Upload | Expiry
- Salud Laboral / Occupational Health Record — Yes/No | Upload | Expiry

Custom documents:
- Free label and upload slot for any additional document type

Per document row shows:
Document name | Yes/No toggle | File name | Issue date |
Expiry date | Status (traffic light) | Version | Upload | Download | Delete

### Tab 3: Asistencia / Attendance
- Month and year picker (defaults to current month)
- Calendar grid showing attendance per day
- Each cell: status dot + hours worked + project name
- Click any cell to edit that day's attendance in a modal

Monthly summary below the calendar:
Total days present | Total hours | Overtime | Absences | Leave days

### Tab 4: Nómina / Payroll
Last 6 months payroll table:
Month | Days | Hours | Salary | Basic Salary |
Reimbursements | Advance Deductions | Overtime Pay |
Deductions | Net Amount | Status | Payment Method

Click any month row → breakdown popup:
- Base salary / wage
- Attendance days calculation
- Attendance hours calculation
- Reimbursements
- Overtime pay
- Gross total
- Advance deductions
- Other deductions
- Net pay
- Download payslip PDF button

### Tab 5: Notas / Notes
Chronological timeline of all notes.
Note types: general / reminder / issue / call

Each note:
- Date and time
- Written by (user name)
- Note type badge
- Note text
- Attached file (if any)
- Edit / Delete

Add note form:
- Note type (dropdown)
- Note text
- Attach file (optional)
- Date and time (defaults to now)
- Save

### Tab 6: Llamadas / Calls
Call history for this employee:
- Called at (date and time)
- Called by (user name)
- Remarks / notes from call
- Follow-up date

Log new call button:
- Date and time (default: now)
- Remarks
- Follow-up date (optional)

---

## Screen 07 — Clients
URL: /clients

Clients are shared. All companies see the same client list.
Projects show a company badge to indicate which company handles each project.

Header:
- Search by name, company name, NIF
- Filter: Client type | Active / Inactive
- New Client button → modal
- Export button

Table columns (from clients table):
Name | Company Name | NIF | Client Type | Contact Person |
Phone | Mobile | Email | City | Payment Terms | Active | Actions

Client detail — 6 tabs:

### Tab 1: Información
Name | Company name | NIF | VAT number |
Client type: company / private / municipality / other |
Contact person | Phone | Mobile | Email |
Address | City | Postal code | Country | Website |
Bank account | Payment terms (default net30) |
Industry | Company size |
Preferred contact method: email / phone / mobile / other |
Active: Yes / No | Notes

### Tab 2: Contactos / Contacts
Multiple contacts per client:
Name | Designation | Email | Phone | Alternate phone

### Tab 3: Proyectos / Projects
All projects for this client:
Project name | Company badge | Status | Billing type | Start | End | Budget

### Tab 4: Facturas / Invoices
All invoices across all projects for this client:
Number | Project | Date | Due date | VAT % | Total | Status | PDF

### Tab 5: Propuestas / Proposals
All proposals for this client:
Number | Project | Date | Expiry | Total | Status

### Tab 6: Comunicación / Communication
Notes and client communication chronological timeline.
Types: call / meeting / email / internal note
Fields: Date | Type | Created by | Text | File attachment

---

## Screen 08 — Projects List
URL: /projects

Header:
- Search by name, code, client
- Filters: Company | Client | Status | Project type | Priority | Billing type
- View toggle: Table / Kanban
- New Project button → modal
- Export button

Table columns (from projects table):
Code | Name | Company | Client | Project Type | Status | Priority |
Billing Type | Workers (own + deployed) | Total Hours | Total Meters |
Start | End | Budget | Outsourced | Actions

Kanban view:
5 columns: Active | In Progress | Completed | Cancelled | On Hold
Each card: name + client + company badge + worker count + priority indicator

---

## Screen 09 — Project Detail
URL: /projects/{id}

Header:
Project name + code | Company badge | Client (clickable link) |
Status badge | Priority badge | Edit button

Info bar (one line):
Type | Billing type | VAT % | Start → End | Budget | Jefe de obra name

Eight tabs:

### Tab 1: Resumen / Summary
- Total workers (own company employees)
- Deployed workers (from other companies, labeled by home company)
- Total hours worked / estimated hours
- Total meters worked / estimated meters
- Budget used vs total (progress bar)
- Actual cost to date
- Remaining budget
- Contact cards:
  - Jefe de obra: name / phone / email
  - Encargado: name / phone / email
  - Seguridad: name / phone / email
- Supervisors list
- Coordinator
- Outsourced flag and outsourced employee if applicable
- Google Drive link
- Document URL
- Payment terms | Forma de pago | Fecha de cobro
- Invoice rules (pre_invoice_rule, invoice_rule, due_rule)
- Color code
- Description

### Tab 2: Empleados / Workers
Own company workers:
Name | Wage type | Standard rate | Project rate override |
Hours on project | Meters | Total cost

Deployed workers section (separate block):
Name | Home company badge | Deployment dates |
Rate during deployment | Billing method | Hours logged | Total cost

Actions: Add worker | Remove worker | Set project rate | Deploy from another company

### Tab 3: Asistencia / Attendance
All attendance records for this project.
Date range picker.

Table:
Date | Employee | Home Company | Check-in | Check-out |
Break hours | Hours worked | OT hours | Wage type | Wage rate |
Total amount | Status | Work mode | Exception | Notes

### Tab 4: Mediciones / Measurements
Meter-based work tracking:
Date | Employee | Quantity | Unit |
Measurement type (length / area / volume / weight) |
Approved: Yes / No | Notes

Approve / Reject workflow.
Approved measurements feed into billing.

### Tab 5: Facturas / Invoices
All invoices for this project (sale and expense):
Type | Number | Date | Due date | Billing type |
Billing period | VAT % | Discount | Retention % | Total | Status | PDF

### Tab 6: Gastos / Expenses
All expenses for this project:
Type (albaran / factura / ticket / other) | Date | Vendor |
Category | Amount | VAT % | Status | Employee | File | Notes

### Tab 7: Documentos / Documents
Project-specific files:
Type | File name | Upload date | Expiry date | Status | Download | Delete

### Tab 8: Notas y Comunicación / Notes and Communication
All notes and client communication, chronological timeline.
Types: Internal note / Client call / Client email / Meeting / Message

Add note:
- Type (dropdown)
- Date and time (default: now)
- Text (required)
- Attach file (optional)
- Save

Notes cannot be deleted once saved (audit requirement).

Project alerts:
- Alert type: budget / deadline / progress / custom
- Title and message
- Scheduled date
- Email recipients and CC
- Status: pending / sent / failed

---

## Screen 10 — Invoices
URL: /invoices

Two tabs on one page:

Tab: Ventas / Sales
Money coming in. Invoices issued to clients.

Columns:
Number | Client | Project | Company | Invoice date | Due date |
Invoice type (pre / final) | Billing type | Billing period |
VAT % | Discount | Retention % | Total | Payment status | Status | PDF | Actions

Tab: Gastos / Expenses
Money going out. Invoices from vendors or expense records.

Columns:
Number | Type (albaran / factura / ticket / other) | Vendor |
Project | Company | Date | Due date | Amount | VAT % | Total |
Payment status | Payment method | Actions

Shared:
- Search and filters on both tabs
- Date range filter
- Company filter (Super Admin)
- Export Excel and PDF buttons
- New Invoice button → modal with type selector

Invoice detail (slide panel):
- Invoice number
- Invoice type: Sale or Expense
- Sub-type: Pre-invoice / Final invoice
- Client or Vendor
- Project (optional link)
- Invoice date | Due date
- Billing type | Billing period
- Line items table: Description | Qty | Unit price | Total per line
- Subtotal
- VAT percent (default 21%)
- VAT amount (calculated)
- Discount type: percent or fixed | Discount value
- Retention percent
- Total amount
- Status: Draft / Sent / Paid
- Payment status: Unpaid / Partial / Paid / Pending
- Payment date | Payment method
- Notes
- Download PDF button

Invoice reminders (per project):
Project | Month | Period start/end | Reminder days |
Reminder emails | Last sent date

---

## Screen 11 — Attendance
URL: /attendance

Header:
- Company filter (Super Admin)
- Month and year picker (defaults to current month)
- Mode toggle: Hourly / Project-based
- New Entry button → modal
- Import Excel button
- Export Excel button

Main view: Calendar grid
- Rows: Employees
- Columns: Days of the month (1–31)
- Each cell: status dot + hours number + project name
- Cell colors: Green (present) | Amber (late or early leave) | Red (absent) | Blue (leave) | Grey (weekend)
- Click any cell → edit modal for that attendance record

Attendance entry fields (from attendance table):
- Employee
- Date
- Project
- Mode: Hourly or Project-based
- Check-in time (if hourly)
- Check-out time (if hourly)
- Break hours (default 1 hour)
- Deduct break from hours: Yes / No
- Hours worked (auto-calculated if hourly, manual if project-based)
- Overtime hours (manual override)
- Status: present / absent / late / early_leave
- Wage type snapshot: hour / day / month
- Wage rate snapshot
- Hourly rate snapshot
- Total amount for that day
- Manual wage override: Yes / No
- Is paid: Yes / No
- Is exception: Yes / No → if yes, exception reason (text)
- Work mode
- Notes

Deployed employees in attendance:
- Appear in the grid with a home company badge
- Their hours are logged against the host project
- Payroll routing is handled per their deployment billing method

Monthly summary table below the grid:
Employee | Company | Days Present | Hours | Overtime |
Absences | Leave days | Total Wage

---

## Screen 12 — Payroll
URL: /payroll

Header:
- Company filter (Super Admin)
- Month and year picker (defaults to current month)
- Calculate Payroll button
- Approve All button
- Lock Period button
- Export Excel button
- Export PDF (all payslips) button

Main table (from payrolls table):
Employee | Company | Attendance Days | Attendance Hours |
Salary | Basic Salary | Wage Type | Wage Rate |
Reimbursements | Advance Deductions | Overtime Pay |
Deductions | Net Amount | Status | Payment Method | Actions

Status values: Pending (amber) | Paid (green)

Per row actions:
- View breakdown (modal)
- Edit manual adjustments
- Mark as paid
- Download payslip PDF

Payroll breakdown modal per employee:
```
Base Salary / Wage              EUR X,XXX.00
Attendance Days  (X days)         EUR XXX.00
Attendance Hours (X hrs)          EUR XXX.00
Reimbursements                    EUR XXX.00
Overtime Pay     (X hrs)          EUR XXX.00
─────────────────────────────────────────────
Gross Pay                       EUR X,XXX.00

Advance Deductions              - EUR XXX.00
Other Deductions                - EUR XXX.00
Manual Additions                + EUR XXX.00
─────────────────────────────────────────────
NET PAY                         EUR X,XXX.00
```

Notes on deployed employees in payroll:
- If billing method is Option A (home pays):
  Home company payroll shows those days with note
  "Deployed to Company X — cost transferred"
  Host company expense shows internal deployment cost

- If billing method is Option B (host pays):
  Home company payroll excludes those dates
  Host company payroll includes those dates

- If billing method is Option C (split):
  Both companies share the cost per configured percentage

Salary Advances section:
Table: Employee | Amount | Category | Reason |
Status | Request date | Payment date | Payroll month

Status: pending / approved / rejected / deducted
7 default advance categories (configurable in settings)

Worker Project Expenses:
Expenses tagged to an employee and a project appear in
their payroll calculation for that month.
Shown separately as "Worker project expenses" in the breakdown.

---

## Screen 13 — Call Panel
URL: /calls

Two-column layout:

Left column — Employee list:
- Search employees
- Filter tabs: All | Pending follow-up | Not contacted this week
- Each row: Avatar | Name | Company | Last contacted | Follow-up date
- Indicator: Red (follow-up overdue) | Amber (due today) | Green (ok)

Right column — Call log for selected employee:
- Employee info card: name | mobile | phone | company
- Call button (opens phone on mobile)

Call history for this employee (from employee_call_logs):
- Called at (date and time)
- Called by (user name)
- Remarks from the call
- Follow-up date

Add call log form (always visible on right column):
- Called at (default: current date and time)
- Remarks (text area)
- Follow-up date (date picker, optional)
- Save button

Top stats bar:
- Calls made today
- Pending follow-ups total
- Workers not contacted this week

---

## Screen 14 — Reports
URL: /reports

Single page. All reporting in one place. No separate pages per report.

Always-visible filter bar at top:
[ Company ] [ Module ] [ Date From ] [ Date To ] [ Export PDF ] [ Export Excel ]

Report modules available in the module dropdown:

**Employees**
Total | Active | Inactive | On leave | By designation | By wage type

**Attendance**
Total records | Total hours | Overtime | Absences |
By employee or by project | Daily trend chart

**Payroll**
Total payroll | Average net pay | Highest | Lowest |
Per employee table | Month-to-month comparison chart |
Advance deductions summary

**Financial**
Sales invoices total | Expense invoices total |
Net position | Unpaid invoices list | Expense by category |
Chart: revenue vs expenses (6 months)

**Documents**
Expired | Expiring in 30 days | Expiring in 60 days |
Expiring in 90 days | Valid | Missing |
Broken down by company and document type

**Projects**
Active | Completed | Cancelled | By company |
Hours per project | Budget used vs remaining |
Workers per project (own + deployed)

**Commission**
Per employee per project per invoice |
Commission percent | Calculated amount |
Original vs adjusted | Finalized | Paid
Export PDF and Excel

**Timesheet**
Hours per employee per project per week or month

**Cross-Company Deployments**
Who was deployed | From which company | To which company |
Project | Dates | Duration | Cost | Billing method

**Today's Report**
Links to Screen 15

---

## Screen 15 — Today's Report
URL: /today
Auto-refreshes every 5 minutes.

KPI cards (6):
- Total Workers (company headcount)
- Active Today (checked in or assigned to project today)
- On Leave Today
- Absent Today
- Hours Logged Today
- Pending Follow-up Calls

Today's Attendance Table:
Employee | Company | Home Company (if deployed) | Project |
Check-in | Check-out | Hours | Status

Pending Actions Panel:
- Documents expiring this week (list with entity name and type)
- Follow-up calls due today (list with employee name)
- Payroll approvals waiting
- Advances pending approval

---

## Screen 16 — Compliance Center
URL: /compliance

Purpose: Central view of all document statuses across all companies.

Summary cards:
- Green: Valid documents (count)
- Amber: Expiring in 30 days (count)
- Amber: Expiring in 60 days (count)
- Amber: Expiring in 90 days (count)
- Red: Expired (count)
- Grey: Missing / not uploaded (count)

Per-company compliance score bar:
```
Company A — Madrid       ████████░░  82%  Amber
Company B — Barcelona    ██████████  97%  Green
Company C — Valencia     █████░░░░░  54%  Red
Company D — Sevilla      █████████░  91%  Green
Company E — Bilbao       ████████░░  80%  Amber
```

Click any company row to filter the detail table below.

Filter bar: Company | Document type | Entity type | Status

Detail table:
Entity Name | Entity Type | Company | Document Type |
Upload date | Expiry date | Days remaining | Status | Action

Actions per row:
- Upload renewal
- Send reminder
- Mark as exempt

---

## Screen 17 — Permission Matrix
URL: /admin/permissions
Visible to: Super Admin and Company Admin

Left column:
List of users in the selected company.
Avatar | Name | Role | Status badge.
Click a user to select.

Right panel for selected user:
Header: "[User Name] — Permisos / Permissions"

Visual matrix:

| Module         | View | Create | Edit | Delete | Upload | Download | Export | Approve |
|----------------|------|--------|------|--------|--------|----------|--------|---------|
| Empleados      |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   ◉     |
| Proyectos      |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   —     |
| Clientes       |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   —     |
| Facturas       |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   ◉     |
| Gastos         |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   ◉     |
| Asistencia     |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   —     |
| Nóminas        |  ◉   |   ◉    |  ◉   |   —    |   —    |   ◉      |   ◉    |   ◉     |
| Documentos     |  ◉   |   —    |  —   |   ◉    |   ◉    |   ◉      |   ◉    |   ◉     |
| Informes       |  ◉   |   —    |  —   |   —    |   —    |    —     |   ◉    |   —     |
| Llamadas       |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   —    |   —     |
| Despliegues    |  ◉   |   ◉    |  ◉   |   ◉    |   —    |    —     |   ◉    |   ◉     |

◉ = toggle switch (on or off). — = not applicable for that module.

Quick preset buttons:
- Acceso Completo / Full Access
- Solo Lectura / Read Only
- Sin Acceso / No Access
- Copiar de... / Copy from another user

Save Changes button — fixed at the bottom of the right panel.

---

## Screen 18 — Proposals
URL: /proposals

Table (from proposals table):
Proposal Number | Client | Project | Date | Expiry date |
Description | Estimated Qty | Estimated Total | Total Amount |
VAT % | Status | PDF | Actions

Status values: Draft | Sent | Approved | Rejected

Proposal detail (slide panel):
- Proposal number
- Client
- Project (optional)
- Proposal date
- Expiry date
- Description
- Line items: Description | Qty | Unit price | Total | VAT %
- Estimated quantity and total
- Total amount
- VAT percent (default 21%)
- Status
- Notes
- Export PDF button

---

## Screen 19 — Commission Reports
URL: /commission

Table (from commission_report_entries):
Report Month | Employee | Project | Invoice |
Commission % | Invoice Total | Amount Paid | Commission Amount |
Original Commission | Adjusted Commission | Adjustment Reason |
Is Finalized | Finalized by | Is Paid | Paid at | Notes

Filters: Month | Employee | Project | Finalized | Paid

Actions:
- Finalize (locks the entry, requires confirmation)
- Adjust commission (enter new amount and reason)
- Mark as paid
- Export PDF
- Export Excel

---

## Screen 20 — Vendors
URL: /vendors

Table (from vendors table):
Name | Company Name | NIF | Phone | Email | City | Active

Vendor detail — 4 tabs:

Tab: Información
Name | Company name | NIF | Phone | Alternate phone | Email |
Address | Area | City | Country | Postal code | Bank account |
Payment terms | Active

Tab: Contactos / Contacts
Name | Position | Phone | Email | Is primary | Notes

Tab: Condiciones de Pago / Payment Terms
(from vendor_payment_terms)
Term name | Days | Discount percentage | Discount days |
Is default | Description

Tab: Gastos / Expenses
All expenses linked to this vendor.

---

## Screen 21 — Vehicles
URL: /vehicles

Table (from vehicles table):
Plate number | Brand | Model | Year | Ownership |
Assigned employee | Fuel type | ITA expiry | Insurance expiry | Active

Vehicle detail — 4 tabs:

Tab: Información
Plate number | Brand | Model | Year |
Ownership: company / employee | Assigned to employee |
Active | Fuel type: petrol / diesel / electric / hybrid |
Color | VIN number | Insurance policy number |
Insurance expiry date | ITA expiry date | Purchase date |
Current mileage | Last oil change mileage | Last oil change date |
Oil change interval KM | Next service date |
Last tyre change date | Last tyre change mileage |
Maintenance cost total | Maintenance notes

Tab: Historial de Asignación / Assignment History
(from vehicle_history)
Employee | Assigned from | Assigned to | Notes

Tab: Mantenimiento / Maintenance
(from vehicle_maintenance_histories)
Maintenance type | Date | Vehicle KM | Description |
Tyre position | Created by

Tab: Kilometraje / Mileage
(from vehicle_mileage_histories)
Mileage value | Recorded at | Updated by

---

## Screen 22 — Leave Management
URL: /leave

Table (from leaves table):
Employee / User | Leave type | Start date | End date | Total days |
Reason | Status | Reviewed by | Review date | Review notes | Attachment

Status: pending / approved / rejected / cancelled

Leave Balances view:
User | Leave type | Year | Allocated | Used | Pending | Carried over | Remaining

Leave categories (8 seeded defaults, configurable):
Casual (12 days) | Annual (20 days) | Sick (10 days) |
Maternity (90 days) | Paternity (15 days) |
Unpaid | Compensatory | Other

Actions: Approve | Reject | Cancel | Adjust balance

---

## Screen 23 — Inventory / Equipment
URL: /inventory

Equipment categories:
Name | Description | Active | Item count

Items table (from equipment_items):
Category | Name | SKU | Type: safety / tool / machine |
Unit | Total stock | Available stock | Active

Stock movements (from equipment_stock_movements):
Item | Movement type: stock_in / issue / return / adjustment / damaged |
Quantity | Balance after | Employee | Project | Notes

Employee issues (from employee_equipment_issues):
Employee | Item | Issued qty | Returned qty |
Issue date | Expected return | Return date |
Status: open / partially_returned / returned

Project assignments (from equipment_project_assignments):
Item | Project | Quantity | Start date | End date | Status

---

## Screen 24 — Measurements
URL: /measurements

Table (from measurements table):
Date | Project | Employee | Quantity | Unit |
Measurement type: length / area / volume / weight |
Approved | Notes

Filters: Project | Employee | Date range | Approval status

Approve workflow:
Authorized user reviews measurement entries and approves or rejects.
Approved measurements feed into project billing calculations.

---

## Screen 25 — Audit Logs
URL: /admin/audit-logs

Visible to: Super Admin and Company Admin only.

Table (from audit_logs table):
User name | User email |
Action: created / updated / deleted / viewed / exported |
Module | Entity name | Model type | Model ID |
Description | Old values | New values |
IP address | User agent | Request method | Timestamp

Filters: Action | Module | User | Date range

Statistics panel:
Action count by type | By user | By module

Export to CSV.

Cannot be edited or deleted under any circumstances.

---

## Screen 26 — Settings
URL: /admin/settings

Sections:

General:
App name | Default language | Timezone (default: Europe/Madrid) |
Default VAT percent (default: 21%) | Session timeout (default: 120 min)

Email:
SMTP host | Port | Username | Password | From name | From email

Document alerts:
Expiry warning days: 30 / 60 / 90 |
Monthly document upload reminder day of month

Notification rules:
Per role — which notification types are enabled or disabled.
Types: document expiry | payroll ready | invoice overdue |
call follow-up | advance pending | leave pending | deployment alert

Overtime policies (from overtime_policies):
Name | Type: percentage / fixed_hourly / accumulate_days / none |
Rate | Daily threshold hours (default 9.00) |
Accumulate hours per day (default 8.00) | Notes

Advance categories (from advance_categories):
7 default categories | Name | Slug | Color | Active

Leave categories (from leave_categories):
8 default categories | Key | Name | Default allocation | Active

Locked periods (from locked_periods):
Year + Month → Locked (prevents editing past payroll)

Company cards (from company_cards):
Name | Last 4 digits | Notes | Active
Used in expenses when payment method is company card.

Dropdown options (from dropdown_seeder_options):
Configurable values for fields across the system.
Key | Value | Label | Active | Sort order

Custom fields (from custom_fields):
Model type (employee / project / client / etc.) |
Field name | Unique key |
Type: text / number / date / dropdown / checkbox |
Options (if dropdown) | Hidden

Teams (from teams):
Name | Description | Team leader | Manager | Status
Used for grouping users within a company.

System health:
Queue status | Mail test | Database connection | Storage check

---

# 8. FULL DATABASE TABLE LIST

New build. Clean schema. No migration debt.
Fable5 designs from scratch using these as requirements.

Authentication and Access:
users | roles | permissions | role_permission | user_role |
user_module_permissions | teams | team_user | team_permission |
role_field_permissions | field_visibilities | user_column_settings |
personal_access_tokens | sessions | cache | jobs | failed_jobs |
password_reset_tokens

Companies and Brand:
brands | companies

Employees and HR:
employees | employee_salary_history | employee_wage_rates |
employee_notes | employee_nicknames | employee_call_logs |
employee_vehicle_assignments

Cross-Company Deployments:
employee_deployments

Documents (unified, polymorphic):
documents

Clients:
clients | client_contacts

Projects:
projects | project_employee_rates | alerts | report_remarks

Attendance and Production:
attendance | attendance_logs | measurements |
production_tasks | task_progress | task_templates |
nickname_reconciliations

Finance:
expenses | expense_categories | expense_line_items |
invoices | payments | proposals | invoice_reminders |
commission_report_entries | invoice_settlement_works |
employee_settlements | company_cards

Payroll:
payrolls | advances | advance_categories |
overtime_policies | locked_periods

Leave:
leaves | leave_balances | leave_categories

Vendors and Vehicles:
vendors | vendor_contacts | vendor_payment_terms |
vehicles | vehicle_history | vehicle_maintenance_histories |
vehicle_mileage_histories

Inventory:
equipment_categories | equipment_items |
equipment_stock_movements | employee_equipment_issues |
equipment_project_assignments

System:
notifications | notification_role_rules | audit_logs | settings |
custom_fields | custom_field_values | dropdown_seeder_options

---

# 9. LANGUAGE SYSTEM

Every single element in the interface shows both languages.

Format:
```
Empleados             ← Spanish: main label, normal size
Employees             ← English: below, smaller text, muted color
```

Applied to: sidebar items | page titles | table column headers |
form field labels | button text | modal titles | KPI card labels |
status badges | dropdown options | error and success messages

Language toggle in the top header area: ES / EN
User preference is saved per account.
System is architected to support additional languages later (French, etc.).

---

# 10. DATA MANAGEMENT STANDARDS

Every table in the system supports:
- Sort by any column ascending and descending
- Filter by multiple fields simultaneously
- Live search (results update as the user types)
- Column visibility toggle saved per user
- Bulk select rows and apply bulk actions
- Pagination: 25 / 50 / 100 rows per page (user selectable)
- Export current filtered view to Excel
- Export current filtered view to PDF

Import from Excel:
- Employees: downloadable template, upload to create records
- Attendance: downloadable template, upload to bulk-log

Inline editing:
Click any text field in a table row → edit directly →
Enter to save, Escape to cancel.

Create and edit always in a modal or slide panel.
Never navigate to a separate page to add or change a record.

---

# 11. GLOBAL SEARCH

Search bar in the top header.
Searches the entire system simultaneously.

Searchable entities:
- Employees: name, employee code, NIF
- Projects: name, project code, client name
- Clients: name, company name, NIF
- Invoices: invoice number, client name
- Documents: document name, document type
- Companies: name, province
- Vendors: name, NIF
- Proposals: proposal number, client name
- Deployed employees: employee name, project name

Results appear as a dropdown grouped by type.
Click any result to navigate directly to that record.

---

# 12. NOTIFICATIONS

In-app (bell icon in header with unread count):
- Document expiring in 90 / 60 / 30 days
- Document expired
- Monthly document not yet uploaded (by 5th of month)
- Payroll ready to process
- Invoice overdue
- Follow-up call due today
- Advance pending approval
- Leave request pending approval
- Project alert triggered (budget / deadline / progress)
- Cross-company deployment created or ended

Email (automatically triggered):
- Document expiry warnings at 90, 60, and 30 days
- Monthly document upload reminder (configurable day)
- Invoice overdue notice
- Call follow-up reminder
- Project alerts sent to configured recipients

Notification rules per role:
Admin can configure which notification types are active or disabled
for each role.

---

# 13. SECURITY

Login:
- Email and password
- Session timeout (configurable, default 120 minutes)
- Remember me option (30 days)
- Password reset by email link
- Architecture ready for two-factor authentication in future

Authorization:
- Every request validates role and module permissions
- Company data is fully isolated: users never see other companies
- Super Admin is the only role with cross-company access

Audit trail:
- Every action logged: who, what, which record, when, IP
- Logs cannot be edited or deleted
- Visible only to Super Admin and Company Admin

Data and storage:
- All hosting in EU region (GDPR requirement, Spanish clients)
- Employee personal data encrypted at rest (NIF, salary, bank details)
- Document files stored in a private bucket (not publicly accessible)
- Employees and clients use soft delete (never hard deleted)

---

# 14. MOBILE

All 26 screens work on phones and tablets.

Adaptations:
- Sidebar becomes bottom navigation bar on mobile
- Tables scroll horizontally
- Modals open full screen
- Calendar grid shows simplified day view
- Charts reduce to key numbers on small screens
- Document upload supports camera capture on mobile
- Attendance check-in and check-out works with one tap on mobile

---

# 15. QUESTIONS FOR CLIENT

Please answer before design and development begin:

1. What are the exact names of the 5 companies?
2. What Spanish province is each company in?
3. What is the brand / group name?
4. Brand logo file and 5 company logo files?
5. Which day of the month should the monthly document reminder send?
6. How many days before expiry should document warnings start?
   (suggested defaults: 30, 60, 90)
7. Default VAT percentage? (standard Spain: 21%)
8. Which day of the month is payroll typically processed?
9. What email service will be used? (SendGrid / Mailgun / SMTP)
10. Where will the system be hosted? (must be EU region for GDPR)
11. What domain name will the CRM run on?
12. Is there existing data to import? (employee list, client list, etc.)
13. For cross-company deployments: which billing method is preferred?
    Option A — home company pays and charges host company internally
    Option B — host company processes payroll for deployment period
    Option C — split cost by percentage
14. Are there any document types not covered by the 13 standard Spanish
    company documents that the business requires?
15. Session timeout preference? (default suggestion: 120 minutes)

---

# 16. SUMMARY

What this system manages:
- 1 brand with 5 companies (more can be added, removed with safety checks)
- Each company: separate employees, documents, payroll, attendance, vehicles
- Shared across companies: clients, projects, vendors, proposals
- Cross-company employee deployment with payroll tracking (new feature)
- 13 official Spanish company documents per company (4 monthly, rest annual)
- Employee documents with Yes/No confirmation fields and expiry tracking
- Two types of invoices: Sale and Expense, on one screen
- Attendance: hourly mode (check in/out) and project-based mode
- Payroll: auto-calculated from attendance with manual add/subtract
- Worker project expenses included in payroll calculation
- Call panel: log calls to workers, set follow-up dates
- Today's report: live daily snapshot of all workers and their status
- Full reports section: all report types on one page with filters and export
- Compliance center: traffic-light document expiry dashboard
- Visual permission matrix: per-user, per-module, per-action access control
- Full audit trail: every action logged, cannot be deleted

Total screens: 26
Primary language: Spanish
Secondary label: English (on every single label)
Design direction: Clean, minimal, professional — similar to Claude.ai

---

End of Document
Version Final — July 2026
Based on: all client conversations and full field-level analysis
of the reference codebase (87 tables, 73 models, 61 existing pages)
