<script setup>
/**
 * Screen 20 — Vendors list. Shared pool. Create in a modal.
 */
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    vendors: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    per_page: Number(props.filters.per_page ?? 25),
});
let timer = null;
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 350); });
function apply(extra = {}) { router.get('/vendors', { ...filters, ...extra }, { preserveScroll: true, preserveState: true }); }

const showForm = ref(false);
const blank = { name: '', company_name: '', nif: '', phone: '', email: '', city: '', address: '', active: true, notes: '' };
const form = useForm({ ...blank });
function submit() {
    form.post('/vendors', { preserveScroll: true, onSuccess: () => { showForm.value = false; form.reset(); } });
}

const columns = [
    { key: 'name', labelKey: 'vendors.name' },
    { key: 'company_name', labelKey: 'vendors.company_name' },
    { key: 'nif', labelKey: 'vendors.nif' },
    { key: 'phone', labelKey: 'vendors.phone' },
    { key: 'email', labelKey: 'vendors.email' },
    { key: 'city', labelKey: 'vendors.city' },
    { key: 'active', labelKey: 'vendors.active' },
];
</script>

<template>
    <Head :title="$t('vendors.title')" />
    <AppLayout>
        <VPageHeader k="vendors.title">
            <VButton v-if="can.create" icon="plus" @click="showForm = true"><Bilingual k="vendors.new" inline /></VButton>
        </VPageHeader>

        <div class="w-full pb-3 sm:w-60"><VSearchInput v-model="filters.search" /></div>

        <VTable :columns="columns">
            <tr v-for="v in vendors.data" :key="v.id" class="cursor-pointer hover:bg-surface-hover" @click="router.get(`/vendors/${v.id}`)">
                <td class="px-3 py-2.5">
                    <span class="flex items-center gap-2"><VAvatar :name="v.name" size="sm" /><span class="text-sm font-medium">{{ v.name }}</span></span>
                </td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.company_name ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.nif ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.phone ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.email ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ v.city ?? '—' }}</td>
                <td class="px-3 py-2.5"><VBadge :status="v.active ? 'ok' : 'neutral'"><Bilingual :k="v.active ? 'vendors.active' : 'employees.inactive'" inline /></VBadge></td>
            </tr>
            <template v-if="vendors.data.length === 0" #empty><VEmptyState icon="clients" /></template>
        </VTable>

        <VPagination :page="vendors.current_page" :pages="vendors.last_page" :per-page="filters.per_page"
            :total="vendors.total" @update:page="(p) => apply({ page: p })" @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />

        <VModal :open="showForm" title-key="vendors.new" @close="showForm = false">
            <form id="vendor-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField k="vendors.name" :error="form.errors.name" required><VInput v-model="form.name" :invalid="Boolean(form.errors.name)" /></FormField>
                <FormField k="vendors.company_name"><VInput v-model="form.company_name" /></FormField>
                <FormField k="vendors.nif"><VInput v-model="form.nif" /></FormField>
                <FormField k="vendors.phone"><VInput v-model="form.phone" /></FormField>
                <FormField k="vendors.email"><VInput v-model="form.email" type="email" /></FormField>
                <FormField k="vendors.city"><VInput v-model="form.city" /></FormField>
                <VCheckbox v-model="form.active"><Bilingual k="vendors.active" inline class="text-sm" /></VCheckbox>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showForm = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="vendor-form" :loading="form.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
