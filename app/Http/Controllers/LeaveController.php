<?php

namespace App\Http\Controllers;

use App\Enums\LeaveStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Admin\Concerns\ResolvesCompanyContext;
use App\Http\Requests\AdjustLeaveBalanceRequest;
use App\Http\Requests\ReviewLeaveRequest;
use App\Http\Requests\StoreLeaveRequest;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveCategory;
use App\Services\Audit\AuditLogger;
use App\Services\Leave\LeaveService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Screen 22 — Leave Management. Company-owned.
 *
 * Approving is what books the days into the attendance grid and therefore
 * into payroll — the work lives in LeaveService, not here.
 */
class LeaveController extends Controller
{
    use ResolvesCompanyContext;

    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): Response
    {
        Gate::authorize('leave_management.view');

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true) ? $request->integer('per_page') : 25;

        $leaves = $this->filteredQuery($request)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Leave $l): array => $this->row($l));

        return Inertia::render('Leave/Index', [
            'leaves' => $leaves,
            'filters' => (object) $request->only(['employee_id', 'status', 'leave_category_id', 'date_from', 'date_to', 'per_page']),
            // Filter dropdown = all employees; create form uses active-only `formEmployees`.
            'employees' => Employee::query()->orderBy('full_name')->get(['id', 'full_name']),
            'formEmployees' => Employee::query()->active()->orderBy('full_name')->get(['id', 'full_name']),
            'categories' => $this->availableCategories(),
            'statuses' => array_map(fn (LeaveStatus $s): string => $s->value, LeaveStatus::cases()),
            'balances' => $this->balances($request),
            'can' => [
                'create' => Gate::allows('leave_management.create'),
                'edit' => Gate::allows('leave_management.edit'),
                'delete' => Gate::allows('leave_management.delete'),
                'approve' => Gate::allows('leave_management.approve'),
            ],
        ]);
    }

    public function store(StoreLeaveRequest $request): RedirectResponse
    {
        $companyId = $this->contextCompanyId();

        $leave = $this->leave->request([
            'employee_id' => $request->integer('employee_id'),
            'leave_category_id' => $request->integer('leave_category_id'),
            'start_date' => $request->string('start_date')->value(),
            'end_date' => $request->string('end_date')->value(),
            'total_days' => $request->string('total_days')->value(),
            'reason' => $request->string('reason')->value() ?: null,
        ]);

        if ($request->hasFile('attachment')) {
            $this->storeAttachment($request, $leave, $companyId);
        }

        $name = $leave->employee?->full_name;
        app(NotificationDispatcher::class)->dispatch(NotificationType::LeavePending, $companyId, [
            'title_es' => "Nueva solicitud de ausencia: {$name}",
            'title_en' => "New leave request: {$name}",
            'entity' => $name, 'url' => '/leave',
        ]);

        return back()->with('success', __('ui.leave.requested'));
    }

    public function approve(ReviewLeaveRequest $request, Leave $leave): RedirectResponse
    {
        $this->leave->approve($leave, $request->string('review_notes')->value() ?: null);
        $this->notifyWorker($leave, true);

        return back()->with('success', __('ui.leave.approved'));
    }

    public function reject(ReviewLeaveRequest $request, Leave $leave): RedirectResponse
    {
        $this->leave->reject($leave, $request->string('review_notes')->value() ?: null);
        $this->notifyWorker($leave, false);

        return back()->with('success', __('ui.leave.rejected'));
    }

    /** Tell the worker their leave was approved / rejected (PWA bell). */
    private function notifyWorker(Leave $leave, bool $approved): void
    {
        $from = $leave->start_date->toDateString();
        $to = $leave->end_date->toDateString();

        app(NotificationDispatcher::class)->dispatchToUser(
            NotificationType::LeaveDecided,
            $leave->employee?->user,
            [
                'title_es' => $approved ? 'Tu permiso fue aprobado' : 'Tu permiso fue rechazado',
                'title_en' => $approved ? 'Your leave was approved' : 'Your leave was rejected',
                'body_es' => $approved
                    ? "Tu solicitud de permiso del {$from} al {$to} ha sido aprobada."
                    : 'Tu solicitud de permiso ha sido rechazada.',
                'body_en' => $approved
                    ? "Your leave request from {$from} to {$to} has been approved."
                    : 'Your leave request has been rejected.',
                'entity' => $from, 'url' => '/worker',
            ],
        );
    }

    public function cancel(Request $request, Leave $leave): RedirectResponse
    {
        Gate::authorize('leave_management.edit');

        $this->leave->cancel($leave, $request->string('review_notes')->value() ?: null);

        return back()->with('success', __('ui.leave.cancelled'));
    }

    public function adjustBalance(AdjustLeaveBalanceRequest $request, LeaveBalance $balance): RedirectResponse
    {
        $this->leave->adjustBalance(
            $balance,
            (float) $request->input('allocated'),
            (float) $request->input('carried_over'),
        );

        return back()->with('success', __('ui.leave.balance_adjusted'));
    }

    /**
     * The justificante lands on the private disk under the company that owns
     * the leave — never in public/, and the filename is randomised (Rule 10).
     */
    private function storeAttachment(StoreLeaveRequest $request, Leave $leave, int $companyId): void
    {
        $file = $request->file('attachment');

        $path = $file->store("leave/{$companyId}/{$leave->id}", 'local');

        $leave->file_path = $path;
        $leave->original_name = $file->getClientOriginalName();
        $leave->save();
    }

    public function download(Leave $leave): mixed
    {
        Gate::authorize('leave_management.view');

        abort_if($leave->file_path === null, 404);
        abort_unless(Storage::disk('local')->exists($leave->file_path), 404);

        // Leave attachments can be medical certificates — audit the access, like
        // every other private-file download (Rule 10).
        app(AuditLogger::class)->log('viewed', $leave, null, null, 'Leave attachment', 'leave_management');

        return Storage::disk('local')->download($leave->file_path, $leave->original_name);
    }

    /**
     * Shared by the screen and (later) its export, so "export the filtered
     * view" cannot drift from what is on screen.
     *
     * @return Builder<Leave>
     */
    private function filteredQuery(Request $request): Builder
    {
        return Leave::query()
            ->with(['employee:id,full_name', 'category:id,key,name,is_paid', 'reviewer:id,name'])
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('leave_category_id'), fn (Builder $q) => $q->where('leave_category_id', $request->integer('leave_category_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('start_date', '>=', $request->string('date_from')->value()))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('end_date', '<=', $request->string('date_to')->value()))
            ->orderByDesc('start_date');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Leave $l): array
    {
        return [
            'id' => $l->id,
            'employee' => $l->employee?->full_name,
            'employee_id' => $l->employee_id,
            'category' => $l->category?->label(),
            // leave_category_id is a required FK, so the relation always resolves
            'is_paid' => $l->category->is_paid,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'total_days' => (float) $l->total_days,
            'reason' => $l->reason,
            'status' => $l->status->value,
            'reviewed_by' => $l->reviewer?->name,
            'reviewed_at' => $l->reviewed_at?->toDateTimeString(),
            'review_notes' => $l->review_notes,
            'has_attachment' => $l->file_path !== null,
            'original_name' => $l->original_name,
        ];
    }

    /**
     * The Balances view — allocated/used/pending/carried over/remaining for
     * the selected year.
     *
     * @return array<int, array<string, mixed>>
     */
    private function balances(Request $request): array
    {
        $year = $request->filled('year') ? $request->integer('year') : (int) now()->format('Y');

        return LeaveBalance::query()
            ->with(['employee:id,full_name', 'category:id,key,name'])
            ->where('year', $year)
            ->get()
            ->map(fn (LeaveBalance $b): array => [
                'id' => $b->id,
                'employee' => $b->employee?->full_name,
                'category' => $b->category?->label(),
                'year' => $b->year,
                'allocated' => (float) $b->allocated,
                'used' => (float) $b->used,
                'pending' => (float) $b->pending,
                'carried_over' => (float) $b->carried_over,
                'remaining' => $b->remaining(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableCategories(): array
    {
        return LeaveCategory::query()
            ->forCompany(app(CurrentCompany::class)->id())
            ->where('active', true)
            ->get(['id', 'key', 'name', 'is_paid', 'default_allocation'])
            // Sorted on the translated label, not the stored Spanish name —
            // otherwise the English list comes out in Spanish alphabetical order.
            ->sortBy(fn (LeaveCategory $c): string => $c->label())
            ->map(fn (LeaveCategory $c): array => [
                'id' => $c->id,
                'name' => $c->label(),
                'is_paid' => $c->is_paid,
                'default_allocation' => (float) $c->default_allocation,
            ])
            ->values()
            ->all();
    }
}
