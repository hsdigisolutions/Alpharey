<script setup>
/**
 * Two-step verification challenge — shown between the password and the
 * session. The user is NOT logged in at this point; a valid TOTP (or a
 * single-use recovery code) is what completes the login.
 */
import { Head, useForm } from '@inertiajs/vue3';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';

const form = useForm({ code: '' });

function submit() {
    form.post('/two-factor/challenge', {
        onError: () => {
            // Clear the field value so the user must retype, but preserve the
            // error message (form.reset() also clears errors, so set directly).
            form.code = '';
        },
    });
}
</script>

<template>
    <Head :title="$t('two_factor.challenge_title')" />

    <div class="flex min-h-screen flex-col items-center justify-center px-4">
        <div class="w-full max-w-sm rounded-lg border border-line bg-surface-raised p-6 shadow-card">
            <h1 class="text-lg font-semibold text-ink">
                <Bilingual k="two_factor.challenge_title" />
            </h1>
            <p class="mt-2 text-sm text-ink-soft">
                <Bilingual k="two_factor.challenge_intro" />
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <FormField k="two_factor.code" :error="form.errors.code" required>
                    <VInput v-model="form.code" inputmode="numeric" autocomplete="one-time-code"
                        autofocus maxlength="11" />
                </FormField>

                <p class="text-xs text-muted">
                    <Bilingual k="two_factor.challenge_recovery_hint" />
                </p>

                <VButton type="submit" class="w-full" :loading="form.processing">
                    <Bilingual k="two_factor.verify" inline />
                </VButton>
            </form>
        </div>
    </div>
</template>
