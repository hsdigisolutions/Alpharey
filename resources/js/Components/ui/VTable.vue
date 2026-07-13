<script setup>
/**
 * Presentational data table (D2). Bilingual sortable headers, optional
 * selection column, sticky header, horizontal scroll on mobile. Data
 * behavior (server-side sorting/filtering/pagination) is wired per module
 * from Phase 2 — this component only emits intent.
 */
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    /** [{ key, labelKey, sortable?, align? ('start'|'end'), width? }] */
    columns: { type: Array, required: true },
    sort: { type: Object, default: null }, // { key, dir: 'asc'|'desc' }
    selectable: { type: Boolean, default: false },
    allSelected: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

defineEmits(['sort', 'toggle-all']);
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-line bg-surface-raised shadow-card">
        <table class="w-full min-w-max text-sm">
            <thead>
                <tr class="border-b border-line bg-surface-sunken/60">
                    <th v-if="selectable" class="w-10 px-3 py-2.5">
                        <input type="checkbox" :checked="allSelected" class="h-4 w-4 rounded-sm accent-[var(--color-accent)]"
                            aria-label="Seleccionar todo / Select all" @change="$emit('toggle-all', $event.target.checked)" />
                    </th>
                    <th v-for="column in columns" :key="column.key" class="px-3 py-2.5 font-normal"
                        :class="[column.align === 'end' ? 'text-end' : 'text-start', column.width]">
                        <button v-if="column.sortable" type="button"
                            class="group inline-flex items-center gap-1 text-ink-soft hover:text-ink"
                            @click="$emit('sort', column.key)">
                            <Bilingual :k="column.labelKey" class="text-xs font-semibold" />
                            <AppIcon
                                :name="sort?.key === column.key && sort?.dir === 'desc' ? 'chevron-down' : 'chevron-up'"
                                class="h-3 w-3 transition-opacity"
                                :class="sort?.key === column.key ? 'text-accent opacity-100' : 'opacity-0 group-hover:opacity-60'" />
                        </button>
                        <Bilingual v-else :k="column.labelKey" class="text-xs font-semibold text-ink-soft" />
                    </th>
                </tr>
            </thead>
            <tbody v-if="!loading" class="divide-y divide-line">
                <slot />
            </tbody>
            <tbody v-else>
                <tr v-for="row in 5" :key="row" class="border-b border-line">
                    <td v-if="selectable" class="px-3 py-3"><div class="h-4 w-4 animate-pulse rounded-sm bg-surface-sunken" /></td>
                    <td v-for="column in columns" :key="column.key" class="px-3 py-3">
                        <div class="h-4 animate-pulse rounded-sm bg-surface-sunken" :style="{ width: `${45 + ((row * 17) % 40)}%` }" />
                    </td>
                </tr>
            </tbody>
        </table>
        <slot name="empty" />
    </div>
</template>
