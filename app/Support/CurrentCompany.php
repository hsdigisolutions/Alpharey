<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Resolves the active company for the request (SECURITY.md §2).
 *
 * The active company comes ONLY from the authenticated user, or — for the
 * Super Admin — from an explicit, session-stored selection made on the
 * Welcome screen. It is never read from request input.
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

        if ($user->isSuperAdmin()) {
            $selected = Session::get(self::SESSION_KEY);

            return is_numeric($selected) ? (int) $selected : null;
        }

        return $user->company_id;
    }

    public function get(): ?Company
    {
        $id = $this->id();

        return $id === null ? null : Company::query()->find($id);
    }

    /**
     * Store the Super Admin's explicit company selection. Non-super users
     * are permanently bound to their own company and cannot switch.
     */
    public function select(Company|int $company): void
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            abort(403, 'Only the Super Admin can switch companies.');
        }

        Session::put(self::SESSION_KEY, $company instanceof Company ? $company->id : $company);
    }

    public function clearSelection(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
