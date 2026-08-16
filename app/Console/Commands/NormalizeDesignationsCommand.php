<?php

namespace App\Console\Commands;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off data cleanup: link legacy free-text employee designations to the new
 * Designation catalogue (sets designation_id) and normalise the display text to
 * the canonical catalogue name.
 *
 * The old VertoCRM stored designations as inconsistent free text ("Peon" vs
 * "Peón", "Oficial" vs "Official", English trades, placeholders). This maps the
 * confirmed values (client-approved 2026-08-16) to catalogue ids; unknown
 * placeholders (NM, tbc, null) are deliberately left untouched.
 *
 * Dry-run by default (prints the plan, writes nothing). Pass --commit to write.
 * A full snapshot of every affected row is saved to
 * storage/app/designation-snapshot.json BEFORE any write, so the change can be
 * rolled back exactly. All writes run in a single transaction — any error rolls
 * the whole batch back.
 */
class NormalizeDesignationsCommand extends Command
{
    protected $signature = 'employees:normalize-designations {--commit : Actually write the changes (otherwise dry-run)}';

    protected $description = 'Link legacy free-text employee designations to the Designation catalogue (client-approved mapping)';

    /**
     * Old free-text value (exact, case-sensitive) => target catalogue id.
     * Values NOT listed here (NM, tbc, null) are left untouched by design.
     *
     * @var array<string, int>
     */
    private const MAP = [
        'Peon' => 2,        // Peón / Mazdoor
        'Peón' => 2,        // Peón / Mazdoor
        'Pintor' => 6,      // Pintor
        'Plumber' => 4,     // Fontanero
        'Oficial' => 11,    // Oficial 1ª
        'Official' => 11,   // Oficial 1ª
        'Supervisor' => 1,  // Maestro / Mistri
        'Pladurista' => 8,  // Yesero
        'Conductor' => 14,  // Otro
        'Cook' => 14,       // Otro
        'CEO' => 14,        // Otro
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        // Resolve the canonical catalogue name for each target id up front, and
        // verify every mapped id actually exists in the catalogue.
        $names = Designation::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('id', array_values(self::MAP))
            ->pluck('name', 'id');

        foreach (array_unique(array_values(self::MAP)) as $id) {
            if (! isset($names[$id])) {
                $this->error("Catalogue designation id {$id} does not exist — aborting.");

                return self::FAILURE;
            }
        }

        // The matching employees (console has no company context → drop the
        // company scope; SoftDeletes stays in force so trashed rows are skipped).
        $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('designation', array_keys(self::MAP))
            ->get();

        // Snapshot EVERY company employee's current (id → designation,
        // designation_id) before touching anything — a total, exact rollback.
        $snapshot = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->get(['id', 'employee_code', 'designation', 'designation_id'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'employee_code' => $e->employee_code,
                'designation' => $e->designation,
                'designation_id' => $e->designation_id,
            ])->all();
        Storage::disk('local')->put('designation-snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Snapshot saved: storage/app/designation-snapshot.json ('.count($snapshot).' employees).');

        // Per-old-value plan.
        $this->newLine();
        $this->line('Planned changes:');
        $counts = [];
        foreach ($employees as $e) {
            $counts[$e->designation] = ($counts[$e->designation] ?? 0) + 1;
        }
        $total = 0;
        foreach (self::MAP as $old => $id) {
            $n = $counts[$old] ?? 0;
            $total += $n;
            $this->line(sprintf('  %-12s → %-18s (id %2d)  ×%d', $old, $names[$id], $id, $n));
        }
        $this->newLine();
        $this->info("Total employees to update: {$total}");

        if (! $commit) {
            $this->warn('DRY RUN — nothing written. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        // Write everything in one transaction; any error rolls the batch back.
        try {
            $updated = DB::transaction(function () use ($employees, $names): int {
                $n = 0;
                foreach ($employees as $e) {
                    $id = self::MAP[$e->designation];
                    $e->designation_id = $id;
                    $e->designation = $names[$id]; // normalise the display text too
                    $e->save();
                    $n++;
                }

                return $n;
            });
        } catch (\Throwable $ex) {
            $this->error('Error during update — transaction rolled back. No changes committed.');
            $this->error($ex->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("COMMITTED — {$updated} employees linked to the designation catalogue.");
        $this->line('Rollback data: storage/app/designation-snapshot.json');

        return self::SUCCESS;
    }
}
