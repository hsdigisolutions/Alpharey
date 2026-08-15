<?php

use App\Support\Geo;

it('measures a known great-circle distance in metres', function (): void {
    // Same point → 0.
    expect(Geo::haversine(40.4168, -3.7038, 40.4168, -3.7038))->toBe(0.0);

    // 1° of latitude ≈ 111.19 km anywhere on Earth.
    expect(Geo::haversine(40.0, -3.0, 41.0, -3.0))->toEqualWithDelta(111194.0, 200.0);

    // A short site-scale hop (~250 m) lands in the right ballpark.
    expect(Geo::haversine(40.41680, -3.70380, 40.41905, -3.70380))->toEqualWithDelta(250.0, 15.0);
});

it('bands a distance against the radius and the off-site threshold', function (): void {
    // radius 500, off-site/alert threshold 2000
    expect(Geo::band(200, 500, 2000))->toBe(Geo::ON_SITE);
    expect(Geo::band(499, 500, 2000))->toBe(Geo::ON_SITE);
    expect(Geo::band(500, 500, 2000))->toBe(Geo::NEAR_SITE); // radius is exclusive lower bound of near
    expect(Geo::band(1500, 500, 2000))->toBe(Geo::NEAR_SITE);
    expect(Geo::band(1999, 500, 2000))->toBe(Geo::NEAR_SITE);
    expect(Geo::band(2000, 500, 2000))->toBe(Geo::OFF_SITE); // threshold is inclusive → off-site + alert
    expect(Geo::band(5000, 500, 2000))->toBe(Geo::OFF_SITE);
});

it('honours a per-project radius wider than the default', function (): void {
    // A large site with a 1 km radius: 800 m is still on-site.
    expect(Geo::band(800, 1000, 2000))->toBe(Geo::ON_SITE);
});
