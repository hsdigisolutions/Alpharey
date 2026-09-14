<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Scopes\CompanyScope;
use Illuminate\Console\Command;

/**
 * Backfill / repair the stored `distance_from_project` on every attendance row
 * that carries a GPS fix, recomputing it LIVE against the row's CURRENT project's
 * CURRENT coordinates (Attendance::syncStoredDistance()). This corrects rows whose
 * stored distance was frozen against a project that was later moved (an admin
 * changing project_id) or whose coordinates were later corrected — the wrong /
 * stale-project distance class the Shahzaib Ali case surfaced.
 *
 * Idempotent: a row already matching its live value is left untouched. Display is
 * already live; this keeps the persisted column honest for exports and any reader.
 */
class ResyncAttendanceDistances extends Command
{
    protected $signature = 'attendance:resync-distances {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute stored attendance GPS distance against each row\'s current project + coordinates';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $scanned = 0;
        $changed = 0;

        Attendance::query()->withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('check_in_lat')->whereNotNull('check_in_lng')->whereNotNull('project_id')
            ->with(['project' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)
                ->select('id', 'name', 'latitude', 'longitude')])
            ->chunkById(500, function ($rows) use (&$scanned, &$changed, $dry): void {
                foreach ($rows as $attendance) {
                    $scanned++;
                    $live = $attendance->liveDistanceMeters();
                    $stored = $attendance->distance_from_project;
                    $matches = (string) ($stored ?? '') === (string) ($live === null ? '' : $live);
                    if ($matches) {
                        continue;
                    }
                    $this->line(sprintf('  att#%d proj#%s: stored=%s -> live=%s',
                        $attendance->id, $attendance->project_id, $stored ?? 'null', $live ?? 'null'));
                    if (! $dry) {
                        $attendance->syncStoredDistance();
                    }
                    $changed++;
                }
            });

        $this->info(sprintf('%s: scanned %d rows, %s %d.',
            $dry ? 'DRY RUN' : 'Done', $scanned, $dry ? 'would change' : 'corrected', $changed));

        return self::SUCCESS;
    }
}
