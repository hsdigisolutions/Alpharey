<script setup>
defineProps({
    titleKey: { type: String, default: null },
    padded: { type: Boolean, default: true },
});
</script>

<template>
    <section class="rounded-lg border border-line bg-surface-raised shadow-card">
        <header v-if="titleKey || $slots.header" class="flex items-center justify-between border-b border-line px-4 py-3">
            <h2 v-if="titleKey" class="text-[15px] font-semibold">
                <Bilingual :k="titleKey" />
            </h2>
            <slot name="header" />
        </header>
        <div :class="padded ? 'p-4' : ''">
            <slot />
        </div>
    </section>
</template>
