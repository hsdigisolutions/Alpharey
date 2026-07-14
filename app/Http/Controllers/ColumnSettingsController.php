<?php

namespace App\Http\Controllers;

use App\Models\UserColumnSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Column visibility saved per user per table (Data Management
 * Standards §10). Purely a per-user preference — no permission gate
 * beyond authentication.
 */
class ColumnSettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'table_name' => ['required', 'string', 'max:60'],
            'visible_columns' => ['required', 'array', 'max:40'],
            'visible_columns.*' => ['string', 'max:60'],
        ]);

        UserColumnSetting::query()->updateOrCreate(
            ['user_id' => $request->user()?->id, 'table_name' => $validated['table_name']],
            ['visible_columns' => array_values($validated['visible_columns'])],
        );

        return back();
    }
}
