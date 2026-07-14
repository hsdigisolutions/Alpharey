<script setup>
/**
 * Screen 04 — Companies (Super Admin only). Cards grid; detail opens in
 * a slide-over with Información + Estadísticas tabs; create in a modal;
 * removal behind a typed-name confirmation (safety checks server-side).
 */
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    companies: { type: Array, required: true },
});

const showCreate = ref(false);
const selectedId = ref(null);
const tab = ref('info');
const showDelete = ref(false);

const selected = computed(() => props.companies.find((c) => c.id === selectedId.value) ?? null);

const blankFields = {
    name: '', cif: '', province: '', address: '', city: '', postal_code: '',
    phone: '', email: '', website: '', status: 'active', notes: '',
};

const createForm = useForm({ ...blankFields });
const editForm = useForm({ ...blankFields });
const deleteForm = useForm({ confirm_name: '' });

watch(selected, (company) => {
    if (company) {
        Object.keys(blankFields).forEach((key) => {
            editForm[key] = company[key] ?? (key === 'status' ? 'active' : '');
        });
        editForm.clearErrors();
        tab.value = 'info';
    }
});

function submitCreate() {
    createForm.post('/companies', {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

function submitEdit() {
    editForm.put(`/companies/${selectedId.value}`, { preserveScroll: true });
}

function submitDelete() {
    deleteForm.delete(`/companies/${selectedId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDelete.value = false;
            selectedId.value = null;
            deleteForm.reset();
        },
    });
}

const fieldRows = [
    ['name', 'cif'],
    ['province', 'city'],
    ['address', 'postal_code'],
    ['phone', 'email'],
    ['website', 'status'],
];
</script>

<template>
    <Head title="Empresas" />

    <AppLayout>
        <VPageHeader k="companies.title">
            <VButton icon="plus" @click="showCreate = true">
                <Bilingual k="companies.new" inline />
            </VButton>
        </VPageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <button v-for="company in props.companies" :key="company.id" type="button"
                class="flex flex-col rounded-xl border border-line bg-surface-raised p-5 text-start shadow-card transition-colors hover:border-line-strong"
                @click="selectedId = company.id">
                <div class="flex items-start gap-3">
                    <VAvatar :name="company.name" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[17px] font-semibold">{{ company.name }}</p>
                        <p class="truncate text-sm text-ink-soft">{{ company.province ?? '—' }}</p>
                        <p class="tabular-nums text-xs text-muted">{{ company.cif ?? '—' }}</p>
                    </div>
                    <VBadge :status="company.status === 'active' ? 'ok' : 'neutral'">
                        <Bilingual :k="company.status === 'active' ? 'companies.active' : 'companies.inactive'" inline />
                    </VBadge>
                </div>
                <p class="tabular-nums mt-3 text-xs text-muted">
                    {{ company.users_count }} <Bilingual k="welcome.users" inline class="text-xs" />
                </p>
            </button>
        </div>

        <!-- Detail slide-over: Información + Estadísticas -->
        <VSlideOver :open="selected !== null" title-key="companies.title" @close="selectedId = null">
            <template v-if="selected">
                <VTabs v-model="tab" :tabs="[
                    { key: 'info', labelKey: 'companies.tab_info' },
                    { key: 'stats', labelKey: 'companies.tab_stats' },
                ]" />

                <form v-if="tab === 'info'" class="mt-4 space-y-4" @submit.prevent="submitEdit">
                    <div v-for="(row, i) in fieldRows" :key="i" class="grid gap-4 sm:grid-cols-2">
                        <template v-for="field in row" :key="field">
                            <FormField v-if="field === 'status'" k="companies.status" :error="editForm.errors.status">
                                <VSelect v-model="editForm.status">
                                    <option value="active">{{ $page.props.lang.es.companies.active }} / {{ $page.props.lang.en.companies.active }}</option>
                                    <option value="inactive">{{ $page.props.lang.es.companies.inactive }} / {{ $page.props.lang.en.companies.inactive }}</option>
                                </VSelect>
                            </FormField>
                            <FormField v-else :k="`companies.${field}`" :error="editForm.errors[field]"
                                :required="field === 'name'">
                                <VInput v-model="editForm[field]" :invalid="Boolean(editForm.errors[field])" />
                            </FormField>
                        </template>
                    </div>
                    <FormField k="companies.notes" :error="editForm.errors.notes">
                        <VTextarea v-model="editForm.notes" :rows="3" />
                    </FormField>

                    <div class="flex justify-end">
                        <VButton type="submit" :loading="editForm.processing">
                            <Bilingual k="common.save" inline />
                        </VButton>
                    </div>

                    <!-- Danger zone -->
                    <div class="mt-6 rounded-lg border border-status-danger/40 bg-status-danger-soft/40 p-4">
                        <Bilingual k="companies.danger_zone" class="text-sm font-semibold text-status-danger" />
                        <p class="mt-1 text-xs text-ink-soft">
                            <Bilingual k="companies.danger_hint" />
                        </p>
                        <VButton variant="danger" size="sm" icon="trash" class="mt-3" @click="showDelete = true">
                            <Bilingual k="companies.delete" inline />
                        </VButton>
                    </div>
                </form>

                <div v-else class="mt-4 grid grid-cols-2 gap-4">
                    <VKpiCard k="welcome.employees" :value="selected.employees_count ?? '—'" icon="employees" />
                    <VKpiCard k="welcome.projects" :value="selected.projects_count ?? '—'" icon="projects" />
                    <VKpiCard k="companies.users" :value="selected.users_count" icon="user" />
                    <VKpiCard k="nav.compliance" :value="selected.compliance_score !== null ? `${selected.compliance_score}%` : '—'" icon="alert" />
                </div>
            </template>
        </VSlideOver>

        <!-- Create modal -->
        <VModal :open="showCreate" title-key="companies.new" @close="showCreate = false">
            <form id="create-company" class="space-y-4" @submit.prevent="submitCreate">
                <FormField k="companies.name" :error="createForm.errors.name" required>
                    <VInput v-model="createForm.name" :invalid="Boolean(createForm.errors.name)" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="companies.cif" :error="createForm.errors.cif">
                        <VInput v-model="createForm.cif" />
                    </FormField>
                    <FormField k="companies.province" :error="createForm.errors.province">
                        <VInput v-model="createForm.province" />
                    </FormField>
                </div>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showCreate = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="create-company" :loading="createForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Typed-name delete confirmation -->
        <VModal :open="showDelete" title-key="companies.delete_title" size="sm" @close="showDelete = false">
            <VAlert status="danger">
                <Bilingual k="companies.danger_hint" />
            </VAlert>
            <form id="delete-company" class="mt-4 space-y-3" @submit.prevent="submitDelete">
                <FormField k="companies.confirm_hint" :error="deleteForm.errors.confirm_name" required>
                    <VInput v-model="deleteForm.confirm_name" :placeholder="selected?.name"
                        :invalid="Boolean(deleteForm.errors.confirm_name)" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showDelete = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="danger" type="submit" form="delete-company" :loading="deleteForm.processing"
                    :disabled="deleteForm.confirm_name !== selected?.name">
                    <Bilingual k="companies.delete" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
