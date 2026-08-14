<?php

namespace App\Console\Commands;

use App\Enums\EquipmentIssueStatus;
use App\Enums\InvoiceType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCallLog;
use App\Models\EmployeeEquipmentIssue;
use App\Models\EquipmentItem;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Project;
use App\Models\Scopes\CompanyScope;
use App\Models\VehicleSession;
use App\Services\Inventory\PpeComplianceService;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Daily sweep for the time-based notifications that are NOT document/vehicle
 * expiries (those live in verto:scan-documents):
 *
 *   - a SALE invoice that has crossed its due date unpaid  → Company Admins
 *   - a company vehicle out for more than 24 h unreturned  → Company Admins
 *   - a call follow-up that falls due today                → the caller
 *
 * Every alert routes through NotificationDispatcher, so the Settings matrix
 * governs the role-based ones and the worker-direct call alert bypasses it.
 * System context: every query opts out of the tenancy scope explicitly.
 */
class ScanAlerts extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'Send overdue-invoice, unreturned-vehicle and call-follow-up notifications';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $sent = 0;
        $sent += $this->scanOverdueInvoices($dispatcher);
        $sent += $this->scanInvoiceReminders($dispatcher);
        $sent += $this->scanUnreturnedVehicles($dispatcher);
        $sent += $this->scanCallFollowUps($dispatcher);
        $sent += $this->scanLowStock($dispatcher);
        $sent += $this->scanOverdueReturns($dispatcher);
        $sent += $this->scanPpeExpiring($dispatcher);
        $sent += $this->scanPpeMissing($dispatcher);

        $this->components->info("Alert scan complete — {$sent} notification group(s) sent.");

        return self::SUCCESS;
    }

    /**
     * A sale invoice that is unpaid/partly-paid and past its due date, alerted
     * exactly once (the overdue_notified_at flag), never re-spammed daily.
     */
    private function scanOverdueInvoices(NotificationDispatcher $dispatcher): int
    {
        $today = now()->startOfDay()->toDateString();

        $invoices = Invoice::query()
            ->withoutGlobalScopes()
            ->where('type', InvoiceType::Sale->value)
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value, PaymentStatus::Pending->value])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            $dispatcher->dispatch(NotificationType::InvoiceOverdue, $invoice->company_id, [
                'title_es' => "Factura vencida sin pagar: {$invoice->number}",
                'title_en' => "Overdue unpaid invoice: {$invoice->number}",
                'entity' => $invoice->number, 'url' => '/invoices',
            ]);

            $invoice->overdue_notified_at = now();
            $invoice->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * A project with worked days THIS month but no sale invoice dated in it —
     * "you've worked, remember to bill". The dormant invoice_reminders table is
     * the cadence guard: a project is reminded again only after reminder_days
     * (default 7) since the last reminder for the month.
     */
    private function scanInvoiceReminders(NotificationDispatcher $dispatcher): int
    {
        $month = now()->format('Y-m');
        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();

        $projectIds = Attendance::query()->withoutGlobalScopes()
            ->whereNotNull('project_id')
            ->whereBetween('date', [$start, $end])
            ->distinct()->pluck('project_id');

        $sent = 0;

        foreach ($projectIds as $projectId) {
            $project = Project::withoutGlobalScopes()->find($projectId);

            if ($project === null) {
                continue;
            }

            $alreadyInvoiced = Invoice::query()->withoutGlobalScopes()
                ->where('type', InvoiceType::Sale->value)
                ->where('project_id', $projectId)
                ->whereBetween('invoice_date', [$start, $end])
                ->exists();

            if ($alreadyInvoiced) {
                continue;
            }

            $reminder = InvoiceReminder::withoutGlobalScopes()
                ->firstOrNew(['project_id' => $projectId, 'month' => $month]);

            // The column defaults to 0; treat 0/null as the standard 7-day cadence.
            $days = (int) ($reminder->reminder_days ?: 7);

            if ($reminder->last_sent_at !== null && $reminder->last_sent_at->gt(now()->subDays($days))) {
                continue; // reminded within the cadence window already
            }

            $dispatcher->dispatch(NotificationType::InvoiceReminder, $project->company_id, [
                'title_es' => "Obra sin facturar este mes: {$project->name}",
                'title_en' => "Project not invoiced this month: {$project->name}",
                'entity' => $project->name, 'url' => '/invoices',
            ]);

            $reminder->company_id = $project->company_id;
            $reminder->period_start = $start;
            $reminder->period_end = $end;
            $reminder->last_sent_at = now();
            $reminder->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * A worker vehicle session still open more than 24 h after it was taken.
     * The overdue_alerted flag (Phase A) makes this fire once per session.
     */
    private function scanUnreturnedVehicles(NotificationDispatcher $dispatcher): int
    {
        $cutoff = now()->subDay();

        $sessions = VehicleSession::query()
            ->withoutGlobalScopes()
            ->whereNull('returned_at')
            ->where('overdue_alerted', false)
            ->where('taken_at', '<', $cutoff)
            // Drop the tenant scope on the eager loads too — a console run has no
            // current company, so a scoped relation would resolve to null.
            ->with([
                'vehicle' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'plate_number', 'company_id'),
                'employee' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'full_name', 'company_id'),
            ])
            ->get();

        $sent = 0;

        foreach ($sessions as $session) {
            $plate = $session->vehicle->plate_number;
            $worker = $session->employee->full_name;

            $dispatcher->dispatch(NotificationType::VehicleNotReturned, $session->company_id, [
                'title_es' => "Vehículo sin devolver (+24 h): {$plate} — {$worker}",
                'title_en' => "Vehicle not returned (+24 h): {$plate} — {$worker}",
                'entity' => $plate, 'url' => '/vehicles',
            ]);

            $session->overdue_alerted = true;
            $session->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * A call whose follow-up date is today, sent to the user who logged it.
     * Worker-direct (a specific caller), so it bypasses the role matrix.
     */
    private function scanCallFollowUps(NotificationDispatcher $dispatcher): int
    {
        $today = now()->startOfDay()->toDateString();

        $logs = EmployeeCallLog::query()
            ->withoutGlobalScopes()
            ->whereDate('follow_up_date', $today)
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'full_name', 'company_id'),
                'caller' => fn ($q) => $q->select('id', 'name'),
            ])
            ->get();

        $sent = 0;

        foreach ($logs as $log) {
            if ($log->caller === null) {
                continue;
            }

            $worker = $log->employee->full_name;

            $dispatcher->dispatchToUser(NotificationType::CallFollowUp, $log->caller, [
                'title_es' => "Seguimiento de llamada hoy: {$worker}",
                'title_en' => "Call follow-up due today: {$worker}",
                'entity' => $worker, 'url' => '/calls',
            ]);

            $sent++;
        }

        return $sent;
    }

    /**
     * An item at/below its reorder minimum, alerted once (low_stock_notified_at).
     * The movement service re-arms the flag when stock recovers above minimum.
     */
    private function scanLowStock(NotificationDispatcher $dispatcher): int
    {
        // Keep the SoftDeletes scope (drop only tenancy) so retired items are out.
        $items = EquipmentItem::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('minimum_stock', '>', 0)
            ->whereColumn('available_stock', '<=', 'minimum_stock')
            ->whereNull('low_stock_notified_at')
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            $left = rtrim(rtrim((string) $item->available_stock, '0'), '.');

            $dispatcher->dispatch(NotificationType::InventoryLowStock, $item->company_id, [
                'title_es' => "Stock bajo: {$item->name} — {$left} {$item->unit}",
                'title_en' => "Low stock: {$item->name} — {$left} {$item->unit}",
                'entity' => $item->name, 'url' => '/inventory',
            ]);

            $item->low_stock_notified_at = now();
            $item->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * Kit past its expected return, still out — alerted once per issue.
     */
    private function scanOverdueReturns(NotificationDispatcher $dispatcher): int
    {
        $today = now()->startOfDay()->toDateString();

        $issues = EmployeeEquipmentIssue::query()
            ->withoutGlobalScopes()
            ->where('status', '!=', EquipmentIssueStatus::Returned->value)
            ->whereNotNull('expected_return_date')
            ->whereDate('expected_return_date', '<', $today)
            ->whereNull('overdue_notified_at')
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'full_name', 'company_id'),
                'item' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name', 'company_id'),
            ])
            ->get();

        $sent = 0;

        foreach ($issues as $issue) {
            $worker = $issue->employee->full_name;
            $item = $issue->item->name;
            $due = $issue->expected_return_date?->toDateString();

            $dispatcher->dispatch(NotificationType::EquipmentOverdue, $issue->company_id, [
                'title_es' => "{$worker} no ha devuelto {$item} (vencía {$due})",
                'title_en' => "{$worker} has not returned {$item} (due {$due})",
                'entity' => $item, 'url' => '/inventory',
            ]);

            $issue->overdue_notified_at = now();
            $issue->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * A PPE issue expiring within 30 days (or already expired), still out —
     * alerted once per issue (ppe_expiry_notified_at).
     */
    private function scanPpeExpiring(NotificationDispatcher $dispatcher): int
    {
        $limit = now()->addDays(30)->toDateString();

        $issues = EmployeeEquipmentIssue::query()
            ->withoutGlobalScopes()
            ->where('status', '!=', EquipmentIssueStatus::Returned->value)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $limit)
            ->whereNull('ppe_expiry_notified_at')
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'full_name', 'company_id'),
                'item' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name', 'company_id'),
            ])
            ->get();

        $sent = 0;

        foreach ($issues as $issue) {
            $worker = $issue->employee->full_name;
            $item = $issue->item->name;
            $date = $issue->expiry_date?->toDateString();
            $expired = $issue->expiry_date?->isPast() ?? false;

            $dispatcher->dispatch(NotificationType::PpeExpiring, $issue->company_id, [
                'title_es' => $expired ? "{$worker} — EPI caducado: {$item} ({$date})" : "{$worker} — EPI caduca pronto: {$item} ({$date})",
                'title_en' => $expired ? "{$worker} — expired PPE: {$item} ({$date})" : "{$worker} — PPE expiring soon: {$item} ({$date})",
                'entity' => $item, 'url' => '/inventory',
            ]);

            $issue->ppe_expiry_notified_at = now();
            $issue->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * Required PPE not held by active workers — a per-company daily SUMMARY (one
     * notification listing the count), so a big non-compliant crew does not flood
     * the bell. Mirrors the auto-absent summary; fires only when there are gaps.
     */
    private function scanPpeMissing(NotificationDispatcher $dispatcher): int
    {
        $compliance = app(PpeComplianceService::class);
        $sent = 0;

        Company::query()->whereNull('deleted_at')->get()->each(function (Company $company) use ($dispatcher, $compliance, &$sent): void {
            $workers = Employee::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->where('active', true)
                ->get();

            // Opt-in: only the company's OWN required-PPE categories trigger the
            // proactive alert (the shared defaults still drive the on-screen
            // report). A company signals "alert me" by flagging its own PPE.
            $missing = $workers->filter(
                fn (Employee $w) => collect($compliance->forEmployee($w, ownRequiredOnly: true))->contains(fn (array $row) => $row['status'] === 'missing'),
            )->count();

            if ($missing === 0) {
                return;
            }

            $dispatcher->dispatch(NotificationType::PpeMissing, $company->id, [
                'title_es' => "EPIs: {$missing} trabajador(es) sin EPI obligatorio",
                'title_en' => "PPE: {$missing} worker(s) missing required PPE",
                'entity' => (string) $missing, 'url' => '/employees',
            ]);

            $sent++;
        });

        return $sent;
    }
}
