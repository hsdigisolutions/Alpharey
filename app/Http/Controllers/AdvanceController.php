<?php

namespace App\Http\Controllers;

use App\Enums\AdvanceStatus;
use App\Enums\NotificationType;
use App\Models\Advance;
use App\Models\AdvanceCategory;
use App\Rules\OwnCompanyEmployee;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Payroll\PayrollService;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Salary advances (Screen 12, lower section). Gated on the payroll module —
 * an advance is pay data, so the amount is encrypted and only exposed to
 * payroll.view holders.
 */
class AdvanceController extends Controller
{
    /** How an advance was paid out (Issue 2). */
    private const PAYMENT_METHODS = ['bank_transfer', 'cash'];

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('payroll.create');

        $validated = $request->validate([
            // Ownership is validity: an id from another company would deduct
            // from THAT company's payslip (payroll gathers per employee).
            'employee_id' => ['required', 'integer', new OwnCompanyEmployee],
            // Own-company or group-wide default category only (categories are
            // shared reference data, so a bare exists would accept another
            // tenant's private category).
            'advance_category_id' => ['nullable', 'integer', Rule::exists('advance_categories', 'id')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', app(CurrentCompany::class)->id()))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_date' => ['required', 'date'],
            'payroll_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            // How it was paid out (Issue 2): bank transfer (with a receipt) or cash.
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            // Added straight from the Payroll screen = a deliberate deduction:
            // approve it on the spot so it comes off this month's pay, instead of
            // sitting Pending (a Pending advance never deducts).
            'approve' => ['nullable', 'boolean'],
        ]);

        $companyId = app(CurrentCompany::class)->id();
        $autoApprove = ($validated['approve'] ?? false) && Gate::allows('payroll.approve');

        $advanceData = $validated;
        unset($advanceData['approve'], $advanceData['receipt']);

        $advance = new Advance($advanceData);
        $advance->company_id = $companyId;

        if ($autoApprove) {
            $advance->status = AdvanceStatus::Approved;
            $advance->approved_by = Auth::id();
            $advance->approved_at = now();
        } else {
            $advance->status = AdvanceStatus::Pending;
        }

        $advance->save();
        $this->storeReceipt($request, $advance);

        // Reflect an approved advance immediately: recompute the month it targets
        // so the payroll row shows the deduction without a second "Calculate".
        if ($autoApprove && $companyId !== null && $advance->payroll_month !== null) {
            app(PayrollService::class)->calculateMonth($companyId, $advance->payroll_month);
        }

        // A request awaiting review pings the admins/managers.
        if (! $autoApprove && $companyId !== null) {
            $name = $advance->employee?->full_name;
            app(NotificationDispatcher::class)->dispatch(NotificationType::AdvancePending, $companyId, [
                'title_es' => "Nuevo anticipo pendiente: {$name}",
                'title_en' => "New advance pending: {$name}",
                'entity' => $name, 'url' => '/payroll',
            ]);
        }

        return back()->with('success', __('ui.advances.saved'));
    }

