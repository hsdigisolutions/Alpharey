<script setup>
/**
 * Screen 26 — Settings, Phase 1 sections: General + Email (SMTP).
 * SMTP is Super Admin-only; the stored password is never echoed back.
 */
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VInput from '@/Components/ui/VInput.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';

const props = defineProps({
    general: { type: Object, required: true },
    mail: { type: Object, default: null },
    canManageMail: { type: Boolean, required: true },
});

const generalForm = useForm({
    app_name: props.general.app_name,
    default_locale: props.general.default_locale,
    timezone: props.general.timezone,
    session_timeout_minutes: props.general.session_timeout_minutes,
});

const mailForm = useForm({
    host: props.mail?.host ?? 'smtp.alpharey.com',
    port: props.mail?.port ?? 587,
    username: props.mail?.username ?? '',
    password: '',
    encryption: props.mail?.encryption ?? 'tls',
    from_name: props.mail?.from_name ?? 'Verto5',
    from_address: props.mail?.from_address ?? '',
});

const testForm = useForm({ to: '' });

function saveGeneral() {
    generalForm.put('/admin/settings/general', { preserveScroll: true });
}

function saveMail() {
    mailForm.put('/admin/settings/mail', {
        preserveScroll: true,
        onSuccess: () => mailForm.reset('password'),
    });
}

function sendTest() {
    testForm.post('/admin/settings/mail/test', { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuración" />

    <AppLayout>
        <VPageHeader k="settings.title" />

        <div class="grid gap-5 lg:grid-cols-2">
            <!-- General -->
            <VCard title-key="settings.general">
                <form class="space-y-4" @submit.prevent="saveGeneral">
                    <FormField k="settings.app_name" :error="generalForm.errors.app_name" required>
                        <VInput v-model="generalForm.app_name" :invalid="Boolean(generalForm.errors.app_name)" />
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField k="settings.default_language" :error="generalForm.errors.default_locale" required>
                            <VSelect v-model="generalForm.default_locale">
                                <option value="es">Español / Spanish</option>
                                <option value="en">Inglés / English</option>
                            </VSelect>
                        </FormField>
                        <FormField k="settings.session_timeout" :error="generalForm.errors.session_timeout_minutes" required>
                            <VInput v-model="generalForm.session_timeout_minutes" type="number"
                                :invalid="Boolean(generalForm.errors.session_timeout_minutes)" />
                        </FormField>
                    </div>
                    <FormField k="settings.timezone" :error="generalForm.errors.timezone" required>
                        <VInput v-model="generalForm.timezone" :invalid="Boolean(generalForm.errors.timezone)" />
                    </FormField>
                    <div class="flex justify-end">
                        <VButton type="submit" :loading="generalForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </div>
                </form>
            </VCard>

            <!-- Email (SMTP) — Super Admin only -->
            <VCard v-if="props.canManageMail && props.mail" title-key="settings.email_section">
                <form class="space-y-4" @submit.prevent="saveMail">
                    <div class="grid gap-4 sm:grid-cols-[1fr_120px]">
                        <FormField k="settings.host" :error="mailForm.errors.host" required>
                            <VInput v-model="mailForm.host" :invalid="Boolean(mailForm.errors.host)" />
                        </FormField>
                        <FormField k="settings.port" :error="mailForm.errors.port" required>
                            <VInput v-model="mailForm.port" type="number" :invalid="Boolean(mailForm.errors.port)" />
                        </FormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField k="settings.username" :error="mailForm.errors.username">
                            <VInput v-model="mailForm.username" autocomplete="off" />
                        </FormField>
                        <FormField k="settings.password" :error="mailForm.errors.password">
                            <VInput v-model="mailForm.password" type="password" autocomplete="new-password"
                                :placeholder="props.mail.has_password ? '••••••••' : ''" />
                            <p class="mt-1 text-xs text-muted">
                                <Bilingual k="settings.password_keep_hint" />
                            </p>
                        </FormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField k="settings.encryption" :error="mailForm.errors.encryption" required>
                            <VSelect v-model="mailForm.encryption">
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">—</option>
                            </VSelect>
                        </FormField>
                        <FormField k="settings.from_name" :error="mailForm.errors.from_name" required>
                            <VInput v-model="mailForm.from_name" :invalid="Boolean(mailForm.errors.from_name)" />
                        </FormField>
                        <FormField k="settings.from_address" :error="mailForm.errors.from_address" required>
                            <VInput v-model="mailForm.from_address" type="email"
                                :invalid="Boolean(mailForm.errors.from_address)" />
                        </FormField>
                    </div>
                    <div class="flex justify-end">
                        <VButton type="submit" :loading="mailForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </div>
                </form>

                <div class="mt-5 border-t border-line pt-4">
                    <Bilingual k="settings.test_title" class="text-sm font-semibold" />
                    <form class="mt-2 flex items-end gap-2" @submit.prevent="sendTest">
                        <div class="flex-1">
                            <FormField k="settings.test_to" :error="testForm.errors.to">
                                <VInput v-model="testForm.to" type="email" :invalid="Boolean(testForm.errors.to)" />
                            </FormField>
                        </div>
                        <VButton type="submit" variant="secondary" :loading="testForm.processing">
                            <Bilingual k="settings.send_test" inline />
                        </VButton>
                    </form>
                </div>
            </VCard>

            <VCard v-else title-key="settings.email_section">
                <p class="text-sm text-muted">
                    <Bilingual k="settings.sa_only" />
                </p>
            </VCard>
        </div>
    </AppLayout>
</template>
