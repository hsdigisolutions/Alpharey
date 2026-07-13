<script setup>
/**
 * Standard list-screen toolbar: live search on the left, filters in the
 * middle slot, exports + primary action on the right (Data Management
 * Standards, REQUIREMENTS.md §10).
 */
import VButton from '@/Components/ui/VButton.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';

defineProps({
    search: { type: String, default: '' },
    exportable: { type: Boolean, default: true },
});

defineEmits(['update:search', 'export']);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 pb-3">
        <div class="w-full sm:w-64">
            <VSearchInput :model-value="search" @update:model-value="$emit('update:search', $event)" />
        </div>

        <slot name="filters" />

        <div class="ms-auto flex items-center gap-2">
            <template v-if="exportable">
                <VButton variant="secondary" size="sm" icon="export" @click="$emit('export', 'excel')">
                    Excel
                </VButton>
                <VButton variant="secondary" size="sm" icon="export" @click="$emit('export', 'pdf')">
                    PDF
                </VButton>
            </template>
            <slot name="actions" />
        </div>
    </div>
</template>
