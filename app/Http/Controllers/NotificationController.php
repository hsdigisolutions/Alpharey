<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Support\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The notification bell + the full /notifications page. The latest items and
 * the unread count also ship with every Inertia page via HandleInertiaRequests
 * (the bell); this controller owns the standalone page and the read/delete
 * actions. Notifications are Laravel database notifications keyed to the
 * authenticated user, so every query is naturally scoped to them.
 */
class NotificationController extends Controller
{
    /** Filter tab → the categories (NotificationType::category) it shows. */
    private const FILTERS = ['all', 'unread', 'documents', 'payroll', 'invoices', 'vehicles', 'workers'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filter = in_array($request->string('filter')->value(), self::FILTERS, true)
            ? $request->string('filter')->value()
            : 'all';

        $query = $user?->notifications()->latest();

        if ($filter === 'unread') {
            $query?->whereNull('read_at');
        } elseif ($filter !== 'all') {
            // Category tab → the notification types that map to it. Filter at
            // the DB level (on the data->type JSON path, portable across MySQL
            // and SQLite) so pagination stays accurate.
            $types = array_map(
                fn (NotificationType $t) => $t->value,
                array_filter(NotificationType::cases(), fn (NotificationType $t) => $t->category() === $filter),
            );
            $query?->whereIn('data->type', array_values($types));
        }

        $paginator = $query?->paginate(25)->withQueryString();

        $items = collect($paginator?->items() ?? [])
            ->map(fn (DatabaseNotification $n) => NotificationPresenter::present($n));

        return Inertia::render('Notifications/Index', [
            'items' => $items->values(),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'unread' => $user?->unreadNotifications()->count() ?? 0,
            'pagination' => $paginator === null ? null : [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()?->unreadNotifications->markAsRead();

        return back();
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()?->notifications()->where('id', $id)->first();

        $notification?->markAsRead();

        return back();
    }

    /** Delete every already-read notification (the /notifications "clear" action). */
    public function deleteRead(Request $request): RedirectResponse
    {
        $request->user()?->readNotifications()->delete();

        return back();
    }
}
