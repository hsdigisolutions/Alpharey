<script setup>
/**
 * Standard form row: bilingual label above the control, error line below.
 * Every form field in the system goes through this wrapper so labels and
 * validation render identically everywhere.
 */
defineProps({
    k: { type: String, required: true }, // ui translation key for the label
    forId: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <div>
        <label :for="forId" class="mb-1.5 flex items-baseline gap-1">
            <Bilingual :k="k" class="text-[13px] font-medium" />
            <span v-if="required" class="text-status-danger" aria-hidden="true">*</span>
        </label>
        <slot />
        <p v-if="error" class="mt-1.5 text-xs text-status-danger">{{ error }}</p>
    </div>
</template>
