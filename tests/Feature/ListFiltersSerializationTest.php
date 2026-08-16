<?php

use App\Models\Company;
use App\Models\User;

/**
 * Guards the "sort param becomes a native function" class of bug: when a list
 * page has NO active filters, `$request->only([...])` is an empty PHP array,
 * which Inertia serialises as a JSON array `[]`. On the JS side `[].sort` is
 * `Array.prototype.sort` (a truthy native function), so `props.filters.sort ??
 * default` yields the function and pollutes the sort query param. Casting the
 * controller's `filters` to (object) makes the empty case serialise as `{}`,
 * so `props.filters.sort` is `undefined` and the default applies.
 *
 * The Inertia page payload keeps literal quotes, so an object filters block
 * reads `"filters":{}` and the buggy array form would read `"filters":[]`.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create();
});

it('serialises empty list filters as an object, never an array', function (string $url): void {
    $this->actingAs($this->admin)->get($url)
        ->assertOk()
        ->assertSee('"filters":{}', false)          // the object (fixed) form is present
        ->assertDontSee('"filters":[]', false);     // the buggy array form is absent
})->with([
    'employees' => ['/employees'],
    'clients' => ['/clients'],
    'projects' => ['/projects'],
    'invoices' => ['/invoices'],
    'vendors' => ['/vendors'],
    'vehicles' => ['/vehicles'],
    'expenses' => ['/expenses'],
    'inventory' => ['/inventory'],
]);
