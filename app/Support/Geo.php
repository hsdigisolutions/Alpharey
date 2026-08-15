<?php

namespace App\Support;

/**
 * Geospatial helpers for worker location verification. One Haversine so the
 * check-in↔check-out mismatch and the check-in↔project distance share the exact
 * same great-circle maths.
 */
class Geo
{
    public const ON_SITE = 'on_site';

    public const NEAR_SITE = 'near_site';

    public const OFF_SITE = 'off_site';

    /**
     * Great-circle distance between two lat/lng points, in METRES. Accurate to
     * well under GPS's own noise across the 0-10 km construction range.
     */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0; // Earth radius, metres
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2.0 * $r * asin(sqrt($a));
    }

    /**
     * Classify a distance (metres) into the traffic-light band:
     *   on_site   — within the project's geofence radius
     *   near_site — beyond the radius but inside the off-site threshold
     *   off_site  — at or beyond the off-site threshold (the alert boundary)
     *
     * The off-site threshold and the alert fire together (client decision):
     * the red badge and the notification always agree.
     */
    public static function band(float $distance, int $radius, int $offSiteThreshold): string
    {
        if ($distance < $radius) {
            return self::ON_SITE;
        }

        if ($distance < $offSiteThreshold) {
            return self::NEAR_SITE;
        }

        return self::OFF_SITE;
    }
}
