---
name: alpharey-development
description: AlphaRey CRM development conventions — tenancy, authorization gates, audit logging, VAT enum, bilingual system, form requests, encryption, file storage, testing requirements. Read BEFORE writing, editing, or reviewing any PHP or Vue file in this project. No exceptions.
---

# SKILL: AlphaRey CRM — Development Conventions

## What this skill is for
Every time you write, edit, or review any PHP or Vue file in this
project — read this skill first. No exceptions. These rules are
non-negotiable and apply to every line of code.

> Installed 2026-07-13, adapted to the implemented codebase: code examples
> reference the real Phase 0 APIs (`BelongsToCompany`, `CurrentCompany`,
> `Auditable`, `VatRate`, `<Bilingual k>`).

---

## Project at a Glance
```
Brand:      AlphaRey
Domain:     alpharey.com (Mukhost cPanel VPS, London UK)
Stack:      Laravel 12 + Vue 3 + Inertia.js + Tailwind CSS + MySQL
Auth:       Session-based (no API tokens, no Sanctum)
PHP:        8.3 on production, 8.2 locally (avoid 8.3-only syntax)
Testing:    Pest + Larastan level 6 + Pint
Deploy:     GitHub Actions → SSH → rsync → Mukhost
No:         Docker, Redis, Node on server, Livewire, SSR
```

Full spec: `docs/REQUIREMENTS.md`
All decisions: `docs/DECISIONS.md` — check before assuming any default.
Current status: `CLAUDE.md` — check before starting any phase.
UI rules: the `alpharey-design` skill.

---

## Rule 1 — Tenancy (Most Critical)

Every company-owned model uses the `App\Models\Concerns\BelongsToCompany`
trait. It adds the global `CompanyScope` (default-deny: guests see zero
rows) and auto-fills `company_id` on create from the active company.

```php
// CORRECT — trait handles isolation automatically
class Employee extends Model
{
    use BelongsToCompany;
}

// Company context comes ONLY from the resolver (user / SA session selection)
$companyId = app(\App\Support\CurrentCompany::class)->id(); // ✅
$companyId = request('company_id');                          // ❌ NEVER

// company_id is NEVER in $fillable on company-owned models
// System code (importers, scheduler) opts out EXPLICITLY:
Employee::acrossAllCompanies()->…
```

Shared models (Clients, Vendors, Proposals) explicitly omit the trait —
they are cross-company by design (REQUIREMENTS.md §2).

Route model binding must not leak existence:
```php
// Another company's employee → 404 (the global scope handles this), not 403
```

Tenancy isolation tests are required for every module
(`tests/Feature/TenancyTest.php` is the template):
```php
it('cannot access another company employee by ID', function () {
    $user = User::factory()->forCompany($companyA)->create();
    $employee = Employee::factory()->for($companyB)->create();
    $this->actingAs($user)
        ->get("/employees/{$employee->id}")
        ->assertNotFound(); // 404, not 403
});
```

---

## Rule 2 — Authorization

Every controller action and every Inertia page prop must check
permissions. UI hiding is never the only control.

```php
public function store(StoreEmployeeRequest $request)
{
    Gate::authorize('employees.create');
    // ...
}

// Inertia props — filter server-side; never send unauthorized data
return Inertia::render('Employees/Index', [
    'employees' => Gate::allows('employees.view') ? Employee::paginate(25) : [],
    'can' => [
        'create' => Gate::allows('employees.create'),
        'edit'   => Gate::allows('employees.edit'),
        'delete' => Gate::allows('employees.delete'),
    ],
]);
```

Permission format: `{module}.{action}` — all 18 modules × 8 actions
(`App\Enums\Module` × `App\Enums\PermissionAction`) are registered as
Gates in Phase 0. Super Admin passes via `Gate::before`; inactive users
are denied everything. **Never add raw role checks — always Gates.**

---

## Rule 3 — Audit Logging

Every model that holds business data uses the
`App\Models\Concerns\Auditable` trait. Logs are append-only. No exceptions.

```php
class Employee extends Model
{
    use Auditable, BelongsToCompany;

    public string $auditModule = 'employees';      // module tag
    public array $auditExclude = ['noisy_column']; // extra exclusions
}

// Logged automatically: created / updated / deleted with old+new values,
// user, IP, user agent, method, URL. $hidden attributes never reach the
// log. Manual actions: app(AuditLogger::class)->log('exported', $model, …)
```

Never create an update or delete route for audit_logs (the model throws
on both). Never truncate the audit_logs table.

---

## Rule 4 — VAT Enum

Every VAT field uses `App\Enums\VatRate` (built in Phase 0).
Never a raw number. Never null displayed as 0%.

```php
// The real enum API:
VatRate::General->percent();        // 21.0
VatRate::General->labelEs();        // 'IVA General 21%'
VatRate::General->labelEn();        // 'VAT Standard 21%'
VatRate::General->amountFor(100.0); // 21.00 (rounded to cents)
VatRate::options();                 // dropdown options, blank first (default)
```

In migrations:
```php
$table->string('vat_rate', 20)->nullable(); // NULL = "No aplica", the default
$table->decimal('vat_amount', 10, 2)->nullable();
```

In controllers — always pass the options to any form with VAT:
```php
'vatOptions' => VatRate::options(),
```
The Vue side is always `<VVatSelect v-model="…" :options="vatOptions" />`.

