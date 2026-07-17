<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\PaymentMethod;
use App\Enums\VatRate;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\LegacyImport\AbstractImporter;

/**
 * Legacy expenses → expenses (DATA_MIGRATION.md §3.4).
 *
 * Documented precedence for the old duplicated columns:
 *  - `iva_percent` wins over `vat_percent` (it was the later, actively-used one)
 *  - `category_id` wins over the string `category`; a string with no matching
 *    category CREATES one and is flagged, rather than dropping the label
 *
 * Figures migrate verbatim (§3.5) — the importer never re-derives the total.
 */
class ExpensesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'expenses';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('expenses', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->legacy('expenses')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $vatPercent = $this->resolveVatPercent($row);
                $vatRate = VatRate::fromPercent($vatPercent);

                if ($vatPercent !== null && (float) $vatPercent > 0 && $vatRate === null) {
                    $this->exception('expenses', $row->id, 'VAT percentage is not an official rate — vat_rate left blank, vat_amount preserved', [
                        'vat_percent' => $vatPercent,
                    ]);
                }

                $expense = new Expense([
                    'number' => $row->number ?? null,
                    'type' => $this->normalizeType($row->type ?? null),
                    'expense_category_id' => $this->resolveCategoryId($row, $defaultCompanyId),
                    'vendor_id' => $row->vendor_id !== null ? $this->newIdFor($row->vendor_id, 'vendors') : null,
                    'project_id' => $row->project_id !== null ? $this->newIdFor($row->project_id, 'projects') : null,
                    'employee_id' => $row->employee_id !== null ? $this->newIdFor($row->employee_id, 'employees') : null,
                    'date' => $row->date ?? null,
                    'due_date' => $row->due_date ?? null,
                    'subtotal' => (string) ($row->subtotal ?? $row->amount ?? 0),
                    'vat_rate' => $vatRate?->value,
                    'vat_amount' => (string) ($row->vat_amount ?? 0),
                    'total' => (string) ($row->total ?? $row->amount ?? 0),
                    'payment_method' => $this->normalizePaymentMethod($row),
                    'payment_status' => $this->normalizePaymentStatus($row),
                    'payment_date' => $row->payment_date ?? null,
                    'is_reimbursable' => (bool) ($row->is_reimbursable ?? false),
                    'notes' => $row->notes ?? null,
                ]);
                $expense->company_id = $defaultCompanyId;
                // approved is not mass-assignable (Measurement/Document rule)
                $expense->approved = (bool) ($row->approved ?? false);
                $expense->save();

                $this->recordMapping($row->id, $expense->id);
                $this->imported++;
            }
        });
    }

    /**
     * §3.4: iva_percent wins over vat_percent when they differ.
     */
    private function resolveVatPercent(object $row): float|string|null
    {
        $iva = $row->iva_percent ?? null;
        $vat = $row->vat_percent ?? null;

        if ($iva !== null && $vat !== null && abs((float) $iva - (float) $vat) > 0.01) {
            $this->exception('expenses', $row->id, 'Conflicting iva_percent/vat_percent — iva_percent used', [
                'iva_percent' => $iva,
                'vat_percent' => $vat,
            ]);
        }

        return $iva ?? $vat;
    }

    /**
     * §3.4: category_id wins; an unmatched string category is CREATED (and
     * flagged) so the label is never silently lost.
     */
    private function resolveCategoryId(object $row, int $companyId): ?int
    {
        if (($row->category_id ?? null) !== null) {
            $mapped = $this->newIdFor($row->category_id, 'expense_categories');

            if ($mapped !== null) {
                return (int) $mapped;
            }
        }

        $name = trim((string) ($row->category ?? ''));

        if ($name === '') {
            return null;
        }

        $existing = ExpenseCategory::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $created = ExpenseCategory::query()->create([
            'company_id' => $companyId,
            'name' => $name,
            'active' => true,
        ]);

        $this->exception('expenses', $row->id, 'Category existed only as a string — created it', [
            'category' => $name,
            'created_category_id' => $created->id,
        ]);

        return $created->id;
    }

    private function normalizeType(?string $legacy): string
    {
        return in_array($legacy, ['albaran', 'factura', 'ticket', 'other'], true) ? $legacy : 'other';
    }

    /**
     * The legacy `payment_method` answers "WHO paid", not "by what method":
     * its values are employee / company_card / bank / not_paid. Only `bank` is
     * a payment method in this schema's sense.
     *
     * The other three are facts this schema already records elsewhere, so they
     * map to NULL rather than being mangled into a method they never were:
     *   - employee     → the worker fronted the cost: `is_reimbursable`
     *   - company_card → `company_card_id`
     *   - not_paid     → `payment_status`
     *
     * Found against the real dump: the old code passed this column straight
     * through into a PaymentMethod cast and the import died on the first
     * `employee` row (510 of 687). DATA_MIGRATION.md §3.4b.
     */
    private function normalizePaymentMethod(object $row): ?string
    {
        $legacy = $row->payment_method ?? null;

        return match ($legacy) {
            'bank' => PaymentMethod::BankTransfer->value,
            'employee', 'company_card', 'not_paid', '', null => null,
            default => $this->flagUnmapped($row, 'payment_method', (string) $legacy),
        };
    }

    /**
     * `reimbursed` is the legacy terminal state for a worker-fronted cost that
     * has been paid back — settled, i.e. paid. It has no case of its own here.
     *
     * Anything genuinely unrecognised is REPORTED rather than silently
     * defaulted: the old whitelist quietly turned every unknown value into
     * 'unpaid', which is how `reimbursed` was about to import as an
     * outstanding debt to 2 workers.
     */
    private function normalizePaymentStatus(object $row): string
    {
        $legacy = $row->payment_status ?? null;

        if (in_array($legacy, ['unpaid', 'partial', 'paid', 'pending'], true)) {
            return $legacy;
        }

        if ($legacy === 'reimbursed') {
            return 'paid';
        }

        if ($legacy !== null && $legacy !== '') {
            $this->flagUnmapped($row, 'payment_status', (string) $legacy);
        }

        return 'unpaid';
    }

    /**
     * A value the new schema has no home for: keep the row, report the field.
     * Returns null so a caller can both flag and yield the blank in one arm.
     */
    private function flagUnmapped(object $row, string $field, string $value): null
    {
        $this->exception('expenses', $row->id, "Unmapped {$field} '{$value}' — left blank for a human", [
            $field => $value,
        ]);

        return null;
    }
}
