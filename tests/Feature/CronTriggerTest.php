<?php

use Illuminate\Support\Facades\Artisan;

it('rejects a request with no token', function (): void {
    config(['app.cron_token' => 'secret-token-value']);

    $this->get('/cron/run')->assertForbidden()->assertSee('Unauthorized');
});

it('rejects a request with the wrong token', function (): void {
    config(['app.cron_token' => 'secret-token-value']);

    $this->get('/cron/run?token=nope')->assertForbidden();
});

it('denies everything when no token is configured', function (): void {
    config(['app.cron_token' => '']);

    // Even an empty token must not open the endpoint.
    $this->get('/cron/run?token=')->assertForbidden();
    $this->get('/cron/run?token=anything')->assertForbidden();
});

it('runs the scheduler with the correct token', function (): void {
    config(['app.cron_token' => 'secret-token-value']);
    Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);

    $this->get('/cron/run?token=secret-token-value')
        ->assertOk()
        ->assertSee('OK');
});
