<script setup>
/**
 * Bilingual error page for 403 / 404 / 500 / 503, rendered by the exception
 * handler in bootstrap/app.php.
 *
 * 403 for an authenticated user: shown inside the app shell (sidebar visible,
 * user feels at home) with a "request access from your admin" message.
 * All other errors: standalone centred page (the app shell may itself be broken).
 */
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    status: { type: Number, required: true },
});

const page = usePage();
const known = [403, 404, 500, 503];
const statusKey = computed(() => (known.includes(props.status) ? props.status : 500));

// Show the full app shell for 403 when the user is authenticated so they can
// navigate back to a module they do have access to without losing context.
const showInApp = computed(() => props.status === 403 && !!page.props.auth?.user);
const homeUrl = computed(() => page.props.auth?.user?.role === 'worker' ? '/worker' : '/');
</script>

<template>
    <Head :title="String(status)" />

    <!-- 403 inside the app shell -->
    <AppLayout v-if="showInApp">
        <div class="flex flex-col items-center justify-center py-24 px-4 text-center">
            <div class="mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-status-danger-soft">
                <AppIcon name="lock" class="h-8 w-8 text-status-danger" />
            </div>
            <h1 class="text-xl font-semibold text-ink">
                <Bilingual k="errors.403_title" class="items-center" />
            </h1>
            <p class="mt-2 max-w-sm text-sm text-ink-soft">
                <Bilingual k="errors.403_message" class="items-center" />
            </p>
            <p class="mt-1 max-w-sm text-sm text-muted">
                <Bilingual k="errors.403_contact" class="items-center" />
            </p>
            <a href="/dashboard"
                class="mt-8 inline-flex items-center rounded-md bg-accent px-4 py-2 text-sm font-semibold text-on-accent hover:bg-accent-hover transition-colors">
                <Bilingual k="errors.back_dashboard" inline />
            </a>
        </div>
    </AppLayout>

    <!-- All other errors: standalone centred page -->
    <div v-else class="flex min-h-screen flex-col items-center justify-center px-4 text-center">
        <p class="text-6xl font-bold text-line">{{ status }}</p>
        <h1 class="mt-4 text-xl font-semibold">
            <Bilingual :k="`errors.${statusKey}_title`" class="items-center" />
        </h1>
        <p class="mt-2 max-w-sm text-sm text-ink-soft">
            <Bilingual :k="`errors.${statusKey}_message`" class="items-center" />
        </p>
        <a :href="homeUrl"
            class="mt-6 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-on-accent hover:bg-accent-hover transition-colors">
            <Bilingual k="errors.back_home" inline />
        </a>
    </div>
</template>
