<script setup>
/**
 * The single, one-time display of the recovery codes, straight after
 * enrolment. They are stored hashed-by-encryption on the user and are never
 * shown again — losing both phone and codes means asking a Super Admin to
 * reset the second factor.
 */
import { Head, router } from '@inertiajs/vue3';
import VButton from '@/Components/ui/VButton.vue';

defineProps({
    codes: { type: Array, required: true },
});

function done() {
    router.visit('/dashboard');
}
</script>

<template>
    <Head :title="$t('two_factor.recovery_title')" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-8">
        <div class="w-full max-w-md rounded-lg border border-line bg-surface-raised p-6 shadow-card">
            <h1 class="text-lg font-semibold text-ink">
                <Bilingual k="two_factor.recovery_title" />
            </h1>
            <p class="mt-2 text-sm text-ink-soft">
                <Bilingual k="two_factor.recovery_intro" />
            </p>

            <ul class="mt-5 grid grid-cols-2 gap-2 rounded-lg border border-line bg-surface-sunken p-3">
                <li v-for="code in codes" :key="code"
                    class="tabular-nums rounded-md px-2 py-1.5 text-center text-sm tracking-wider text-ink">
                    {{ code }}
                </li>
            </ul>

            <VButton class="mt-5 w-full" @click="done">
                <Bilingual k="two_factor.recovery_done" inline />
            </VButton>
        </div>
    </div>
</template>
