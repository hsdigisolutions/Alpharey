<script setup>
/**
 * "My Account" — self-service for the signed-in CRM user.
 *
 * Scope is deliberately narrow (client decisions 2026-07-23): edit only your
 * own name; ask a Super Admin to reset your password (you never set it here);
 * and manage your own second factor behind a password re-check. Email, role and
 * company are shown read-only — they are login/authorization data an admin owns.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';

const props = defineProps({
    account: { type: Object, required: true },
});

// Flash success/error surface as toasts via VToastHost in the layout.

// --- Profile (name only) ----------------------------------------------------
const profileForm = useForm({ name: props.account.name });

function saveProfile() {
    profileForm.put('/account/profile', { preserveScroll: true });
}

// --- Password: request a Super Admin to reset -------------------------------
const requesting = ref(false);

function requestPasswordReset() {
    requesting.value = true;
    router.post('/account/password-reset-request', {}, {
        preserveScroll: true,
        onFinish: () => { requesting.value = false; },
    });
}

// --- Second factor: password-gated actions ----------------------------------
// One confirm modal, two possible actions.
const confirmOpen = ref(false);
const confirmAction = ref(null); // 'reconfigure' | 'recovery'
const confirmForm = useForm({ current_password: '' });

const confirmTitleKey = computed(() =>
    confirmAction.value === 'reconfigure' ? 'account.tfa_reconfigure' : 'account.tfa_recovery',
);

function openConfirm(action) {
    confirmAction.value = action;
    confirmForm.reset();
    confirmForm.clearErrors();
    confirmOpen.value = true;
}

function submitConfirm() {
    const url = confirmAction.value === 'reconfigure'
        ? '/account/two-factor/reconfigure'
        : '/account/two-factor/recovery-codes';

    // Full visit (not preserveScroll): both actions redirect away — to the 2FA
    // setup screen, or to the one-time recovery-codes screen.
    confirmForm.post(url, {
        onError: () => {},
    });
}
</script>

<template>
    <Head :title="t('account.title')" />

    <AppLayout>
        <VPageHeader k="account.title" />

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- Profile -->
            <VCard>
                <h2 class="mb-4 text-section font-semibold"><Bilingual k="account.profile" inline /></h2>

                <form class="space-y-4" @submit.prevent="saveProfile">
                    <FormField k="account.name" :error="profileForm.errors.name" required>
                        <VInput v-model="profileForm.name" :invalid="Boolean(profileForm.errors.name)" autocomplete="name" />
                    </FormField>

                    <!-- Read-only identity: managed by an admin -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-muted"><Bilingual k="account.email" inline /></p>
                            <p class="truncate text-sm text-ink">{{ account.email }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-muted"><Bilingual k="account.role" inline /></p>
                            <p class="text-sm text-ink">{{ $t(`roles.${account.role}`) }}</p>
                        </div>
                        <div v-if="account.company">
                            <p class="text-xs text-muted"><Bilingual k="account.company" inline /></p>
                            <p class="truncate text-sm text-ink">{{ account.company }}</p>
                        </div>
                    </div>
                    <p class="text-xs text-muted"><Bilingual k="account.identity_hint" /></p>

                    <div class="flex justify-end">
                        <VButton type="submit" :loading="profileForm.processing" :disabled="!profileForm.isDirty">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </div>
                </form>
            </VCard>

            <!-- Security -->
            <VCard>
                <h2 class="mb-4 text-section font-semibold"><Bilingual k="account.security" inline /></h2>

                <!-- Password -->
                <div class="border-b border-line pb-4">
                    <p class="mb-1 text-sm font-medium text-ink"><Bilingual k="account.password" inline /></p>
                    <template v-if="account.password_reset_requested">
                        <div class="flex items-center gap-2">
                            <VBadge status="warn"><Bilingual k="account.password_pending" inline /></VBadge>
                        </div>
                        <p class="mt-2 text-xs text-muted"><Bilingual k="account.password_pending_hint" /></p>
                    </template>
                    <template v-else>
                        <p class="mb-2 text-xs text-muted"><Bilingual k="account.password_hint" /></p>
                        <VButton variant="secondary" size="sm" :loading="requesting" @click="requestPasswordReset">
                            <Bilingual k="account.password_request" inline />
                        </VButton>
                    </template>
                </div>

                <!-- Two-factor -->
                <div class="pt-4">
                    <div class="mb-1 flex items-center gap-2">
                        <p class="text-sm font-medium text-ink"><Bilingual k="account.tfa" inline /></p>
                        <VBadge :status="account.two_factor_enabled ? 'ok' : 'neutral'">
                            <Bilingual :k="account.two_factor_enabled ? 'account.tfa_on' : 'account.tfa_off'" inline />
                        </VBadge>
                    </div>
                    <p class="mb-3 text-xs text-muted"><Bilingual k="account.tfa_hint" /></p>
                    <div class="flex flex-wrap gap-2">
                        <VButton variant="secondary" size="sm" @click="openConfirm('reconfigure')">
                            <Bilingual k="account.tfa_reconfigure" inline />
                        </VButton>
                        <VButton v-if="account.two_factor_enabled" variant="ghost" size="sm" @click="openConfirm('recovery')">
                            <Bilingual k="account.tfa_recovery" inline />
                        </VButton>
                    </div>
                </div>
            </VCard>
        </div>

        <!-- Password re-check before a 2FA action -->
        <VModal :open="confirmOpen" :title-key="confirmTitleKey" @close="confirmOpen = false">
            <form id="confirm-form" class="space-y-3" @submit.prevent="submitConfirm">
                <p class="text-sm text-ink-soft"><Bilingual k="account.confirm_password_hint" /></p>
                <FormField k="account.current_password" :error="confirmForm.errors.current_password" required>
                    <VInput v-model="confirmForm.current_password" type="password" autocomplete="current-password"
                        :invalid="Boolean(confirmForm.errors.current_password)" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="confirmOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="confirm-form" :loading="confirmForm.processing">
                    <Bilingual k="common.continue" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
