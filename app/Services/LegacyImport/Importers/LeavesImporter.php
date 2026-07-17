<?php

namespace App\Services\LegacyImport\Importers;

use App\Enums\LeaveStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveCategory;
use App\Services\LegacyImport\AbstractImporter;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy leaves + leave_balances → the new tables (DATA_MIGRATION.md §3.7).
 *
 * THE PROBLEM: the legacy schema hangs leave off `user_id`; this one hangs it
 * off `employee_id`, because approved leave has to reach attendance and
 * payroll, and both are keyed on employees. Nothing links users to employees
 * in EITHER schema, so the mapping has to be reconstructed — by name, which is
 * a guess, so every guess is reported:
 *
 *   - exactly one employee matches the user's name  → imported, mapped
 *   - no match, or more than one                    → NOT imported, reported
 *
 * Silently attaching a worker's holiday to the wrong person would corrupt both
 * their balance and their attendance, so an ambiguous row is left for a human
 * rather than guessed at.
 *
 * Imported leave is NOT replayed through LeaveService: it never re-books
 * attendance and never re-derives a balance. History is a fact — the legacy
 * system already booked those days, and re-running the engine over them would
 * either double-book the grid or refuse on a conflict.
 */
class LeavesImporter extends AbstractImporter
{
    public function name(): string
    {
        return 'leaves';
    }

    /**
     * Legacy user id → new employee id, resolved by name.
     *
     * @var array<int|string, int>
     */
    private array $userToEmployee = [];

    protected function import(): void
    {
        $defaultCompanyId = Company::query()->orderBy('id')->value('id');

        if ($defaultCompanyId === null) {
            $this->exception('leaves', null, 'No companies exist — run the seeder first.');

            return;
        }

        $this->mapUsersToEmployees();
        $this->importCategories($defaultCompanyId);
        $this->importLeaves($defaultCompanyId);
        $this->importBalances($defaultCompanyId);
    }

    /**
     * Build the user → employee bridge the schemas never had.
     */
    private function mapUsersToEmployees(): void
    {
        // System context: the importer spans every company (Rule 1 opt-out).
        $employees = Employee::acrossAllCompanies()->get(['id', 'full_name']);

        // Group by normalised name so an ambiguous name is visibly ambiguous
        // rather than resolving to whichever row happened to come back first.
        $byName = [];

        foreach ($employees as $employee) {
            $key = $this->normalise($employee->full_name);
            $byName[$key][] = $employee->id;
        }

        foreach ($this->legacy('users')->get(['id', 'name']) as $user) {
            $key = $this->normalise((string) $user->name);
            $matches = $byName[$key] ?? [];

            if (count($matches) === 1) {
                $this->userToEmployee[$user->id] = $matches[0];
            }
        }
    }

    private function importLeaves(int $companyId): void
    {
        $this->legacy('leaves')->orderBy('id')->chunk(500, function ($rows) use ($companyId): void {
            foreach ($rows as $row) {
                if ($this->alreadyImported($row->id)) {
                    $this->skipped++;

                    continue;
                }

                $employeeId = $this->userToEmployee[$row->user_id] ?? null;

                if ($employeeId === null) {
                    $this->exception('leaves', $row->id, 'Could not resolve the legacy user to exactly one employee — leave NOT imported, resolve by hand', [
                        'legacy_user_id' => $row->user_id,
                        'leave_type' => $row->leave_type ?? null,
                        'start_date' => $row->start_date ?? null,
                    ]);

                    continue;
                }

                $categoryId = $this->categoryIdFor($row->leave_type ?? null, $row->id);

                if ($categoryId === null) {
                    continue; // already reported
                }

                $leave = new Leave([
                    'employee_id' => $employeeId,
                    'leave_category_id' => $categoryId,
                    'start_date' => $row->start_date,
                    'end_date' => $row->end_date,
                    // verbatim: the legacy system already decided what this cost
                    'total_days' => (string) ($row->total_days ?? 0),
                    'reason' => $row->reason ?? null,
                ]);
                $leave->company_id = $companyId;
                $leave->status = $this->normalizeStatus($row->status ?? null);
                $leave->reviewed_at = $row->reviewed_at ?? null;
                $leave->review_notes = $row->review_notes ?? null;
                $reviewerId = ($row->reviewed_by ?? null) !== null
                    ? $this->newIdFor($row->reviewed_by, 'users')
                    : null;
                $leave->reviewed_by = $reviewerId !== null ? (int) $reviewerId : null;
                $leave->original_name = $row->attachment ?? null;
                $leave->save();

                $this->recordMapping($row->id, $leave->id);
                $this->imported++;
            }
        });
    }

