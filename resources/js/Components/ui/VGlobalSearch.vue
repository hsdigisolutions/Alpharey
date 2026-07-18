<script setup>
/**
 * Global search (header bar, Screen — Phase 8). Debounce-fetches the JSON
 * /search endpoint and shows grouped results; Enter/click navigates via
 * Inertia. All permission + tenancy scoping is server-side — this only
 * renders what the endpoint chose to return.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import { t } from '@/translate';

const term = ref('');
const groups = ref([]);
const open = ref(false);
const loading = ref(false);
const activeIndex = ref(-1);
const root = ref(null);
let debounce = null;
let controller = null;

// Flat list of results for keyboard navigation across groups.
const flat = computed(() => groups.value.flatMap((g) => g.results.map((r) => ({ ...r, module: g.module }))));

function moduleLabel(module) {
    return t(`nav.${module}`);
}

function onInput() {
    open.value = true;
    activeIndex.value = -1;

    if (debounce) clearTimeout(debounce);

    if (term.value.trim().length < 2) {
        groups.value = [];
        loading.value = false;

        return;
    }

    loading.value = true;
    debounce = setTimeout(fetchResults, 200);
}

async function fetchResults() {
    // Abort an in-flight request so results can't arrive out of order.
    if (controller) controller.abort();
    controller = new AbortController();

    try {
        const res = await fetch(`/search?q=${encodeURIComponent(term.value.trim())}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const data = await res.json();
        groups.value = data.groups ?? [];
    } catch (e) {
        if (e.name !== 'AbortError') groups.value = [];
    } finally {
        loading.value = false;
    }
}

function choose(result) {
    open.value = false;
    term.value = '';
    groups.value = [];
    router.visit(result.href);
}

function onKeydown(e) {
    if (!open.value || flat.value.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % flat.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + flat.value.length) % flat.value.length;
    } else if (e.key === 'Enter' && activeIndex.value >= 0) {
        e.preventDefault();
        choose(flat.value[activeIndex.value]);
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
});
</script>

<template>
    <div ref="root" class="relative max-w-md flex-1">
        <AppIcon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
        <input
            v-model="term"
            type="search"
            :placeholder="`${$t('common.search_everything')}…`"
            class="w-full rounded-md border border-line bg-surface-sunken py-1.5 ps-9 pe-3 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none"
            @input="onInput"
            @focus="open = term.length >= 2"
            @keydown="onKeydown"
        />

        <!-- Results dropdown -->
        <div v-if="open && term.trim().length >= 2"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-96 overflow-y-auto rounded-lg border border-line bg-surface-raised shadow-raised">
            <div v-if="loading" class="px-3 py-4 text-center text-sm text-muted">…</div>
            <div v-else-if="groups.length === 0" class="px-3 py-4 text-center text-sm text-muted">
                {{ $t('common.search_no_results') }}
            </div>
            <template v-else>
                <div v-for="group in groups" :key="group.module">
                    <p class="bg-surface-sunken px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                        {{ moduleLabel(group.module) }}
                    </p>
                    <button
                        v-for="result in group.results"
                        :key="`${group.module}-${result.id}`"
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm hover:bg-surface-hover"
                        :class="{ 'bg-accent-soft': flat[activeIndex]?.module === group.module && flat[activeIndex]?.id === result.id }"
                        @click="choose(result)"
                    >
                        <span class="truncate text-ink">{{ result.label }}</span>
                        <span v-if="result.sub" class="tabular-nums shrink-0 text-xs text-muted">{{ result.sub }}</span>
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
