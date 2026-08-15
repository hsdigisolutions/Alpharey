<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Services\Dashboard\TodayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 15 — Today's Report. Per selected company, auto-refreshed by the
 * client every 5 minutes.
 */
class TodayController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly TodayService $today) {}

    public function index(Request $request): Response
    {
        $companyId = $this->contextCompanyId();

        $statuses = array_map(fn (AttendanceStatus $s): string => $s->value, AttendanceStatus::cases());
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'project' => is_numeric($request->query('project')) ? (int) $request->query('project') : null,
            'status' => in_array($request->query('status'), $statuses, true) ? (string) $request->query('status') : null,
        ];

        $data = $this->today->for($companyId, $filters);

        // Advance amounts are encrypted pay data — strip them for anyone
        // without the right to see pay (same rule as the payroll screen).
        if (! Gate::allows('payroll.view')) {
            $data['pending']['advances_pending'] = array_map(
                function (array $row): array {
                    unset($row['amount']);

                    return $row;
                },
                $data['pending']['advances_pending'],
            );
        }

        return Inertia::render('Today/Index', [
            'data' => $data,
            'can' => [
                'view_pay' => Gate::allows('payroll.view'),
            ],
        ]);
    }
}
