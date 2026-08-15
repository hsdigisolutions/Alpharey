/**
 * MediaRecorder-produced WebM/Ogg blobs carry no duration in their header, so a
 * native <audio controls> reports `Infinity`/`NaN` and shows "0:00" on the
 * scrubber until the clip is played all the way through. Forcing a seek to the
 * end makes the browser index the stream and report the real duration.
 *
 * Wire it to the element's `loadedmetadata` event:
 *   <audio ... @loadedmetadata="fixAudioDuration($event.target)" />
 */
export function fixAudioDuration(el) {
    if (!el || (el.duration !== Infinity && !Number.isNaN(el.duration))) return;

    const onUpdate = () => {
        el.removeEventListener('timeupdate', onUpdate);
        // Back to the start once the real duration has been resolved.
        if (el.duration !== Infinity && !Number.isNaN(el.duration)) el.currentTime = 0;
    };
    el.addEventListener('timeupdate', onUpdate);
    // A huge target the browser clamps to the true end, triggering `timeupdate`.
    el.currentTime = 1e101;
}
