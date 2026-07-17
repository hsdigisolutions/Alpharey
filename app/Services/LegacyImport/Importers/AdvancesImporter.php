<?php

namespace App\Services\LegacyImport\Importers;

use App\Models\Advance;
use App\Models\AdvanceCategory;
use App\Models\Company;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy salary advances → advances + advance_categories.
 *
 * An advance that was already taken off a payroll arrives as `deducted`, the
 * terminal state — so the new workflow cannot re-deduct money that has already
 * been settled.
 */
class AdvancesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'advances';
    }

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('advances', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->importCategories($defaultCompanyId);

        $this->legacy('advances')->orderBy('id')->chunk(500, function ($rows) use ($defaultCompanyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $employeeId = $this->newIdFor($row->employee_id, 'employees');

                if ($employeeId === null) {
                    $this->exception('advances', $row->id, 'Employee not imported — run the employees importer first', [
                        'legacy_employee_id' => $row->employee_id,
                    ]);

                    continue;
                }

                $categoryId = ($row->advance_category_id ?? $row->category_id ?? null) !== null
                    ? $this->newIdFor($row->advance_category_id ?? $row->category_id, 'advance_categories')
                    : null;

                $advance = new Advance([
                    'employee_id' => $employeeId,
                    'advance_category_id' => $categoryId,
                    'amount' => (string) ($row->amount ?? 0),
                    'reason' => $row->reason ?? null,
                    'status' => $this->normalizeStatus($row->status ?? null),
                    'request_date' => $row->request_date ?? $row->created_at ?? now()->toDateString(),
                    'payment_date' => $row->payment_date ?? null,
                    'payroll_month' => $this->normalizeMonth($row->payroll_month ?? null),
                ]);
                $advance->company_id = $defaultCompanyId;
                $advance->save();

                $this->recordMapping($row->id, $advance->id);
                $this->imported++;
            }
        });
    }

    private function importCategories(int $companyId): void
    {
        // Older dumps may predate the categories table entirely.
        if (! Schema::connection('legacy')->hasTable('advance_categories')) {
            return;
        }

        foreach ($this->legacy('advance_categories')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'advance_categories')) {
                continue;
            }

            $category = AdvanceCategory::query()->create([
                'company_id' => $companyId,
                'name' => (string) ($row->name ?? 'Sin nombre'),
                'active' => (bool) ($row->active ?? true),
            ]);

            $this->recordMapping($row->id, $category->id, 'advance_categories');
        }
    }

    private function normalizeStatus(?string $legacy): string
    {
        return in_array($legacy, ['pending', 'approved', 'rejected', 'deducted'], true) ? $legacy : 'pending';
    }

    private function normalizeMonth(?string $legacy): ?string
    {
        if ($legacy === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})/', $legacy, $m)) {
            return "{$m[1]}-{$m[2]}";
        }

        return null;
    }
}
