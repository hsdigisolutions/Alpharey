<script setup>
defineProps({
    titleKey: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section class="rounded-lg border border-line bg-surface-raised shadow-card">
        <header v-if="titleKey || $slots.header" class="flex items-center justify-between border-b border-line px-4 py-3">
            <h2 v-if="titleKey" class="flex items-center gap-2 text-[15px] font-semibold">
                <span class="h-4 w-1 shrink-0 rounded-full bg-accent" aria-hidden="true" />
                <Bilingual :k="titleKey" />
            </h2>
            <slot name="header" />
        </header>
        <div :class="padded ? 'p-4' : ''">
            <slot />
        </div>
    </section>
</template>
