<?php

namespace App\Console\Commands;

use App\Enums\InvoiceType;
use App\Enums\NotificationType;
use App\Enums\PaymentStatus;
use App\Models\EmployeeCallLog;
use App\Models\Invoice;
use App\Models\VehicleSession;
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
        $sent += $this->scanUnreturnedVehicles($dispatcher);
        $sent += $this->scanCallFollowUps($dispatcher);

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
                'entity' => $worker, 'url' => '/call-panel',
            ]);

            $sent++;
        }

        return $sent;
    }
}
