<script setup>
/**
 * VCombobox — searchable dropdown. Unlike VSelect (which reads a native
 * <select> slot), this takes an options array so it can be filtered and
 * can render section-header items that are not selectable.
 *
 * options: Array<{
 *   value: string | number,
 *   label: string,
 *   secondary?: string,   // shown dimmed below the label
 *   isHeader?: boolean,   // non-selectable section divider
 * }>
 */
import { computed, nextTick, onMounted, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    options: { type: Array, required: true },
    placeholder: { type: String, default: '—' },
    searchPlaceholder: { type: String, default: '...' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const search = ref('');
const searchInput = ref(null);
const container = ref(null);

const selected = computed(() =>
    props.options.find((o) => !o.isHeader && String(o.value) === String(props.modelValue)) ?? null,
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) => {
        if (o.isHeader) return false; // headers never match text search
        const l = (o.label ?? '').toLowerCase();
        const s = (o.secondary ?? '').toLowerCase();
        return l.includes(q) || s.includes(q);
    });
});

function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
    if (open.value) {
        search.value = '';
        nextTick(() => searchInput.value?.focus());
    }
}

function select(option) {
    if (option.isHeader) return;
    emit('update:modelValue', option.value);
    open.value = false;
    search.value = '';
}

function clear() {
    emit('update:modelValue', null);
    open.value = false;
}

// Close on outside click.
function onClickOutside(e) {
    if (container.value && !container.value.contains(e.target)) {
        open.value = false;
    }
}
onMounted(() => document.addEventListener('mousedown', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside));

// Keyboard support: Escape closes.
function onKeydown(e) {
    if (e.key === 'Escape') { open.value = false; }
}
</script>

<template>
    <div ref="container" class="relative" @keydown="onKeydown">
        <!-- Trigger button -->
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md border border-line-strong bg-surface-sunken px-3 py-2 text-sm text-ink transition-colors hover:border-accent focus:outline-none focus:ring-2 focus:ring-accent/30"
            :class="{ 'opacity-50 pointer-events-none': disabled }"
            @click="toggle"
        >
            <span v-if="selected" class="truncate">
                {{ selected.label }}
                <span v-if="selected.secondary" class="ms-1 text-muted">— {{ selected.secondary }}</span>
            </span>
            <span v-else class="text-muted">{{ placeholder }}</span>
            <svg class="h-4 w-4 shrink-0 text-muted transition-transform" :class="{ 'rotate-180': open }"
                viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </button>

        <!-- Dropdown panel -->
        <div v-if="open"
            class="absolute z-50 mt-1 w-full rounded-lg border border-line bg-surface-raised shadow-raised">
            <!-- Search input -->
            <div class="border-b border-line px-2 py-1.5">
                <input
                    ref="searchInput"
                    v-model="search"
                    type="text"
                    :placeholder="searchPlaceholder"
                    class="w-full rounded bg-transparent px-1 py-0.5 text-sm text-ink placeholder:text-muted focus:outline-none"
                />
            </div>

            <!-- Options list -->
            <ul class="max-h-56 overflow-y-auto py-1">
                <!-- Clear option (when a value is set) -->
                <li v-if="modelValue !== null && modelValue !== '' && !search"
                    class="cursor-pointer px-3 py-1.5 text-sm text-muted hover:bg-surface-hover"
                    @mousedown.prevent="clear">
                    —
                </li>

                <template v-if="filtered.length">
                    <li v-for="(opt, i) in filtered" :key="i"
                        :class="[
                            opt.isHeader
                                ? 'px-3 pt-2 pb-0.5 text-[11px] font-semibold uppercase tracking-wider text-muted select-none'
                                : 'cursor-pointer px-3 py-1.5 text-sm hover:bg-surface-hover',
                            !opt.isHeader && String(opt.value) === String(modelValue)
                                ? 'bg-accent-soft font-medium text-ink'
                                : (!opt.isHeader ? 'text-ink' : ''),
                        ]"
                        @mousedown.prevent="select(opt)">
                        <template v-if="!opt.isHeader">
                            <span class="block truncate">{{ opt.label }}</span>
                            <span v-if="opt.secondary" class="block truncate text-xs text-muted">{{ opt.secondary }}</span>
                        </template>
                        <template v-else>{{ opt.label }}</template>
                    </li>
                </template>
                <li v-else class="px-3 py-2 text-sm text-muted">—</li>
            </ul>
        </div>
    </div>
</template>
