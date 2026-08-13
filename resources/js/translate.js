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
 *
 * Optional `params` interpolate Laravel-style `:name` placeholders, e.g.
 * `t('x.split', { qty: 25, unit: 'm²', n: 2 })`.
 */
export function t(key, params) {
    const page = usePage();
    const primary = page.props.locale?.primary ?? 'es';

    let str = lookup(page.props.lang?.[primary], key) ?? key;

    if (params && typeof str === 'string') {
        for (const [name, value] of Object.entries(params)) {
            str = str.replaceAll(`:${name}`, String(value));
        }
    }

    return str;
}

/**
 * Resolves a ui.* key in the user's primary language only.
 * Kept as a separate export so callers don't need updating.
 */
export function tPair(key) {
    return t(key);
}
