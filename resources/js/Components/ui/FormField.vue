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
    <!-- min-w-0: a grid/flex child defaults to min-width:auto, and a native
         <select> is as wide as its widest <option> — one long employee name
         would otherwise stretch the whole form and scroll the modal sideways. -->
    <div class="min-w-0">
        <label :for="forId" class="mb-1.5 flex items-baseline gap-1">
            <Bilingual :k="k" class="text-[13px] font-medium" />
            <span v-if="required" class="text-status-danger" aria-hidden="true">*</span>
        </label>
        <slot />
        <p v-if="error" class="mt-1.5 text-xs text-status-danger">{{ error }}</p>
    </div>
</template>