---

## Rule 5 — Bilingual System

Every UI string goes through the translation layer. Never hard-code
Spanish or English text in a component.

```php
// lang/es/ui.php + lang/en/ui.php (same key structure in both)
'employees' => ['title' => 'Empleados', 'add' => 'Nuevo Empleado'],
```

```vue
<!-- The Bilingual component takes the translation KEY; both language
     dictionaries ship on every Inertia page (props.lang.es / .en) -->
<Bilingual k="employees.title" />          <!-- stacked -->
<Bilingual k="employees.add" inline />     <!-- tight spaces / buttons -->
```

When adding a new string: add to `lang/es/ui.php` AND `lang/en/ui.php`,
then use `<Bilingual k>`. Never a hardcoded string.

---

## Rule 6 — Create/Edit Always in Modal or Slide Panel

Never navigate to a separate page to create or edit a record.

```vue
<!-- CORRECT -->
<VButton @click="showCreate = true"><Bilingual k="employees.add" inline /></VButton>
<VModal :open="showCreate" title-key="employees.add" @close="showCreate = false">…</VModal>

<!-- WRONG — never do this -->
<Link href="/employees/create">…</Link>
```

Sizes: sm (confirmations) · md (standard forms) · lg (multi-section);
always full screen on mobile (the components handle this).

---

## Rule 7 — Form Requests

Every write endpoint (POST, PUT, PATCH, DELETE) uses a dedicated Form
Request class. No inline validation in controllers.

```php
class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('employees.create');
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'nif'       => ['nullable', 'string', new ValidNif],
            'wage_type' => ['required', Rule::enum(WageType::class)],
            'vat_rate'  => ['nullable', Rule::enum(VatRate::class)],
            'iban'      => ['nullable', 'string', new ValidIban],
        ];
    }
}
```

NIF/NIE/CIF and IBAN get custom validation rules (built with Phase 2).
Never skip validation on any field. Never validate `company_id` from input
— it doesn't come from input (Rule 1).

---

## Rule 8 — Encryption

Encrypted at rest on the Employee model (lands in Phase 2): NIF/DNI/NIE/
passport, IBAN, bank name, salary/wage fields — via encrypted casts.
Searchable encrypted fields use a blind index
(`nif_hash = hash_hmac('sha256', $nif, key)`); search the hash, display
the decrypted value.

Never log encrypted field values in plaintext (they're in `$hidden` →
excluded from audit automatically). Never return them in Inertia props
unless the user has explicit permission to see them.

---

## Rule 9 — Soft Deletes

Employees and Clients: soft deletes only, never hard delete. Other
models: check REQUIREMENTS.md — if not listed, no soft delete.

---

## Rule 10 — File Storage

All uploads go under `storage/app/private/` — never `public/`. Randomized
stored filenames; original name kept as display metadata in the DB only.
Downloads only through authenticated, permission-checked, company-scoped
responses, and every upload/download is audited. Never generate public
URLs for private documents.

---

## Rule 11 — Testing Requirements

Every module ships with: CRUD tests, tenancy isolation tests (mandatory
— including "ignores company_id in request input"), permission tests,
and validation tests.

Run before every commit — all five must be green (CI blocks otherwise):
```bash
php artisan test
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=1G
php C:\Users\Super\.composer\composer.phar audit
npm run build
```

---

## Rule 12 — Inertia Page Structure (actual layout)

```
resources/js/
├── Pages/               ← one folder per module (Employees/Index.vue, …)
│   ├── Auth/Login.vue
│   ├── Dashboard.vue
│   └── Error.vue
├── Layouts/
│   └── AppLayout.vue    ← wraps every authenticated page (sidebar/header/nav)
└── Components/
    ├── AppIcon.vue      ← the line-icon set
    ├── Bilingual.vue    ← registered globally, use everywhere
    └── ui/              ← the component library (VButton, VModal, VTable,
                            VVatSelect, VBadge, …) — new components go HERE
```

Every authenticated page:
```vue
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
</script>
<template>
    <AppLayout><!-- page content --></AppLayout>
</template>
```

---

## Rule 13 — Cross-Company Deployments

When an employee is deployed from Company A to Company B:

- `billing_method`: **Option A only** (home company pays) is automated.
- Attendance is logged against the host project with a deployed badge.
- Home company payroll includes those days with a deployment note.
- Host company gets an auto-generated `internal_deployment` expense.
- **NEVER implement Option B (host processes payroll)** — cesión ilegal
  de trabajadores under Spanish law. See `docs/PAYROLL_DEPLOYMENTS.md`.

Deployed employees always show their home-company badge in the
attendance grid and project worker lists.

---

## Commit Message Format

```
Phase X: short description

- what was built
- what tests were added
- any decisions made
```

---

## Before Every Commit — Checklist

- [ ] `php artisan test` — all green
- [ ] `./vendor/bin/pint` — clean
- [ ] `./vendor/bin/phpstan analyse` — level 6 clean
- [ ] `composer audit` — clean
- [ ] `npm run build` — successful
- [ ] New module has tenancy isolation tests
- [ ] New module has permission tests
- [ ] No company_id read from request input
- [ ] No hardcoded color hex in Vue files
- [ ] All strings through the translation layer
- [ ] VAT fields use the VatRate enum
- [ ] Create/edit in modal — not a separate page
- [ ] CLAUDE.md updated if a phase is complete
