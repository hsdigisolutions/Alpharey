<?php

namespace App\Services\LegacyImport\Importers;

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
                    'payment_method' => $row->payment_method ?? null,
                    'payment_status' => $this->normalizePaymentStatus($row->payment_status ?? null),
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

    private function normalizePaymentStatus(?string $legacy): string
    {
        return in_array($legacy, ['unpaid', 'partial', 'paid', 'pending'], true) ? $legacy : 'unpaid';
    }
}
