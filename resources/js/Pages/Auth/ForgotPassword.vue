<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';

const page = usePage();

const form = useForm({ email: '' });

function submit() {
    form.post('/forgot-password');
}
</script>

<template>
    <Head title="Recuperar contraseña" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-accent text-lg font-bold text-on-accent">V5</span>
                <h1 class="text-center text-xl font-semibold">
                    <Bilingual k="auth.forgot_title" class="items-center" />
                </h1>
                <p class="text-center text-sm text-muted">
                    <Bilingual k="auth.forgot_hint" class="items-center" />
                </p>
            </div>

            <form class="space-y-4 rounded-lg border border-line bg-surface-raised p-6 shadow-card" @submit.prevent="submit">
                <VAlert v-if="page.props.flash?.success" status="ok">{{ page.props.flash.success }}</VAlert>

                <FormField k="auth.email" for-id="email" :error="form.errors.email" required>
                    <VInput id="email" v-model="form.email" type="email" autocomplete="username"
                        :invalid="Boolean(form.errors.email)" />
                </FormField>

                <VButton type="submit" class="w-full" :loading="form.processing">
                    <Bilingual k="auth.send_link" inline />
                </VButton>

                <p class="text-center">
                    <a href="/login" class="text-sm text-accent-hover hover:underline">
                        <Bilingual k="auth.back_to_login" inline class="text-[13px]" />
                    </a>
                </p>
            </form>
        </div>
    </div>
</template>
