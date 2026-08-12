<script setup>
/**
 * The VAT (IVA) dropdown — DECISIONS.md VAT policy. Options come from
 * App\Enums\VatRate::options() passed as a page prop; blank ("No aplica")
 * is always the default. Each option renders bilingually on two lines.
 */
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import VDropdown from '@/Components/ui/VDropdown.vue';

const props = defineProps({
    modelValue: { type: [String, null], default: null }, // VatRate value or null
    // The typed percentage when the "Custom" rate is selected.
    customPercent: { type: [Number, String, null], default: null },
    /** @type {Array<{value: string|null, percent: number|null, label_es: string, label_en: string}>} */
    options: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'update:customPercent']);

const selected = computed(
    () => props.options.find((option) => option.value === props.modelValue) ?? props.options[0],
);

const isCustom = computed(() => props.modelValue === 'custom');
</script>

<template>
  <div class="space-y-2">
    <VDropdown align="start" width="w-full min-w-56">
        <template #trigger="{ toggle }">
            <button type="button" :disabled="disabled"
                class="flex w-full items-center justify-between rounded-md border bg-surface-raised px-3 py-2 text-start transition-colors duration-150 disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
                :class="invalid ? 'border-status-danger' : 'border-line hover:border-line-strong'"
                @click="toggle">
                <span class="flex flex-col leading-tight">
                    <span class="text-sm" :class="selected.value === null ? 'text-muted' : 'text-ink'">
                        {{ selected.label_es }}
                    </span>
                    <span class="text-[11px] text-muted">{{ selected.label_en }}</span>
                </span>
                <AppIcon name="chevron-down" class="h-4 w-4 shrink-0 text-muted" />
            </button>
        </template>

        <template #default="{ close }">
            <ul role="listbox">
                <li v-for="option in options" :key="option.value ?? 'blank'">
                    <button type="button" role="option" :aria-selected="option.value === modelValue"
                        class="flex w-full items-center justify-between rounded-md px-2.5 py-2 text-start hover:bg-surface-sunken"
                        :class="{ 'bg-accent-soft': option.value === modelValue }"
                        @click="emit('update:modelValue', option.value); close()">
                        <span class="flex flex-col leading-tight">
                            <span class="text-sm text-ink">{{ option.label_es }}</span>
                            <span class="text-[11px] text-muted">{{ option.label_en }}</span>
                        </span>
                        <AppIcon v-if="option.value === modelValue" name="check" class="h-4 w-4 text-accent" />
                    </button>
                </li>
            </ul>
        </template>
    </VDropdown>

    <!-- Typed percentage for the Custom rate. -->
    <div v-if="isCustom" class="relative">
        <input type="number" step="0.01" min="0" max="100" :disabled="disabled"
            :value="customPercent"
            class="w-full rounded-md border bg-surface-sunken px-3 py-2 pe-8 text-sm text-ink placeholder:text-muted focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20"
            :class="invalid ? 'border-status-danger' : 'border-line-strong'"
            :placeholder="$t('vat.custom_placeholder')"
            @input="emit('update:customPercent', $event.target.value === '' ? null : Number($event.target.value))" />
        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-sm text-muted">%</span>
    </div>
  </div>
</template>
