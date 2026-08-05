<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia page. Keep this payload small and never
     * put anything here the current user is not allowed to see.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'company_id' => $user->company_id,
                    'locale' => $user->locale,
                ] : null,
            ],
            'locale' => [
                'primary' => app()->getLocale(),
                'secondary' => app()->getLocale() === 'es' ? 'en' : 'es',
            ],
            // Active company context (id + name only): the user's company, or
            // the Super Admin's session selection (null while browsing all).
            'company' => $user === null ? null : (function () {
                $company = app(CurrentCompany::class)->get();

                return $company === null ? null : ['id' => $company->id, 'name' => $company->name];
            })(),
            // Companies the user may switch between (id + name only).
            // SA gets ALL companies for the inline header dropdown.
            // Admin/Manager get their pivot-assigned companies (>1 = switcher shows).
            // Workers have no CRM session at all.
            'companies' => $user instanceof User && ! $user->isWorker()
                ? ($user->isSuperAdmin()
                    ? Company::query()->orderBy('name')->get(['id', 'name'])
                        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                        ->all()
                    : $user->companies
                        ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
                        ->when(
                            $user->company !== null && ! $user->companies->contains('id', $user->company_id),
                            fn ($list) => $list->prepend(['id' => $user->company->id, 'name' => $user->company->name]),
                        )
                        ->values()
                        ->all())
                : [],
            // Full bilingual UI dictionary — both languages always ship because
            // every label renders Spanish + English (REQUIREMENTS.md §9).
            'lang' => [
                'es' => trans('ui', [], 'es'),
                'en' => trans('ui', [], 'en'),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            // Bell: unread count + the latest few (REQUIREMENTS.md §12)
            'notifications' => $user === null ? null : [
                'unread' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(8)->get()
                    ->map(fn ($notification) => [
                        'id' => $notification->id,
                        'read' => $notification->read_at !== null,
                        'created_at' => $notification->created_at?->diffForHumans(),
                        'data' => $notification->data,
                    ]),
            ],
        ]);
    }
}
