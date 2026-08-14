<?php

namespace App\Services\Documents;

use App\Models\Client;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\Vehicle;
use App\Models\Vendor;
use App\Services\Vehicles\VehicleCompliance;
use App\Support\CurrentCompany;
use App\Support\DocumentTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The Document Command Center dataset. Builds — once, cached 5 min behind a
 * change-signature — the full row set every view slices from: uploaded
 * documents (all five entity types, with status + the smart-panel payload for
 * inline View), computed MISSING slots (Company + Employee + Vehicle — the
 * mandatory-compliance surfaces), and vehicle expiry pseudo-documents.
 *
 * Cold load is a handful of queries (documents + companies + employees +
 * vehicles, plus name lookups for project/client/vendor docs when present);
 * on a cache hit it is one signature query. Missing is COMPUTED, never stored.
 */
class DocumentCenterService
{
    private const CACHE_TTL = 300;

    public function __construct(
        private DocumentStatus $status,
        private VehicleCompliance $vehicles,
        private CurrentCompany $currentCompany,
    ) {}

    /**
     * The cached row set + aggregates for the acting scope.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string,int>, generated_for: int|null}
     */
    public function dataset(): array
    {
        $scope = $this->currentCompany->id(); // null = Super Admin, all companies

        $key = 'doc-center:'.($scope ?? 'all').':'.md5($this->signature($scope));

        return Cache::remember($key, self::CACHE_TTL, fn (): array => $this->compute($scope));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array<string,int>, generated_for: int|null}
     */
    private function compute(?int $scope): array
    {
        $today = now()->startOfDay();

        // ── Load (scoped) ────────────────────────────────────────────────────
        /** @var Collection<int, Document> $documents */
        $documents = Document::query()->withoutGlobalScope(CompanyScope::class)
            ->when($scope !== null, fn ($q) => $q->where('company_id', $scope))
            ->with('uploader:id,name')
            ->get();

        $companies = Company::query()
            ->when($scope !== null, fn ($q) => $q->whereKey($scope))
            ->get(['id', 'name'])->keyBy('id');

        $employees = Employee::query()->withoutGlobalScope(CompanyScope::class)
            ->when($scope !== null, fn ($q) => $q->where('company_id', $scope))
            ->where('active', true)
            ->get(['id', 'full_name', 'company_id'])->keyBy('id');

        $vehicles = Vehicle::query()->withoutGlobalScope(CompanyScope::class)
            ->when($scope !== null, fn ($q) => $q->where('company_id', $scope))
            ->where('active', true)
            ->get(['id', 'plate_number', 'company_id', 'insurance_expiry_date', 'ita_expiry_date', 'road_tax_expiry_date'])
            ->keyBy('id');

        $current = $documents->where('is_current', true)->values();

        // Name lookups only for the doc-bearing project/client/vendor ids.
        $projectNames = $this->namesFor($current, Project::class, fn ($ids) => Project::query()
            ->withoutGlobalScope(CompanyScope::class)->whereKey($ids)->pluck('name', 'id'));
        $clientNames = $this->namesFor($current, Client::class, fn ($ids) => Client::query()
            ->whereKey($ids)->pluck('name', 'id'));
        $vendorNames = $this->namesFor($current, Vendor::class, fn ($ids) => Vendor::query()
            ->whereKey($ids)->pluck('name', 'id'));

        $entityName = function (Document $d) use ($companies, $employees, $projectNames, $clientNames, $vendorNames): ?string {
            return match ($d->documentable_type) {
                Company::class => $companies[$d->documentable_id]->name ?? null,
                Employee::class => $employees[$d->documentable_id]->full_name ?? null,
                Project::class => $projectNames[$d->documentable_id] ?? null,
                Client::class => $clientNames[$d->documentable_id] ?? null,
                Vendor::class => $vendorNames[$d->documentable_id] ?? null,
                default => null,
            };
        };

        $rows = [];
        $present = []; // 'type|entityType|entityId' => true (monthly-aware)

        // ── Uploaded documents ───────────────────────────────────────────────
        foreach ($current as $doc) {
            $entityType = $this->entityType($doc->documentable_type);
            [$state, $daysLeft] = $this->status->of($doc);

            $uploadedThisMonth = $doc->created_at !== null && $doc->created_at->isSameMonth($today);
            $isMonthly = in_array($doc->type_key, DocumentTypes::monthlyCompanyKeys(), true);

            // Monthly-this-month rule (Q4): a monthly type NOT refreshed this
            // month is a Faltante — skip the stale upload so the missing sweep
            // represents it as exactly one missing row (no double-count).
            if ($isMonthly && ! $uploadedThisMonth) {
                continue;
            }

            $present[$doc->type_key.'|'.$entityType.'|'.$doc->documentable_id] = true;

            $companyId = $doc->company_id;

            $rows[] = [
                'key' => 'doc-'.$doc->id,
                'document_id' => $doc->id,
                'entity_type' => $entityType,
                'entity_id' => $doc->documentable_id,
                'entity_name' => $entityName($doc),
                'company_id' => $companyId,
                'company_name' => $companyId !== null ? ($companies[$companyId]->name ?? null) : null,
                'type_key' => $doc->type_key,
                'category' => $doc->category,
                'status' => $state,
                'days_left' => $daysLeft,
                'expiry_date' => $doc->expiry_date?->toDateString(),
                'uploaded_at' => $doc->created_at?->toDateString(),
                'has_file' => $doc->getAttribute('file_path') !== null,
                'is_exempt' => (bool) $doc->is_exempt,
                'is_vehicle' => false,
                'is_missing' => false,
            ];
        }

        // ── Missing (Company + Employee) ─────────────────────────────────────
        foreach ($companies as $company) {
            foreach (DocumentTypes::companyKeys() as $typeKey) {
                if (! isset($present[$typeKey.'|company|'.$company->id])) {
                    $rows[] = $this->missingRow('company', $company->id, $company->name, $company->id, $company->name, $typeKey, 'company');
                }
            }
        }

        foreach ($employees as $employee) {
            foreach (DocumentTypes::employeeKeys() as $typeKey) {
                if (! isset($present[$typeKey.'|employee|'.$employee->id])) {
                    $companyName = $companies[$employee->company_id]->name ?? null;
                    $rows[] = $this->missingRow('employee', $employee->id, $employee->full_name, $employee->company_id, $companyName, $typeKey, DocumentTypes::categoryFor('employee', $typeKey));
                }
            }
        }

        // ── Vehicles (read-only pseudo-documents) ────────────────────────────
        foreach ($vehicles as $vehicle) {
            $companyName = $companies[$vehicle->company_id]->name ?? null;

            foreach (VehicleCompliance::EXPIRY_FIELDS as $field => $column) {
                [$state, $daysLeft] = $this->vehicles->of($vehicle, $field);
                /** @var Carbon|null $date */
                $date = $vehicle->getAttribute($column);
                $missing = $date === null;

                $rows[] = [
                    'key' => 'veh-'.$vehicle->id.'-'.$field,
                    'document_id' => null,
                    'entity_type' => 'vehicle',
                    'entity_id' => $vehicle->id,
                    'entity_name' => $vehicle->plate_number,
                    'company_id' => $vehicle->company_id,
                    'company_name' => $companyName,
                    'type_key' => $field,
                    'category' => 'vehicle',
                    'status' => $missing ? 'neutral' : $state,
                    'days_left' => $daysLeft,
                    'expiry_date' => $date?->toDateString(),
                    'uploaded_at' => null,
                    'has_file' => false,
                    'is_exempt' => false,
                    'is_vehicle' => true,
                    'is_missing' => $missing,
                ];
            }
        }

        return [
            'rows' => $rows,
            'summary' => $this->summarise($rows),
            'generated_for' => $scope,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingRow(string $entityType, int $entityId, ?string $entityName, ?int $companyId, ?string $companyName, string $typeKey, string $category): array
    {
        return [
            'key' => 'missing-'.$entityType.'-'.$entityId.'-'.$typeKey,
            'document_id' => null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'company_id' => $companyId,
            'company_name' => $companyName,
            'type_key' => $typeKey,
            'category' => $category,
            'status' => 'missing',
            'days_left' => null,
            'expiry_date' => null,
            'uploaded_at' => null,
            'has_file' => false,
            'is_exempt' => false,
            'is_vehicle' => false,
            'is_missing' => true,
        ];
    }

    /**
     * Distinct entity names for a morph type present in the current documents.
     *
     * @param  Collection<int, Document>  $current
     * @param  callable(list<int>): Collection<int, string>  $loader
     * @return array<int, string>
     */
    private function namesFor(Collection $current, string $morphClass, callable $loader): array
    {
        $ids = $current->where('documentable_type', $morphClass)
            ->pluck('documentable_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return $loader($ids)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarise(array $rows): array
    {
        $summary = ['total' => 0, 'ok' => 0, 'warn' => 0, 'danger' => 0, 'neutral' => 0, 'exempt' => 0, 'missing' => 0];

        foreach ($rows as $row) {
            $summary['total']++;
            $status = $row['status'];
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    // ── View derivations (over the cached rows) ─────────────────────────────

    /**
     * Urgent buckets, most-critical-first. Exempt rows never appear.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{expired: list<array<string,mixed>>, week: list<array<string,mixed>>, month: list<array<string,mixed>>, missing: list<array<string,mixed>>}
     */
    public function urgent(array $rows): array
    {
        $expired = $week = $month = $missing = [];

        foreach ($rows as $row) {
            if ($row['is_exempt']) {
                continue;
            }

            if ($row['status'] === 'missing') {
                $missing[] = $row;

                continue;
            }

            $days = $row['days_left'];
            if ($days === null) {
                continue; // ok/neutral with no date — not urgent
            }

            if ($days < 0) {
                $expired[] = $row;
            } elseif ($days <= 7) {
                $week[] = $row;
            } elseif ($days <= 30) {
                $month[] = $row;
            }
        }

        $byDays = fn (array $a, array $b): int => ($a['days_left'] ?? 0) <=> ($b['days_left'] ?? 0);
        usort($expired, $byDays);
        usort($week, $byDays);
        usort($month, $byDays);
        usort($missing, fn (array $a, array $b): int => strcmp((string) $a['entity_name'], (string) $b['entity_name']));

        return ['expired' => $expired, 'week' => $week, 'month' => $month, 'missing' => $missing];
    }

    /**
     * Rows grouped by entity type → entity, each with a compliance score.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string,mixed>>>
     */
    public function byEntity(array $rows): array
    {
        $entities = [];

        foreach ($rows as $row) {
            $groupKey = $row['entity_type'].'|'.$row['entity_id'];
            $entities[$groupKey] ??= [
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'entity_name' => $row['entity_name'],
                'company_name' => $row['company_name'],
                'scores' => [],
                'missing' => 0,
                'expiring' => 0,
            ];

            $entities[$groupKey]['scores'][] = $this->scoreOf($row['status']);
            if ($row['status'] === 'missing') {
                $entities[$groupKey]['missing']++;
            }
            if (in_array($row['status'], ['warn', 'danger'], true) && ! $row['is_exempt']) {
                $entities[$groupKey]['expiring']++;
            }
        }

        $out = [];
        foreach ($entities as $entity) {
            $scores = array_filter($entity['scores'], fn ($s) => $s !== null);
            $entity['percent'] = $scores === [] ? 100 : (int) round(array_sum($scores) / count($scores) * 100);
            unset($entity['scores']);
            $out[$entity['entity_type']][] = $entity;
        }

        foreach ($out as &$list) {
            usort($list, fn (array $a, array $b): int => $a['percent'] <=> $b['percent']); // worst first
        }

        return $out;
    }

    /**
     * Six months of weekly expiry counts, each week carrying its row keys.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{month: string, weeks: list<array{label: string, from: string, to: string, count: int, keys: list<string>}>}>
     */
    public function timeline(array $rows): array
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addMonths(6);
        $buckets = [];

        foreach ($rows as $row) {
            if ($row['expiry_date'] === null || $row['is_exempt']) {
                continue;
            }
            $date = Carbon::parse($row['expiry_date'])->startOfDay();
            if ($date->lt($today) || $date->gt($horizon)) {
                continue;
            }
            $weekStart = $date->copy()->startOfWeek();
            $ym = $date->format('Y-m');
            $buckets[$ym][$weekStart->toDateString()][] = $row['key'];
        }

        $out = [];
        foreach ($buckets as $ym => $weeks) {
            ksort($weeks);
            $weekRows = [];
            foreach ($weeks as $from => $keys) {
                $start = Carbon::parse($from);
                $weekRows[] = [
                    'label' => $start->format('d').'–'.$start->copy()->endOfWeek()->format('d'),
                    'from' => $from,
                    'to' => $start->copy()->endOfWeek()->toDateString(),
                    'count' => count($keys),
                    'keys' => $keys,
                ];
            }
            $out[] = ['month' => $ym, 'weeks' => $weekRows];
        }

        usort($out, fn (array $a, array $b): int => strcmp($a['month'], $b['month']));

        return $out;
    }

    /**
     * Dashboard aggregates: per-company scores + per-entity-type breakdown.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{companyScores: list<array<string,mixed>>, categoryBreakdown: list<array<string,mixed>>}
     */
    public function dashboard(array $rows): array
    {
        $companies = [];
        $categories = [];

        foreach ($rows as $row) {
            $cid = $row['company_id'];
            if ($cid !== null) {
                $companies[$cid] ??= ['company_id' => $cid, 'name' => $row['company_name'], 'scores' => [], 'total' => 0];
                $companies[$cid]['scores'][] = $this->scoreOf($row['status']);
                $companies[$cid]['total']++;
            }

            $cat = $row['entity_type'];
            $categories[$cat] ??= ['category' => $cat, 'total' => 0, 'ok' => 0, 'danger' => 0, 'missing' => 0];
            $categories[$cat]['total']++;
            if ($row['status'] === 'ok') {
                $categories[$cat]['ok']++;
            }
            if ($row['status'] === 'danger') {
                $categories[$cat]['danger']++;
            }
            if ($row['status'] === 'missing') {
                $categories[$cat]['missing']++;
            }
        }

        $companyScores = [];
        foreach ($companies as $company) {
            $scores = array_filter($company['scores'], fn ($s) => $s !== null);
            $companyScores[] = [
                'company_id' => $company['company_id'],
                'name' => $company['name'],
                'total' => $company['total'],
                'percent' => $scores === [] ? 100 : (int) round(array_sum($scores) / count($scores) * 100),
            ];
        }
        usort($companyScores, fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

        return ['companyScores' => $companyScores, 'categoryBreakdown' => array_values($categories)];
    }

    /**
     * Server-side filter for the All Documents view.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function filter(array $rows, array $filters): array
    {
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        return array_values(array_filter($rows, function (array $row) use ($filters, $search): bool {
            if ($search !== '') {
                $hay = mb_strtolower(($row['entity_name'] ?? '').' '.($row['type_key'] ?? '').' '.($row['company_name'] ?? ''));
                if (! str_contains($hay, $search)) {
                    return false;
                }
            }
            if (! empty($filters['category']) && $row['entity_type'] !== $filters['category']) {
                return false;
            }
            if (! empty($filters['type_key']) && $row['type_key'] !== $filters['type_key']) {
                return false;
            }
            if (! empty($filters['status']) && $row['status'] !== $filters['status']) {
                return false;
            }
            if (! empty($filters['company_id']) && (int) $row['company_id'] !== (int) $filters['company_id']) {
                return false;
            }
            if (! empty($filters['from']) && ($row['expiry_date'] === null || $row['expiry_date'] < $filters['from'])) {
                return false;
            }
            if (! empty($filters['to']) && ($row['expiry_date'] === null || $row['expiry_date'] > $filters['to'])) {
                return false;
            }

            return true;
        }));
    }

    private function scoreOf(string $status): ?float
    {
        return match ($status) {
            'exempt' => null,
            'ok' => 1.0,
            'warn', 'neutral' => 0.5,
            default => 0.0, // danger, missing
        };
    }

    private function entityType(string $morphClass): string
    {
        return match ($morphClass) {
            Company::class => 'company',
            Employee::class => 'employee',
            Project::class => 'project',
            Client::class => 'client',
            Vendor::class => 'vendor',
            default => 'employee',
        };
    }

    /**
     * Change-signature: any document/vehicle/employee/project/company edit moves
     * a MAX(updated_at) and busts the cache.
     */
    private function signature(?int $scope): string
    {
        $max = fn (string $model, string $table) => $model::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->when($scope !== null && $table !== 'companies', fn ($q) => $q->where('company_id', $scope))
            ->when($scope !== null && $table === 'companies', fn ($q) => $q->whereKey($scope))
            ->max('updated_at');

        return implode('|', [
            (string) $max(Document::class, 'documents'),
            (string) $max(Vehicle::class, 'vehicles'),
            (string) $max(Employee::class, 'employees'),
            (string) $max(Project::class, 'projects'),
            (string) $max(Company::class, 'companies'),
        ]);
    }
}
