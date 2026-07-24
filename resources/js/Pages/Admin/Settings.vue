<script setup>
/**
 * Screen 26 — Settings, Phase 1 sections: General + Email (SMTP).
 * SMTP is Super Admin-only; the stored password is never echoed back.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    general: { type: Object, required: true },
    mail: { type: Object, default: null },
    canManageMail: { type: Boolean, required: true },
    overtimePolicies: { type: Array, default: () => [] },
    overtimeTypes: { type: Array, default: () => [] },
    notificationMatrix: { type: Array, default: null },
    systemHealth: { type: Object, default: null },
});

// Notification matrix — deep-clone so toggles don't mutate the prop directly.
// Worker is deliberately absent: workers receive no CRM notifications.
const roles = ['super_admin', 'admin', 'manager'];
const matrixForm = useForm({
    matrix: (props.notificationMatrix ?? []).map((row) => ({ type: row.type, roles: { ...row.roles } })),
});

function saveMatrix() {
    matrixForm.put(route('settings.notifications'), { preserveScroll: true });
}

const healthStatus = { ok: 'ok', warn: 'warn', danger: 'danger' };

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
    from_name: props.mail?.from_name ?? 'AlphaRey',
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

/* --- Overtime policies --- */
const showPolicy = ref(false);
const editingPolicy = ref(null);
const policyForm = useForm({ name: '', type: 'percentage', rate: null, daily_threshold_hours: 9, accumulate_hours_per_day: 8, notes: '' });

function openPolicy(p = null) {
    editingPolicy.value = p;
    policyForm.name = p?.name ?? '';
    policyForm.type = p?.type ?? 'percentage';
    policyForm.rate = p?.rate ?? null;
    policyForm.daily_threshold_hours = p?.daily_threshold_hours ?? 9;
    policyForm.accumulate_hours_per_day = p?.accumulate_hours_per_day ?? 8;
    policyForm.notes = p?.notes ?? '';
    policyForm.clearErrors();
    showPolicy.value = true;
}
function savePolicy() {
    const opts = { preserveScroll: true, onSuccess: () => (showPolicy.value = false) };
    editingPolicy.value ? policyForm.put(`/admin/overtime-policies/${editingPolicy.value.id}`, opts) : policyForm.post('/admin/overtime-policies', opts);
}
function deletePolicy(p) { router.delete(`/admin/overtime-policies/${p.id}`, { preserveScroll: true }); }
</script>

<template>
    <Head :title="$t('settings.title')" />

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

            <!-- Overtime policies (Phase 4) — spans both columns -->
            <VCard title-key="overtime.title" class="lg:col-span-2" :padded="false">
                <template #header>
                    <VButton size="sm" icon="plus" @click="openPolicy()"><Bilingual k="overtime.add" inline /></VButton>
                </template>
                <table v-if="overtimePolicies.length" class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs text-ink-soft">
                            <th class="px-4 py-2 text-start"><Bilingual k="overtime.name" inline /></th>
                            <th class="px-4 py-2 text-start"><Bilingual k="overtime.type" inline /></th>
                            <th class="px-4 py-2 text-end"><Bilingual k="overtime.rate" inline /></th>
                            <th class="px-4 py-2 text-end"><Bilingual k="overtime.threshold" inline /></th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="p in overtimePolicies" :key="p.id" class="hover:bg-surface-hover">
                            <td class="px-4 py-2.5 font-medium">{{ p.name }}</td>
                            <td class="px-4 py-2.5"><VBadge status="accent"><Bilingual :k="`overtime.type_${p.type}`" inline /></VBadge></td>
                            <td class="tabular-nums px-4 py-2.5 text-end">{{ p.rate ?? '—' }}</td>
                            <td class="tabular-nums px-4 py-2.5 text-end">{{ p.daily_threshold_hours }}h</td>
                            <td class="px-4 py-2.5 text-end">
                                <span class="flex items-center justify-end gap-1">
                                    <VButton variant="ghost" size="sm" icon="edit" @click="openPolicy(p)" />
                                    <VButton variant="ghost" size="sm" icon="trash" @click="deletePolicy(p)" />
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-4 py-6 text-center text-sm text-muted"><Bilingual k="common.coming_soon" class="items-center" /></p>
            </VCard>

            <!-- Notification rules matrix (Super Admin only) -->
            <VCard v-if="notificationMatrix" title-key="settings.notifications_section">
                <template #description><Bilingual k="settings.notifications_hint" /></template>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase text-muted">
                                <th class="px-3 py-2 text-start font-medium"></th>
                                <th v-for="role in roles" :key="role" class="px-3 py-2 text-center font-medium">
                                    {{ $t(`settings.role_${role}`) }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in matrixForm.matrix" :key="row.type" class="border-b border-line">
                                <td class="px-3 py-2 text-ink">{{ $t(`settings.ntype_${row.type}`) }}</td>
                                <td v-for="role in roles" :key="role" class="px-3 py-2 text-center">
                                    <input type="checkbox" v-model="row.roles[role]"
                                        class="h-4 w-4 rounded border-line-strong text-accent focus:ring-accent" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 flex justify-end">
                    <VButton :loading="matrixForm.processing" @click="saveMatrix"><Bilingual k="common.save" inline /></VButton>
                </div>
            </VCard>

            <!-- System health (Super Admin only) -->
            <VCard v-if="systemHealth" title-key="settings.health_section">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div v-for="(check, key) in systemHealth" :key="key"
                        class="rounded-lg border border-line bg-surface-sunken p-3">
                        <div class="flex items-center gap-2">
                            <VStatusDot :status="healthStatus[check.status] ?? 'neutral'" />
                            <span class="text-xs font-medium text-ink-soft">{{ $t(`settings.health_${key}`) }}</span>
                        </div>
                        <p class="mt-1 text-sm text-ink">{{ check.detail }}</p>
                    </div>
                </div>
            </VCard>
        </div>

        <VModal :open="showPolicy" :title-key="editingPolicy ? 'overtime.title' : 'overtime.add'" size="sm" @close="showPolicy = false">
            <form id="ot-form" class="space-y-3" @submit.prevent="savePolicy">
                <FormField k="overtime.name" :error="policyForm.errors.name" required><VInput v-model="policyForm.name" /></FormField>
                <FormField k="overtime.type" :error="policyForm.errors.type" required>
                    <VSelect v-model="policyForm.type">
                        <option v-for="t in overtimeTypes" :key="t" :value="t">{{ $t(`overtime.type_${t}`) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="overtime.rate" :error="policyForm.errors.rate"><VInput v-model="policyForm.rate" type="number" step="0.01" /></FormField>
                <FormField k="overtime.threshold"><VInput v-model="policyForm.daily_threshold_hours" type="number" step="0.5" /></FormField>
                <FormField k="overtime.notes"><VTextarea v-model="policyForm.notes" :rows="2" /></FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showPolicy = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="ot-form" :loading="policyForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
