import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Autosave an Inertia useForm to localStorage so an accidental close / reload
 * never loses a half-typed create or edit (Change 1). ONE reusable composable,
 * keyed per form instance (`draft:company:new` / `draft:company:42`).
 *
 * Contract:
 *  - Nothing is written until a field actually differs from the pristine state
 *    captured at arm() time, so an untouched form never leaves a stray draft.
 *  - Sensitive fields (NIF/IBAN/bank/salary/wage on Employee & Client) are
 *    NEVER written to plaintext localStorage — pass them in `exclude`. File
 *    inputs are dropped automatically (they can't be serialised anyway).
 *  - Restore is automatic: arm() re-applies a saved draft into the form and
 *    flips `hasDraft`, which the caller surfaces as a dismissible banner.
 *
 * Lifecycle the caller drives:
 *  - arm()     — right after the form has been populated for the record being
 *                opened (create → blanks, edit → the row). Reads any saved
 *                draft and, when it differs, restores it + sets hasDraft.
 *  - clear()   — on a successful submit: the draft is done, drop it.
 *  - disarm()  — when the modal closes WITHOUT submitting: stop watching but
 *                KEEP the stored draft so reopening restores it.
 *  - discard() — the banner's "Discard draft" action: revert to pristine and
 *                delete the stored draft.
 *
 * @param {import('@inertiajs/vue3').InertiaForm} form
 * @param {{key: () => string, exclude?: string[], debounce?: number}} options
 */
export function useFormDraft(form, options) {
    const { key, exclude = [], debounce = 500 } = options;

    const hasDraft = ref(false);
    let pristine = null; // JSON string of the sanitized starting state
    let currentKey = null;
    let timer = null;
    let stop = null;

    function storageKey() {
        return `draft:${key()}`;
    }

    /** Field data minus excluded/PII fields and un-serialisable file inputs. */
    function sanitize(data) {
        const out = {};
        for (const k of Object.keys(data)) {
            if (exclude.includes(k)) continue;
            const v = data[k];
            if (v instanceof File || v instanceof Blob || v instanceof FileList) continue;
            out[k] = v;
        }

        return out;
    }

    function read(k) {
        try {
            const raw = localStorage.getItem(k);

            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function write(k, value) {
        try {
            localStorage.setItem(k, JSON.stringify(value));
        } catch {
            // Quota / private-mode — autosave is best-effort, never fatal.
        }
    }

    function remove(k) {
        try {
            localStorage.removeItem(k);
        } catch {
            // ignore
        }
    }

    function apply(data) {
        for (const k of Object.keys(data)) {
            if (k in form) form[k] = data[k];
        }
    }

    function stopWatching() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
        if (stop) {
            stop();
            stop = null;
        }
    }

    function save() {
        if (currentKey === null) return;
        const snapshot = JSON.stringify(sanitize(form.data()));
        if (snapshot === pristine) {
            remove(currentKey); // reverted by hand — nothing to keep
        } else {
            write(currentKey, JSON.parse(snapshot));
        }
    }

    function arm() {
        stopWatching();
        currentKey = storageKey();
        pristine = JSON.stringify(sanitize(form.data()));

        const saved = read(currentKey);
        if (saved && JSON.stringify(saved) !== pristine) {
            apply(saved);
            hasDraft.value = true;
        } else {
            hasDraft.value = false;
            if (saved) remove(currentKey); // stored draft identical to pristine — noise
        }

        // Inertia's useForm proxies each field reactively; watch a JSON
        // projection of data() so any change (nested included) triggers the
        // debounced save without depending on framework internals.
        stop = watch(() => JSON.stringify(form.data()), () => {
            if (timer) clearTimeout(timer);
            timer = setTimeout(save, debounce);
        });
    }

    function discard() {
        if (pristine !== null) apply(JSON.parse(pristine));
        if (currentKey !== null) remove(currentKey);
        hasDraft.value = false;
    }

    function clear() {
        if (currentKey !== null) remove(currentKey);
        hasDraft.value = false;
        stopWatching();
    }

    function disarm() {
        // Flush any pending debounced save so a fast close still persists.
        if (timer) {
            clearTimeout(timer);
            timer = null;
            save();
        }
        stopWatching();
        hasDraft.value = false;
    }

    onBeforeUnmount(stopWatching);

    return { hasDraft, arm, clear, disarm, discard };
}
