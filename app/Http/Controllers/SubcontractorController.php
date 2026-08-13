<?php

namespace App\Http\Controllers;

use App\Enums\SubcontractorPaymentStatus;
use App\Enums\SubcontractorStatus;
use App\Http\Requests\Subcontractors\StoreSubcontractorPaymentRequest;
use App\Http\Requests\Subcontractors\StoreSubcontractorRequest;
use App\Http\Requests\Subcontractors\StoreSubcontractorWorkerRequest;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\SubcontractorPayment;
use App\Models\SubcontractorWorker;
use App\Services\Subcontractors\SubcontractorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subcontratistas (thaekedar). Company-owned; every action is permission-gated
 * on the `subcontractors` module and every payload filtered server-side.
 */
class SubcontractorController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('subcontractors.view');

        $rows = Subcontractor::query()
            ->with(['project:id,name', 'workers:id,subcontractor_id,total_agreed'])
            ->withCount('workers')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Subcontractor $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'project' => $s->project?->name,
                'status' => $s->status->value,
                'workers_count' => $s->workers_count,
                'agreed_total' => round((float) $s->workers->sum(fn (SubcontractorWorker $w) => (float) $w->total_agreed), 2),
                'start_date' => $s->start_date?->toDateString(),
                'end_date' => $s->end_date?->toDateString(),
            ]);

        return Inertia::render('Subcontractors/Index', [
            'subcontractors' => $rows,
            'projects' => $this->projects(),
            'statuses' => array_map(fn (SubcontractorStatus $s) => $s->value, SubcontractorStatus::cases()),
            'can' => [
                'create' => Gate::allows('subcontractors.create'),
                'edit' => Gate::allows('subcontractors.edit'),
                'delete' => Gate::allows('subcontractors.delete'),
            ],
        ]);
    }

    public function store(StoreSubcontractorRequest $request): RedirectResponse
    {
        $subcontractor = Subcontractor::query()->create($request->validated());

        return redirect()->route('subcontractors.show', $subcontractor)
            ->with('success', __('ui.subcontractors.saved'));
    }

    public function show(Subcontractor $subcontractor): Response
    {
        Gate::authorize('subcontractors.view');

        $subcontractor->load(['project:id,name', 'workers.employee:id,full_name', 'payments' => fn ($q) => $q->orderBy('payment_number')]);

        $workersTotal = round((float) $subcontractor->workers->sum(fn (SubcontractorWorker $w) => (float) $w->total_agreed), 2);
        $paymentsTotal = round((float) $subcontractor->payments->sum(fn (SubcontractorPayment $p) => (float) $p->amount), 2);
        $paidTotal = round((float) $subcontractor->payments
            ->where('status', SubcontractorPaymentStatus::Paid)
            ->sum(fn (SubcontractorPayment $p) => (float) $p->amount), 2);

        return Inertia::render('Subcontractors/Detail', [
            'subcontractor' => [
                'id' => $subcontractor->id,
                'name' => $subcontractor->name,
                'nif' => $subcontractor->nif,
                'phone' => $subcontractor->phone,
                'email' => $subcontractor->email,
                'project_id' => $subcontractor->project_id,
                'project' => $subcontractor->project?->name,
                // The deal. our_profit is DERIVED — client − budget (Scenario B
                // subtracts our expenses in the settlement payload, Step 2).
                'client_amount' => $subcontractor->client_amount !== null ? (float) $subcontractor->client_amount : null,
                'agreed_budget' => $subcontractor->agreed_budget !== null ? (float) $subcontractor->agreed_budget : null,
                'expense_responsibility' => $subcontractor->expense_responsibility->value,
                'our_profit' => ($subcontractor->client_amount !== null && $subcontractor->agreed_budget !== null)
                    ? round((float) $subcontractor->client_amount - (float) $subcontractor->agreed_budget, 2)
                    : null,
                'start_date' => $subcontractor->start_date?->toDateString(),
                'end_date' => $subcontractor->end_date?->toDateString(),
                'status' => $subcontractor->status->value,
                'notes' => $subcontractor->notes,
            ],
            'workers' => $subcontractor->workers->map(fn (SubcontractorWorker $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'is_our_employee' => $w->is_our_employee,
                'employee_id' => $w->employee_id,
                'employee' => $w->employee?->full_name,
                'days_worked' => (float) $w->days_worked,
                'agreed_rate' => (float) $w->agreed_rate,
                'total_agreed' => (float) $w->total_agreed,
                'payment_status' => $w->payment_status->value,
                'notes' => $w->notes,
            ])->values(),
            'payments' => $subcontractor->payments->map(fn (SubcontractorPayment $p): array => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => $p->payment_date?->toDateString(),
                'amount' => (float) $p->amount,
                'status' => $p->status->value,
                'expense_id' => $p->expense_id,
                'notes' => $p->notes,
            ])->values(),
            'summary' => [
                'workers_total' => $workersTotal,
                'payments_total' => $paymentsTotal,
                'paid_total' => $paidTotal,
                'pending' => round($paymentsTotal - $paidTotal, 2),
            ],
            'projects' => $this->projects(),
            'employees' => $this->employees(),
            'statuses' => array_map(fn (SubcontractorStatus $s) => $s->value, SubcontractorStatus::cases()),
            'can' => [
                'edit' => Gate::allows('subcontractors.edit'),
                'delete' => Gate::allows('subcontractors.delete'),
            ],
        ]);
    }

    public function update(StoreSubcontractorRequest $request, Subcontractor $subcontractor): RedirectResponse
    {
        $subcontractor->update($request->validated());

        return back()->with('success', __('ui.subcontractors.saved'));
    }

    public function destroy(Subcontractor $subcontractor): RedirectResponse
    {
        Gate::authorize('subcontractors.delete');
        $subcontractor->delete();

        return redirect()->route('subcontractors.index')->with('success', __('ui.subcontractors.deleted'));
    }

    // ── Workers ─────────────────────────────────────────────────────────────

    public function storeWorker(StoreSubcontractorWorkerRequest $request, Subcontractor $subcontractor, SubcontractorService $service): RedirectResponse
    {
        $service->addWorker($subcontractor, $request->validated());

        return back()->with('success', __('ui.subcontractors.worker_saved'));
    }

    public function updateWorker(StoreSubcontractorWorkerRequest $request, Subcontractor $subcontractor, SubcontractorWorker $worker, SubcontractorService $service): RedirectResponse
    {
        abort_unless($worker->subcontractor_id === $subcontractor->id, 404);
        $service->updateWorker($worker, $request->validated());

        return back()->with('success', __('ui.subcontractors.worker_saved'));
    }

    public function destroyWorker(Subcontractor $subcontractor, SubcontractorWorker $worker): RedirectResponse
    {
        Gate::authorize('subcontractors.edit');
        abort_unless($worker->subcontractor_id === $subcontractor->id, 404);
        $worker->delete();

        return back()->with('success', __('ui.subcontractors.worker_deleted'));
    }

    // ── Payments ────────────────────────────────────────────────────────────

    public function storePayment(StoreSubcontractorPaymentRequest $request, Subcontractor $subcontractor, SubcontractorService $service): RedirectResponse
    {
        $service->addPayment($subcontractor, $request->validated());

        return back()->with('success', __('ui.subcontractors.payment_saved'));
    }

    public function markPaid(Subcontractor $subcontractor, SubcontractorPayment $payment, SubcontractorService $service): RedirectResponse
    {
        Gate::authorize('subcontractors.edit');
        abort_unless($payment->subcontractor_id === $subcontractor->id, 404);
        $service->markPaid($payment);

        return back()->with('success', __('ui.subcontractors.payment_paid'));
    }

    public function markPending(Subcontractor $subcontractor, SubcontractorPayment $payment, SubcontractorService $service): RedirectResponse
    {
        Gate::authorize('subcontractors.edit');
        abort_unless($payment->subcontractor_id === $subcontractor->id, 404);
        $service->markPending($payment);

        return back()->with('success', __('ui.subcontractors.saved'));
    }

    public function destroyPayment(Subcontractor $subcontractor, SubcontractorPayment $payment, SubcontractorService $service): RedirectResponse
    {
        Gate::authorize('subcontractors.edit');
        abort_unless($payment->subcontractor_id === $subcontractor->id, 404);
        $service->deletePayment($payment);

        return back()->with('success', __('ui.subcontractors.payment_deleted'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(): array
    {
        return Project::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Project $p): array => ['id' => $p->id, 'name' => $p->name])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(): array
    {
        return Employee::query()->where('active', true)->orderBy('full_name')
            ->get(['id', 'full_name', 'designation'])
            ->map(fn (Employee $e): array => ['id' => $e->id, 'name' => $e->full_name, 'designation' => $e->designation])->all();
    }
}
