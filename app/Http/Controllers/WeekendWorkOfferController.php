<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\StoreWeekendOfferRequest;
use App\Models\WeekendWorkOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Weekend Work Offers (Screen 11 side panel). An admin publishes an offer for a
 * weekend date; only then, and only for the invited workers, does the PWA allow
 * a weekend check-in. One offer per company per date (re-publishing replaces).
 */
class WeekendWorkOfferController extends Controller
{
    public function store(StoreWeekendOfferRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // One offer per (company, date): re-publishing the same date updates it.
        // The company_id is filled by the BelongsToCompany creating hook — never
        // from input — and the match query is company-scoped by the global scope.
        $offer = WeekendWorkOffer::query()->firstOrNew(['offer_date' => $data['offer_date']]);
        $offer->fill([
            'project_id' => $data['project_id'] ?? null,
            'offer_date' => $data['offer_date'],
            'weekend_rate_type' => $data['weekend_rate_type'],
            'weekend_rate_amount' => $data['weekend_rate_amount'] ?? null,
            'invited_employee_ids' => array_map('intval', $data['invited_employee_ids']),
        ]);
        $offer->created_by = $request->user()?->id;
        $offer->save();

        return back()->with('success', __('ui.attendance.offer_saved'));
    }

    public function destroy(WeekendWorkOffer $weekendOffer): RedirectResponse
    {
        Gate::authorize('attendance.edit');

        $weekendOffer->delete();

        return back()->with('success', __('ui.attendance.offer_deleted'));
    }
}
