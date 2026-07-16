<script setup>
/**
 * Standard page header pattern (D3): bilingual title on the left, action
 * buttons on the right. Sits directly under the app header inside <main>.
 */
defineProps({
    k: { type: String, required: true },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h1 class="min-w-0 text-2xl font-semibold tracking-tight">
            <Bilingual :k="k" />
        </h1>
        <div v-if="$slots.default" class="flex flex-wrap items-center justify-end gap-2">
            <slot />
        </div>
    </div>
</template>
