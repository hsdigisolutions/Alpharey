/**
 * A single GPS fix for a punch — with every failure turned into a defined
 * outcome rather than a thrown error, because on a building site every one of
 * them happens: permission refused, no signal between two towers, an old
 * device with no sensor, a fix that never resolves.
 *
 * The client decision is that NONE of these block the punch. So this never
 * rejects: it always resolves to a shape the caller can send as-is, with
 * `denied: true` when there is no usable location. GPS is evidence, not a gate.
 */

const TIMEOUT_MS = 12000;

/**
 * @returns {Promise<{lat: number|null, lng: number|null, accuracy: number|null, denied: boolean}>}
 */
export function getLocation() {
    const denied = { lat: null, lng: null, accuracy: null, denied: true };

    if (!('geolocation' in navigator)) {
        return Promise.resolve(denied);
    }

    return new Promise((resolve) => {
        // A hard cap of our own: some devices leave getCurrentPosition hanging
        // forever, and a worker cannot be left staring at a spinner.
        const guard = setTimeout(() => resolve(denied), TIMEOUT_MS + 1000);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                clearTimeout(guard);
                resolve({
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    denied: false,
                });
            },
            () => {
                // Permission denied, position unavailable, or timeout — all one
                // outcome to the worker: punch anyway, flagged.
                clearTimeout(guard);
                resolve(denied);
            },
            { enableHighAccuracy: true, timeout: TIMEOUT_MS, maximumAge: 0 },
        );
    });
}
