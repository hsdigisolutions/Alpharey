<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 03 — Dashboard. Always for ONE company.
 *
 * A Super Admin browsing "all companies" has no single company to summarise,
 * so (like the finance screens — scaffolding decision 27) they are sent to
 * Welcome to pick one rather than shown a meaningless all-companies blur.
 */
class DashboardController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(): Response
    {
        $companyId = $this->contextCompanyId();

        return Inertia::render('Dashboard', [
            'data' => $this->dashboard->for($companyId),
            'can' => [
                // Quick-action buttons: only offer what the user may actually do.
                'create_employee' => Gate::allows('employees.create'),
                'create_project' => Gate::allows('projects.create'),
                'upload_document' => Gate::allows('documents.upload'),
                'log_attendance' => Gate::allows('attendance.create'),
            ],
        ]);
    }
}
