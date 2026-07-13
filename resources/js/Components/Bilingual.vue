<script setup>
/**
 * The bilingual label — the non-negotiable convention (REQUIREMENTS.md §9).
 * Renders the primary-locale text with the secondary language underneath,
 * smaller and muted. Both dictionaries are shared on every page by
 * HandleInertiaRequests, keyed under props.lang.es / props.lang.en.
 *
 * Usage: <Bilingual k="nav.dashboard" />
 *        <Bilingual k="auth.login" inline />   (secondary follows on one line)
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    k: { type: String, required: true },
    inline: { type: Boolean, default: false },
});

const page = usePage();

function lookup(locale, key) {
    const dict = page.props.lang?.[locale] ?? {};
    return key.split('.').reduce((node, part) => (node ?? {})[part], dict) ?? key;
}

const primary = computed(() => lookup(page.props.locale.primary, props.k));
const secondary = computed(() => lookup(page.props.locale.secondary, props.k));
</script>

<template>
    <span v-if="inline" class="whitespace-nowrap">
        <span>{{ primary }}</span>
        <span class="ms-1.5 text-[0.8em] text-muted">{{ secondary }}</span>
    </span>
    <span v-else class="flex flex-col leading-tight">
        <span>{{ primary }}</span>
        <span class="text-[0.72em] text-muted">{{ secondary }}</span>
    </span>
</template>