    /**
     * Balances migrate as stored — never recomputed from the imported leaves,
     * which would silently "correct" a figure the client has been running on.
     */
    private function importBalances(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('leave_balances')) {
            return;
        }

        foreach ($this->legacy('leave_balances')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'leave_balances')) {
                continue;
            }

            $employeeId = $this->userToEmployee[$row->user_id] ?? null;

            if ($employeeId === null) {
                $this->exception('leave_balances', $row->id, 'Could not resolve the legacy user to exactly one employee — balance NOT imported', [
                    'legacy_user_id' => $row->user_id,
                ]);

                continue;
            }

            $categoryId = $this->categoryIdFor($row->leave_type ?? null, $row->id, 'leave_balances');

            if ($categoryId === null) {
                continue;
            }

            $balance = new LeaveBalance([
                'employee_id' => $employeeId,
                'leave_category_id' => $categoryId,
                'year' => (int) ($row->year ?? now()->format('Y')),
                'allocated' => (string) ($row->total_allocated ?? 0),
                'used' => (string) ($row->total_used ?? 0),
                'pending' => (string) ($row->total_pending ?? 0),
                'carried_over' => (string) ($row->carried_over ?? 0),
            ]);
            $balance->company_id = $companyId;
            $balance->save();

            $this->recordMapping($row->id, $balance->id, 'leave_balances');
        }
    }

    /**
     * The 8 defaults are seeded reference data, so the importer matches on the
     * key rather than creating duplicates. A legacy type outside the set is
     * created (as a company category) and flagged — never dropped.
     */
    private function importCategories(int $companyId): void
    {
        if (! Schema::connection('legacy')->hasTable('leave_categories')) {
            return;
        }

        foreach ($this->legacy('leave_categories')->orderBy('id')->get() as $row) {
            if ($this->alreadyImported($row->id, 'leave_categories')) {
                continue;
            }

            $existing = LeaveCategory::query()
                ->whereNull('company_id')
                ->where('key', (string) ($row->key ?? ''))
                ->first();

            if ($existing !== null) {
                $this->recordMapping($row->id, $existing->id, 'leave_categories');

                continue;
            }

            $created = LeaveCategory::query()->create([
                'company_id' => $companyId,
                'key' => (string) ($row->key ?? 'legacy-'.$row->id),
                'name' => (string) ($row->name ?? 'Sin nombre'),
                'description' => $row->description ?? null,
                'default_allocation' => (string) ($row->default_allocation ?? 0),
                'is_paid' => ((string) ($row->key ?? '')) !== 'unpaid',
                'active' => (bool) ($row->is_active ?? true),
            ]);

            $this->recordMapping($row->id, $created->id, 'leave_categories');
        }
    }

    private function categoryIdFor(?string $legacyType, string|int $rowId, string $table = 'leaves'): ?int
    {
        $key = $this->normaliseKey($legacyType);

        $category = LeaveCategory::query()->where('key', $key)->first()
            ?? LeaveCategory::query()->where('key', 'other')->first();

        if ($category === null) {
            $this->exception($table, $rowId, 'No leave category matched and no "other" fallback exists — run the seeder first', [
                'leave_type' => $legacyType,
            ]);

            return null;
        }

        if ($this->normaliseKey($legacyType) !== $category->key) {
            $this->exception($table, $rowId, 'Unknown leave type — filed under "other"', [
                'leave_type' => $legacyType,
            ]);
        }

        return $category->id;
    }

    private function normaliseKey(?string $legacyType): string
    {
        return strtolower(trim((string) $legacyType));
    }

    /**
     * Case/accent-insensitive name key, so "José García" and "Jose Garcia"
     * are the same person.
     */
    private function normalise(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return preg_replace('/\s+/', ' ', strtolower(trim((string) $ascii))) ?? '';
    }

    private function normalizeStatus(?string $legacy): LeaveStatus
    {
        return LeaveStatus::tryFrom((string) $legacy) ?? LeaveStatus::Pending;
    }
}
