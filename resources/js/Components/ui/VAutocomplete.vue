<script setup>
/**
 * VAutocomplete — a free-text input that suggests previously-used values.
 *
 * Unlike VCombobox (which forces a choice from a fixed option list), this is a
 * plain text field: `v-model` is the raw string and stays fully editable, so it
 * saves as ANY value. After 2+ characters it debounces a fetch to a JSON
 * endpoint (`{ suggestions: string[] }`) and shows them below the field.
 * Clicking or Enter fills the field; the admin can keep typing to edit it.
 *
 * Keyboard: ↑/↓ move the highlight, Enter selects, Escape closes.
 * No suggestions → no dropdown (never a "no results" message).
 */
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    endpoint: { type: String, default: '/autocomplete/descriptions' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    minChars: { type: Number, default: 2 },
    debounceMs: { type: Number, default: 300 },
});
const emit = defineEmits(['update:modelValue']);

const suggestions = ref([]);
const open = ref(false);
const loading = ref(false);
const activeIndex = ref(-1);
const container = ref(null);

let debounceTimer = null;
let requestToken = 0;

function onInput(event) {
    const value = event.target.value;
    emit('update:modelValue', value);
    schedule(value);
}

function schedule(value) {
    clearTimeout(debounceTimer);
    const q = (value ?? '').trim();
    if (q.length < props.minChars) {
        suggestions.value = [];
        open.value = false;
        loading.value = false;
        return;
    }
    debounceTimer = setTimeout(() => fetchSuggestions(q), props.debounceMs);
}

async function fetchSuggestions(q) {
    const token = ++requestToken;
    loading.value = true;
    try {
        const res = await fetch(`${props.endpoint}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (token !== requestToken) return; // a newer keystroke won — drop this
        suggestions.value = Array.isArray(data.suggestions) ? data.suggestions : [];
        activeIndex.value = -1;
        open.value = suggestions.value.length > 0;
    } catch {
        if (token === requestToken) { suggestions.value = []; open.value = false; }
    } finally {
        if (token === requestToken) loading.value = false;
    }
}

function select(value) {
    emit('update:modelValue', value);
    open.value = false;
    suggestions.value = [];
    activeIndex.value = -1;
}

function onKeydown(event) {
    if (!open.value || suggestions.value.length === 0) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % suggestions.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = activeIndex.value <= 0 ? suggestions.value.length - 1 : activeIndex.value - 1;
    } else if (event.key === 'Enter') {
        // Select the highlighted row, or the first suggestion if none highlighted.
        event.preventDefault();
        select(suggestions.value[activeIndex.value >= 0 ? activeIndex.value : 0]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onClickOutside(event) {
    if (container.value && !container.value.contains(event.target)) {
        open.value = false;
    }
}
watch(open, (isOpen) => {
    if (isOpen) document.addEventListener('mousedown', onClickOutside);
    else document.removeEventListener('mousedown', onClickOutside);
});

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    document.removeEventListener('mousedown', onClickOutside);
});
</script>

<template>
    <div ref="container" class="relative">
        <input
            :value="modelValue" :placeholder="placeholder" :disabled="disabled"
            type="text" autocomplete="off"
            class="w-full rounded-md border bg-surface-raised px-3 py-2 text-sm text-ink placeholder:text-faint transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
            :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
            @input="onInput"
            @keydown="onKeydown"
            @focus="suggestions.length && (open = true)" />

        <!-- Loading spinner -->
        <span v-if="loading" class="absolute end-2.5 top-1/2 -translate-y-1/2">
            <span class="block h-4 w-4 animate-spin rounded-full border-2 border-line border-t-accent"></span>
        </span>

        <!-- Suggestions dropdown -->
        <ul v-if="open && suggestions.length"
            class="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-line bg-surface-raised py-1 shadow-raised">
            <li v-for="(s, i) in suggestions" :key="i"
                class="cursor-pointer truncate px-3 py-1.5 text-sm"
                :class="i === activeIndex ? 'bg-accent-soft font-medium text-ink' : 'text-ink hover:bg-surface-hover'"
                @mousedown.prevent="select(s)"
                @mouseenter="activeIndex = i">
                {{ s }}
            </li>
        </ul>
    </div>
</template>
