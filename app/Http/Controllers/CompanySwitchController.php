<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;

/**
 * Header company switcher for Admins/Managers assigned to more than one
 * company on the user_company pivot. CurrentCompany::select() enforces the
 * pivot membership (403 otherwise) — this is the multi-company counterpart
 * of the Super Admin's Welcome-screen selection.
 */
class CompanySwitchController extends Controller
{
    public function __invoke(Company $company, CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->select($company);

        // Land on the dashboard: the page the user was on may not exist in
        // the newly selected company's data.
        return redirect()->route('dashboard');
    }
}
