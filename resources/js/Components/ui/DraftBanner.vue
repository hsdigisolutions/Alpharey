<script setup>
/**
 * Dismissible "unsaved draft restored" banner (Change 1). Shown atop a form
 * modal when useFormDraft has auto-restored a saved draft; the discard action
 * reverts the form to its pristine state and deletes the stored draft.
 */
import AppIcon from '@/Components/AppIcon.vue';
import { t } from '@/translate';

defineProps({
    show: { type: Boolean, default: false },
});
const emit = defineEmits(['discard']);
</script>

<template>
    <div v-if="show"
        class="mb-4 flex items-center justify-between gap-3 rounded-md border border-status-info-soft bg-status-info-soft px-3 py-2 text-sm text-status-info">
        <span class="flex items-center gap-2">
            <AppIcon name="info" class="h-4 w-4 shrink-0" />
            {{ t('common.draft_restored') }}
        </span>
        <button type="button" class="shrink-0 font-medium underline hover:no-underline" @click="emit('discard')">
            {{ t('common.draft_discard') }}
        </button>
    </div>
</template>
