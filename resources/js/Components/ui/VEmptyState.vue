<script setup>
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    icon: { type: String, default: 'file' },
    titleKey: { type: String, default: 'common.empty_title' },
    messageKey: { type: String, default: 'common.empty_message' },
});
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <!-- Branded emblem: coral-tinted disc with faint concentric rings. -->
        <span class="relative mb-4 flex h-16 w-16 items-center justify-center">
            <span class="absolute inset-0 rounded-full ring-1 ring-accent/15" aria-hidden="true" />
            <span class="absolute inset-[6px] rounded-full ring-1 ring-accent/25" aria-hidden="true" />
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-accent-soft text-accent">
                <AppIcon :name="icon" class="h-5 w-5" />
            </span>
        </span>
        <h3 class="text-[15px] font-semibold">
            <Bilingual :k="titleKey" class="items-center" />
        </h3>
        <p class="mt-1 max-w-xs text-sm text-muted">
            <Bilingual :k="messageKey" class="items-center" />
        </p>
        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </div>
</template>
