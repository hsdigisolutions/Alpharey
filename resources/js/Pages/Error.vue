<script setup>
/**
 * Bilingual error page for 403 / 404 / 500 / 503, rendered by the exception
 * handler in bootstrap/app.php.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    status: { type: Number, required: true },
});

const known = [403, 404, 500, 503];
const statusKey = computed(() => (known.includes(props.status) ? props.status : 500));
</script>

<template>
    <Head :title="String(status)" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4 text-center">
        <p class="text-6xl font-bold text-line">{{ status }}</p>
        <h1 class="mt-4 text-xl font-semibold">
            <Bilingual :k="`errors.${statusKey}_title`" class="items-center" />
        </h1>
        <p class="mt-2 max-w-sm text-sm text-ink-soft">
            <Bilingual :k="`errors.${statusKey}_message`" class="items-center" />
        </p>
        <a href="/" class="mt-6 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-on-accent">
            <Bilingual k="errors.back_home" inline />
        </a>
    </div>
</template>
