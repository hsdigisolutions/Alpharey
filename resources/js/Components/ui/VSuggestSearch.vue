<script setup>
/**
 * A list-page search box with a live SUGGESTIONS dropdown (Change 2), scoped to
 * ONE module via the /search endpoint's `&module=` filter. Reuses the global
 * bar's fetch/keyboard pattern; all permission + tenancy scoping is server-side.
 *
 * It IS the list's search field (v-model = the filter term). On picking a
 * suggestion:
 *  - jump=true  → navigate to that record's detail page (Employees, Clients,
 *                 Projects, Vendors, Vehicles, Subcontractors).
 *  - jump=false → fill the term with the picked label and emit `select`, so the
 *                 page filters its own table (Invoices, Expenses, Proposals,
 *                 Inventory — entities with no standalone detail page).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { tPair } from '@/translate';

const props = defineProps({
    modelValue: { type: String, default: '' },
    module: { type: String, required: true },
    jump: { type: Boolean, default: false },
    placeholder: { type: String, default: null },
    minChars: { type: Number, default: 2 },
});
const emit = defineEmits(['update:modelValue', 'select']);

const results = ref([]);
const open = ref(false);
const loading = ref(false);
const activeIndex = ref(-1);
const root = ref(null);
let debounce = null;
let controller = null;

const resolvedPlaceholder = computed(() => props.placeholder ?? tPair('common.search'));

function onInput(e) {
    const value = e.target.value;
    emit('update:modelValue', value);
    open.value = true;
    activeIndex.value = -1;

    if (debounce) clearTimeout(debounce);

    if (value.trim().length < props.minChars) {
        results.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;
    debounce = setTimeout(() => fetchResults(value.trim()), 250);
}

async function fetchResults(term) {
    if (controller) controller.abort();
    controller = new AbortController();

    try {
        const q = new URLSearchParams({ q: term, module: props.module });
        const res = await fetch(`/search?${q.toString()}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const data = await res.json();
        results.value = data.groups?.[0]?.results ?? [];
    } catch (e) {
        if (e.name !== 'AbortError') results.value = [];
    } finally {
        loading.value = false;
    }
}

function choose(result) {
    open.value = false;
    results.value = [];

    if (props.jump && result.href) {
        router.visit(result.href);

        return;
    }

    // Fill-and-filter: the label becomes the term, the page filters its table.
    emit('update:modelValue', result.label);
    emit('select', result.label);
}

function clear() {
    emit('update:modelValue', '');
    results.value = [];
    open.value = false;
}

function onKeydown(e) {
    if (!open.value || results.value.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % results.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + results.value.length) % results.value.length;
    } else if (e.key === 'Enter' && activeIndex.value >= 0) {
        e.preventDefault();
        choose(results.value[activeIndex.value]);
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}

function onClickOutside(e) {
    if (root.value && !root.value.contains(e.target)) open.value = false;
}

onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    if (debounce) clearTimeout(debounce);
    if (controller) controller.abort();
});
</script>

<template>
    <div ref="root" class="relative">
        <AppIcon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
        <input
            type="search"
            :value="modelValue"
            :placeholder="resolvedPlaceholder"
            class="w-full rounded-md border border-line bg-surface-raised py-2 ps-9 pe-8 text-sm text-ink placeholder:text-faint transition-colors duration-150 hover:border-line-strong focus:border-accent focus:outline-none"
            autocomplete="off"
            @input="onInput"
            @focus="open = modelValue.trim().length >= minChars"
            @keydown="onKeydown"
        />
        <button v-if="modelValue" type="button"
            class="absolute end-2 top-1/2 -translate-y-1/2 rounded-sm p-0.5 text-muted hover:text-ink"
            :aria-label="$tPair('common.clear')"
            @click="clear">
            <AppIcon name="x" class="h-3.5 w-3.5" />
        </button>

        <div v-if="open && modelValue.trim().length >= minChars"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-80 overflow-y-auto rounded-lg border border-line bg-surface-raised shadow-raised">
            <div v-if="loading" class="px-3 py-3 text-center text-sm text-muted">…</div>
            <div v-else-if="results.length === 0" class="px-3 py-3 text-center text-sm text-muted">
                {{ $t('common.search_no_results') }}
            </div>
            <button
                v-for="(result, i) in results"
                v-else
                :key="result.id"
                type="button"
                class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-surface-hover"
                :class="{ 'bg-accent-soft': i === activeIndex }"
                @click="choose(result)"
            >
                <span class="truncate text-ink">{{ result.label }}</span>
                <span v-if="result.sub" class="shrink-0 tabular-nums text-xs text-muted">{{ result.sub }}</span>
            </button>
        </div>
    </div>
</template>
