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
    <!-- Secondary line inherits currentColor at reduced opacity so the pair
         stays legible on any background: cream page, coral button, dark
         sidebar (design-skill rule: never colors that work in one mode). -->
    <span v-if="inline" class="whitespace-nowrap">
        <span>{{ primary }}</span>
        <span class="ms-1.5 text-[0.8em] opacity-60">{{ secondary }}</span>
    </span>
    <span v-else class="flex flex-col leading-tight">
        <span>{{ primary }}</span>
        <span class="text-[0.72em] opacity-60">{{ secondary }}</span>
    </span>
</template>
