import { usePage } from '@inertiajs/vue3';

/**
 * The translation helpers behind the bilingual system.
 *
 * `<Bilingual k="…"/>` covers labels that render as two lines. These cover the
 * slots where markup is impossible and a plain string is required: the browser
 * tab title, aria-labels, placeholders, <option> text.
 *
 * Both read the CURRENT primary locale, so they flip the moment the user
 * toggles ES/EN — the thing hardcoded "Buscar / Search" literals never did.
 */
function lookup(dict, key) {
    return key.split('.').reduce((node, part) => (node ?? {})[part], dict ?? {});
}

/**
 * Resolve a ui.* key in the user's primary language.
 * Falls back to the key itself so a missing string is obvious, never blank.
 */
export function t(key) {
    const page = usePage();
    const primary = page.props.locale?.primary ?? 'es';

    return lookup(page.props.lang?.[primary], key) ?? key;
}

/**
 * Resolves a ui.* key in the user's primary language only.
 * Kept as a separate export so callers don't need updating.
 */
export function tPair(key) {
    return t(key);
}
