<script setup>
/**
 * Screen 01 placeholder. The visual structure matches REQUIREMENTS.md §7
 * (Screen 01); the actual authentication flow is Phase 1 work, so the form
 * is intentionally inert.
 */
import { Head, router, usePage } from '@inertiajs/vue3';

const page = usePage();

function switchLocale() {
    const next = page.props.locale.primary === 'es' ? 'en' : 'es';
    router.post('/locale', { locale: next }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Login" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <button type="button"
            class="absolute end-4 top-4 rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-ink-soft hover:bg-surface-sunken"
            @click="switchLocale">
            {{ page.props.locale.primary.toUpperCase() }} / {{ page.props.locale.secondary.toUpperCase() }}
        </button>

        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-accent text-lg font-bold text-on-accent">V5</span>
                <h1 class="text-center text-xl font-semibold">
                    <Bilingual k="auth.welcome_back" class="items-center" />
                </h1>
            </div>

            <form class="space-y-4 rounded-2xl border border-line bg-surface-raised p-6 shadow-sm" @submit.prevent>
                <label class="block">
                    <Bilingual k="auth.email" class="mb-1 text-sm font-medium" />
                    <input type="email" disabled autocomplete="username"
                        class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm opacity-60" />
                </label>
                <label class="block">
                    <Bilingual k="auth.password" class="mb-1 text-sm font-medium" />
                    <input type="password" disabled autocomplete="current-password"
                        class="w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm opacity-60" />
                </label>
                <label class="flex items-center gap-2 text-sm text-ink-soft">
                    <input type="checkbox" disabled class="rounded border-line opacity-60" />
                    <Bilingual k="auth.remember_me" inline />
                </label>
                <button type="submit" disabled
                    class="w-full cursor-not-allowed rounded-lg bg-accent py-2.5 text-sm font-semibold text-on-accent opacity-50">
                    <Bilingual k="auth.login" inline />
                </button>
                <p class="text-center text-xs text-muted">
                    <Bilingual k="auth.phase1_note" class="items-center" />
                </p>
            </form>
        </div>
    </div>
</template>
