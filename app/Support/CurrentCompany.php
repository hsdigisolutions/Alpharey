<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Resolves the active company for the request (SECURITY.md §2).
 *
 * The active company comes ONLY from the authenticated user, or from an
 * explicit, session-stored selection. It is never read from request input.
 *
 *  - Super Admin: any company, chosen on the Welcome screen (null = browse all)
 *  - Admin / Manager: their primary company by default; when they hold several
 *    assignments on the user_company pivot they may switch between them, and
 *    the selection is validated against the pivot ON EVERY REQUEST — a stale
 *    or forged session value falls back to the primary company.
 */
class CurrentCompany
{
    private const SESSION_KEY = 'current_company_id';

    /**
     * The company id scoping this request, or null when there is none
     * (guest, or Super Admin browsing without a selection).
     */
    public function id(): ?int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        $selected = Session::get(self::SESSION_KEY);
        $selectedId = is_numeric($selected) ? (int) $selected : null;

        if ($user->isSuperAdmin()) {
            return $selectedId;
        }

        // A non-SA selection only counts while the pivot still backs it —
        // revoking an assignment invalidates the session choice immediately.
        if ($selectedId !== null
            && $selectedId !== $user->company_id
            && $user->isAssignedToCompany($selectedId)) {
            return $selectedId;
        }

        return $user->company_id;
    }

    public function get(): ?Company
    {
        $id = $this->id();

        return $id === null ? null : Company::query()->find($id);
    }

    /**
     * Store an explicit company selection. The Super Admin may select any
     * company; everyone else only a company they are assigned to on the
     * user_company pivot.
     */
    public function select(Company|int $company): void
    {
        $user = Auth::user();
        $companyId = $company instanceof Company ? $company->id : $company;

        if (! $user instanceof User) {
            abort(403);
        }

        if (! $user->isSuperAdmin()
            && $companyId !== $user->company_id
            && ! $user->isAssignedToCompany($companyId)) {
            abort(403, 'You are not assigned to this company.');
        }

        Session::put(self::SESSION_KEY, $companyId);
    }

    public function clearSelection(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
