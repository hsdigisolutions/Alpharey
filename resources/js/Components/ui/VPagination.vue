<script setup>
/**
 * Table pagination with the mandated 25/50/100 page sizes
 * (REQUIREMENTS.md §10).
 */
import AppIcon from '@/Components/AppIcon.vue';
import VSelect from '@/Components/ui/VSelect.vue';

defineProps({
    page: { type: Number, default: 1 },
    pages: { type: Number, default: 1 },
    perPage: { type: Number, default: 25 },
    total: { type: Number, default: 0 },
});

defineEmits(['update:page', 'update:perPage']);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 pt-3 text-sm text-ink-soft">
        <label class="flex items-center gap-2">
            <Bilingual k="table.per_page" inline class="text-xs" />
            <VSelect :model-value="perPage"
                @update:model-value="(v) => $emit('update:perPage', Number(v))">
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
            </VSelect>
        </label>

        <p class="tabular-nums text-xs text-muted">{{ total }} <Bilingual k="table.records" inline class="text-xs" /></p>

        <div class="flex items-center gap-1">
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-sunken disabled:opacity-40"
                :disabled="page <= 1" aria-label="Anterior / Previous" @click="$emit('update:page', page - 1)">
                <AppIcon name="chevron-left" class="h-4 w-4" />
            </button>
            <span class="tabular-nums px-2 text-xs">{{ page }} / {{ Math.max(pages, 1) }}</span>
            <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-sunken disabled:opacity-40"
                :disabled="page >= pages" aria-label="Siguiente / Next" @click="$emit('update:page', page + 1)">
                <AppIcon name="chevron-right" class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
