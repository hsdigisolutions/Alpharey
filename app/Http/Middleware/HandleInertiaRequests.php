<?php

namespace App\Http\Middleware;

use App\Models\User;
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
        ]);
    }
}
