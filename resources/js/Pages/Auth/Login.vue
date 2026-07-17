<script setup>
/**
 * Screen 01 — Login (REQUIREMENTS.md §7). Session auth, remember me
 * (30 days), rate-limited server-side. Routing after login is by role.
 */
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VInput from '@/Components/ui/VInput.vue';

const page = usePage();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

function switchLocale() {
    const next = page.props.locale.primary === 'es' ? 'en' : 'es';
    router.post('/locale', { locale: next }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('auth.login')" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <button type="button"
            class="absolute end-4 top-4 rounded-md border border-line px-2.5 py-1.5 text-xs font-semibold text-ink-soft hover:bg-surface-hover"
            @click="switchLocale">
            {{ page.props.locale.primary.toUpperCase() }} / {{ page.props.locale.secondary.toUpperCase() }}
        </button>

        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-accent text-lg font-bold text-on-accent">V5</span>
                <h1 class="text-center text-xl font-semibold">
                    <Bilingual k="auth.welcome_back" class="items-center" />
                </h1>
            </div>

            <form class="space-y-4 rounded-lg border border-line bg-surface-raised p-6 shadow-card" @submit.prevent="submit">
                <FormField k="auth.email" for-id="email" :error="form.errors.email" required>
                    <VInput id="email" v-model="form.email" type="email" autocomplete="username"
                        :invalid="Boolean(form.errors.email)" />
                </FormField>

                <FormField k="auth.password" for-id="password" :error="form.errors.password" required>
                    <VInput id="password" v-model="form.password" type="password" autocomplete="current-password"
                        :invalid="Boolean(form.errors.password)" />
                </FormField>

                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <VCheckbox v-model="form.remember">
                        <Bilingual k="auth.remember_me" inline class="text-sm" />
                    </VCheckbox>
                    <a href="/forgot-password" class="text-accent-hover hover:underline">
                        <Bilingual k="auth.forgot_password" class="items-end text-end text-[13px] leading-tight" />
                    </a>
                </div>

                <VButton type="submit" class="w-full" :loading="form.processing">
                    <Bilingual k="auth.login" inline />
                </VButton>
            </form>
        </div>
    </div>
</template>
