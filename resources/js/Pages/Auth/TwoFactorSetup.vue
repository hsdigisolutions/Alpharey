<script setup>
/**
 * Mandatory 2FA enrolment. Reached automatically for any user who has not set
 * up a second factor yet — RequireTwoFactor keeps them here until they confirm.
 *
 * The QR is an inline SVG data URI generated server-side: the CSP forbids
 * external image hosts, and a third-party QR service would be handed the
 * shared secret.
 */
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';

defineProps({
    qr: { type: String, required: true },
    secret: { type: String, required: true },
});

const form = useForm({ code: '' });

function submit() {
    form.post('/two-factor/setup', {
        onFinish: () => form.reset('code'),
    });
}
</script>

<template>
    <Head :title="$t('two_factor.setup_title')" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-8">
        <div class="w-full max-w-md rounded-lg border border-line bg-surface-raised p-6 shadow-card">
            <h1 class="text-lg font-semibold text-ink">
                <Bilingual k="two_factor.setup_title" />
            </h1>
            <p class="mt-2 text-sm text-ink-soft">
                <Bilingual k="two_factor.setup_intro" />
            </p>

            <div class="mt-5 flex justify-center rounded-lg border border-line bg-surface p-4">
                <img :src="qr" alt="" class="h-48 w-48" />
            </div>

            <div class="mt-4">
                <p class="text-xs text-muted"><Bilingual k="two_factor.manual_key" inline /></p>
                <code class="mt-1 block break-all rounded-md bg-surface-sunken px-3 py-2 text-xs tracking-wider text-ink">
                    {{ secret }}
                </code>
            </div>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <FormField k="two_factor.code" :error="form.errors.code" required>
                    <VInput v-model="form.code" inputmode="numeric" autocomplete="one-time-code"
                        autofocus maxlength="6" />
                </FormField>

                <VButton type="submit" class="w-full" :loading="form.processing">
                    <Bilingual k="two_factor.confirm" inline />
                </VButton>
            </form>
        </div>
    </div>
</template>
