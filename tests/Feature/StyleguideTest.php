<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the design-system styleguide outside production', function (): void {
    $this->get('/styleguide')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Styleguide')
            ->has('vatOptions', 5)
            ->where('vatOptions.0.value', null)
            ->where('vatOptions.0.label_es', 'No aplica')
            ->where('vatOptions.1.percent', 21)
            ->where('lang.es.styleguide.vat', 'Desplegable de IVA'));
});
