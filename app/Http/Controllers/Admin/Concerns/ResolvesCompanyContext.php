<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Support\CurrentCompany;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Admin screens that operate on one company's data (permission matrix,
 * user management) need an active company context. Company Admins always
 * have one; a Super Admin browsing without a selection is sent to the
 * Welcome screen to pick a company first.
 */
trait ResolvesCompanyContext
{
    protected function contextCompanyId(): int
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            throw new HttpResponseException(
                redirect()->route('welcome')->with('error', __('ui.permissions.select_company_first')),
            );
        }

        return $companyId;
    }
}
