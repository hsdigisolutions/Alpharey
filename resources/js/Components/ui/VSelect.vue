<script setup>
import { computed, nextTick, onMounted, onUnmounted, onUpdated, ref, watch } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    id: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const open = ref(false);
const nativeRef = ref(null);
const triggerRef = ref(null);
const panelRef = ref(null);
const options = ref([]);

/* Read options from the hidden native <select> so v-for / $t() / dynamic
   values all just work — no VNode parsing needed. */
function syncOptions() {
    if (!nativeRef.value) return;
    options.value = Array.from(nativeRef.value.options).map(o => ({
        value: o.value,
        label: o.text,
        disabled: o.disabled,
    }));
}

const selectedLabel = computed(() => {
    const val = String(props.modelValue ?? '');
    return options.value.find(o => String(o.value) === val)?.label ?? '';
});

function pick(value) {
    if (props.disabled) return;
    emit('update:modelValue', value);
    open.value = false;
    triggerRef.value?.focus();
}

function onClickOutside(e) {
    if (triggerRef.value?.contains(e.target)) return;
    if (panelRef.value?.contains(e.target)) return;
    open.value = false;
}

/* Scroll the active option into view each time the panel opens. */
watch(open, async (v) => {
    if (!v) return;
    await nextTick();
    panelRef.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
});

onMounted(() => {
    syncOptions();
    document.addEventListener('mousedown', onClickOutside);
});
onUpdated(syncOptions);
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside));
</script>

<template>
    <div class="relative">
        <!-- Hidden native select keeps slot/option API and form semantics unchanged. -->
        <select ref="nativeRef" :id="id" :value="modelValue" :disabled="disabled"
            class="sr-only" tabindex="-1" aria-hidden="true"
            @change="emit('update:modelValue', $event.target.value)">
            <slot />
        </select>

        <!-- Styled trigger -->
        <button ref="triggerRef" type="button" :disabled="disabled"
            class="flex w-full items-center justify-between gap-2 rounded-md border bg-surface-sunken px-3 py-2 text-sm transition-colors duration-150 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:cursor-not-allowed disabled:opacity-60"
            :class="invalid
                ? 'border-status-danger'
                : open
                    ? 'border-accent ring-2 ring-accent/20'
                    : 'border-line-strong hover:border-accent/50 cursor-pointer'"
            :aria-haspopup="'listbox'"
            :aria-expanded="open"
            @click="open = !open"
            @keydown.esc="open = false"
            @keydown.space.prevent="open = !open"
            @keydown.enter.prevent="open = !open">
            <span class="truncate" :class="selectedLabel ? 'text-ink' : 'text-muted'">
                {{ selectedLabel || '—' }}
            </span>
            <AppIcon name="chevron-down"
                class="h-4 w-4 shrink-0 text-muted transition-transform duration-150"
                :class="open ? '-rotate-180' : ''" />
        </button>

        <!-- Dropdown panel -->
        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="scale-95 opacity-0"
            enter-to-class="scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="scale-100 opacity-100"
            leave-to-class="scale-95 opacity-0">
            <div v-if="open" ref="panelRef" role="listbox"
                class="vselect-panel absolute z-50 mt-1 max-h-60 overflow-y-auto rounded-md border border-line bg-surface-raised shadow-raised"
                style="width: max-content; min-width: 100%;">
                <button v-for="opt in options" :key="opt.value"
                    type="button" role="option"
                    :disabled="opt.disabled"
                    :aria-selected="String(opt.value) === String(modelValue ?? '')"
                    class="flex w-full items-center gap-2 whitespace-nowrap px-3 py-2 text-left text-sm transition-colors duration-75"
                    :class="String(opt.value) === String(modelValue ?? '')
                        ? 'bg-accent text-on-accent'
                        : opt.disabled
                            ? 'cursor-not-allowed text-muted opacity-50'
                            : 'cursor-pointer text-ink hover:bg-surface-hover'"
                    @click="pick(opt.value)">
                    <span class="flex-1">{{ opt.label }}</span>
                    <AppIcon v-if="String(opt.value) === String(modelValue ?? '')"
                        name="check" class="h-3.5 w-3.5 shrink-0 opacity-75" />
                </button>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.vselect-panel::-webkit-scrollbar {
    width: 3px;
}
.vselect-panel::-webkit-scrollbar-track {
    background: transparent;
}
.vselect-panel::-webkit-scrollbar-thumb {
    background: rgba(212, 149, 106, 0.4);
    border-radius: 2px;
}
.vselect-panel::-webkit-scrollbar-thumb:hover {
    background: rgba(212, 149, 106, 0.7);
}
</style>
