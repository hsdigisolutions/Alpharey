<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Screen 25 — Audit Logs (Super Admin + Company Admin, read-only).
 * Company Admins see their own company's trail only; the Super Admin
 * sees everything, or the selected company. No update or delete routes
 * exist anywhere for audit logs.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = $this->filteredQuery($request);

        $perPage = in_array($request->integer('per_page'), [25, 50, 100], true)
            ? $request->integer('per_page')
            : 25;

        $logs = $query->clone()
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'created_at' => $log->created_at->toDateTimeString(),
                'user_name' => $log->user_name,
                'user_email' => $log->user_email,
                'action' => $log->action,
                'module' => $log->module,
                'entity_name' => $log->entity_name,
                'model_type' => $log->model_type !== null ? class_basename($log->model_type) : null,
                'model_id' => $log->model_id,
                'description' => $log->description,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'request_method' => $log->request_method,
            ]);

        return Inertia::render('Admin/AuditLogs', [
            'logs' => $logs,
            'filters' => (object) $request->only(['action', 'module', 'user', 'date_from', 'date_to', 'per_page']),
            'stats' => [
                'by_action' => $this->filteredQuery($request)
                    ->select('action')->selectRaw('count(*) as total')
                    ->groupBy('action')->orderByDesc('total')->limit(6)->pluck('total', 'action'),
                'by_module' => $this->filteredQuery($request)
                    ->whereNotNull('module')
                    ->select('module')->selectRaw('count(*) as total')
                    ->groupBy('module')->orderByDesc('total')->limit(5)->pluck('total', 'module'),
                'by_user' => $this->filteredQuery($request)
                    ->whereNotNull('user_name')
                    ->select('user_name')->selectRaw('count(*) as total')
                    ->groupBy('user_name')->orderByDesc('total')->limit(5)->pluck('total', 'user_name'),
            ],
            'actionOptions' => ['created', 'updated', 'deleted', 'exported', 'login', 'logout'],
        ]);
    }

    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $query = $this->filteredQuery($request)->orderByDesc('id');

        $audit->log('exported', null, null, null, 'Audit log CSV export', 'audit_logs');

        $filename = 'audit-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'id', 'timestamp', 'user_name', 'user_email', 'action', 'module',
                'entity_name', 'model_type', 'model_id', 'description',
                'old_values', 'new_values', 'ip_address', 'request_method',
                'request_url', 'user_agent',
            ]);

            $query->chunk(500, function ($logs) use ($handle): void {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->created_at->toDateTimeString(),
                        $log->user_name,
                        $log->user_email,
                        $log->action,
                        $log->module,
                        $log->entity_name,
                        $log->model_type,
                        $log->model_id,
                        $log->description,
                        json_encode($log->old_values),
                        json_encode($log->new_values),
                        $log->ip_address,
                        $log->request_method,
                        $log->request_url,
                        $log->user_agent,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = AuditLog::query();

        // Company Admins are locked to their company; the Super Admin sees
        // all rows or the selected company's.
        if ($user instanceof User && ! $user->isSuperAdmin()) {
            $query->where('company_id', $user->company_id);
        } elseif (($selected = app(CurrentCompany::class)->id()) !== null) {
            $query->where('company_id', $selected);
        }

        return $query
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', $request->string('action')))
            ->when($request->filled('module'), fn (Builder $q) => $q->where('module', $request->string('module')))
            ->when($request->filled('user'), fn (Builder $q) => $q->where(function (Builder $q) use ($request): void {
                $q->where('user_name', 'like', '%'.$request->string('user').'%')
                    ->orWhere('user_email', 'like', '%'.$request->string('user').'%');
            }))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->string('date_to')));
    }
}
