<?php

it('sends the core security headers on every response', function (): void {
    $response = $this->get('/login');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->has('Permissions-Policy'))->toBeTrue();
});

it('enforces a restrictive content security policy outside local dev', function (): void {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'");
});

it('sends HSTS on secure requests only', function (): void {
    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    expect($this->get('http://localhost/login')->headers->has('Strict-Transport-Security'))
        ->toBeFalse();
});