    /**
     * Correct an advance (Issue 1) — amount, reason, date, target month, payment
     * method + receipt. A DEDUCTED advance is settled (already off a PAID
     * payroll) and immutable, the same guard decide()/destroy() use. Editing a
     * Pending/Approved advance is safe: an approved one's target month(s) are
     * recomputed so the deduction updates at once.
     */
    public function update(Request $request, Advance $advance): RedirectResponse
    {
        Gate::authorize('payroll.edit');

        abort_if($advance->status === AdvanceStatus::Deducted, 422, 'Deducted advances cannot be edited.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_date' => ['required', 'date'],
            'payroll_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'payment_method' => ['nullable', Rule::in(self::PAYMENT_METHODS)],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        $oldMonth = $advance->payroll_month;

        $advance->fill([
            'amount' => $validated['amount'],
            'reason' => $validated['reason'] ?? null,
            'request_date' => $validated['request_date'],
            'payroll_month' => $validated['payroll_month'] ?? null,
            'payment_method' => $validated['payment_method'] ?? null,
        ]);
        $advance->save();
        $this->storeReceipt($request, $advance);

        // Reflect the correction: recompute the affected month(s) so the payroll
        // deduction updates without a manual "Calculate". Only an APPROVED advance
        // deducts (a Pending one does not), and a locked/paid month is left alone
        // by calculateMonth (PeriodLock).
        $companyId = app(CurrentCompany::class)->id();
        if ($advance->status === AdvanceStatus::Approved && $companyId !== null) {
            foreach (array_unique(array_filter([$oldMonth, $advance->payroll_month])) as $month) {
                app(PayrollService::class)->calculateMonth($companyId, $month);
            }
        }

        return back()->with('success', __('ui.advances.updated'));
    }

    /**
     * Approve or reject. A deducted advance is settled and immutable — it has
     * already come off a payroll, so re-deciding it would silently change
     * money that was paid.
     */
    public function decide(Request $request, Advance $advance): RedirectResponse
    {
        Gate::authorize('payroll.approve');

        abort_if($advance->status === AdvanceStatus::Deducted, 422, 'Deducted advances cannot be changed.');

        $validated = $request->validate([
            'status' => ['required', Rule::in([AdvanceStatus::Approved->value, AdvanceStatus::Rejected->value])],
            'payroll_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $advance->status = AdvanceStatus::from($validated['status']);
        $advance->approved_by = Auth::id();
        $advance->approved_at = now();

        if (isset($validated['payroll_month'])) {
            $advance->payroll_month = $validated['payroll_month'];
        }

        $advance->save();

        // Tell the worker their advance was approved / rejected (PWA bell).
        $approved = $advance->status === AdvanceStatus::Approved;
        app(NotificationDispatcher::class)->dispatchToUser(
            NotificationType::AdvanceDecided,
            $advance->employee?->user,
            [
                'title_es' => $approved ? 'Tu anticipo fue aprobado' : 'Tu anticipo fue rechazado',
                'title_en' => $approved ? 'Your advance was approved' : 'Your advance was rejected',
                'entity' => $advance->reason, 'url' => '/worker',
            ],
        );

        return back()->with('success', __('ui.advances.decided'));
    }

    public function destroy(Advance $advance): RedirectResponse
    {
        Gate::authorize('payroll.edit');

        abort_if($advance->status === AdvanceStatus::Deducted, 422, 'Deducted advances cannot be removed.');

        $advance->delete();

        return back()->with('success', __('ui.advances.deleted'));
    }

    /**
     * Download the bank-transfer receipt — gated (pay data) + audited, and the
     * file is served only through here, never a public URL (Rule 10).
     */
    public function downloadReceipt(Advance $advance, AuditLogger $audit): StreamedResponse
    {
        Gate::authorize('payroll.view');

        $path = $advance->receipt_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        $audit->log('viewed', $advance, null, null, 'Advance receipt', 'payroll');

        return Storage::disk('local')->download($path, $advance->receipt_name ?? 'recibo');
    }

    /**
     * Store an uploaded bank-transfer receipt on the private disk (randomized
     * name, original kept as metadata). Server-set columns — never mass-assigned.
     */
    private function storeReceipt(Request $request, Advance $advance): void
    {
        $file = $request->file('receipt');
        if ($file === null) {
            return;
        }

        $old = $advance->receipt_path;
        if ($old !== null && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
        }

        $folder = 'advances/'.$advance->company_id.'/'.$advance->id;
        $advance->receipt_path = $file->storeAs($folder, Str::random(40).'.'.$file->getClientOriginalExtension(), 'local');
        $advance->receipt_name = $file->getClientOriginalName();
        $advance->save();
    }

    /**
     * Settings → advance categories.
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        Gate::authorize('payroll.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        AdvanceCategory::query()->create([
            'company_id' => app(CurrentCompany::class)->id(),
            'name' => $validated['name'],
            'active' => true,
        ]);

        return back()->with('success', __('ui.advances.category_saved'));
    }
}
