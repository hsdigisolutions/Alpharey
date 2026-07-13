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
        <span class="rounded-lg bg-surface-sunken p-3 text-muted">
            <AppIcon :name="icon" class="h-6 w-6" />
        </span>
        <h3 class="mt-3 text-[15px] font-semibold">
            <Bilingual :k="titleKey" class="items-center" />
        </h3>
        <p class="mt-1 max-w-xs text-sm text-muted">
            <Bilingual :k="messageKey" class="items-center" />
        </p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
