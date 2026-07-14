<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Nueva contraseña" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-lg bg-accent text-lg font-bold text-on-accent">V5</span>
                <h1 class="text-center text-xl font-semibold">
                    <Bilingual k="auth.reset_title" class="items-center" />
                </h1>
            </div>

            <form class="space-y-4 rounded-lg border border-line bg-surface-raised p-6 shadow-card" @submit.prevent="submit">
                <FormField k="auth.email" for-id="email" :error="form.errors.email" required>
                    <VInput id="email" v-model="form.email" type="email" autocomplete="username"
                        :invalid="Boolean(form.errors.email)" />
                </FormField>

                <FormField k="auth.new_password" for-id="password" :error="form.errors.password" required>
                    <VInput id="password" v-model="form.password" type="password" autocomplete="new-password"
                        :invalid="Boolean(form.errors.password)" />
                </FormField>

                <FormField k="auth.confirm_password" for-id="password_confirmation" required>
                    <VInput id="password_confirmation" v-model="form.password_confirmation" type="password"
                        autocomplete="new-password" />
                </FormField>

                <VButton type="submit" class="w-full" :loading="form.processing">
                    <Bilingual k="auth.save_password" inline />
                </VButton>
            </form>
        </div>
    </div>
</template>
