<script setup>
/**
 * Screen 07 — Clients list. Shared pool (all companies see the same
 * clients). Live search, filters, pagination; create in a modal.
 */
import { reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClientFormModal from '@/Components/Clients/ClientFormModal.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    clients: { type: Object, required: true },
    filters: { type: Object, required: true },
    clientTypes: { type: Array, required: true },
    can: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    client_type: props.filters.client_type ?? '',
    status: props.filters.status ?? '',
    sort: props.filters.sort ?? 'name',
    dir: props.filters.dir ?? 'asc',
    per_page: Number(props.filters.per_page ?? 25),
});

let timer = null;
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 350); });

function apply(extra = {}) {
    router.get('/clients', { ...filters, ...extra }, { preserveScroll: true, preserveState: true });
}
function sortBy(key) {
    filters.dir = filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc';
    filters.sort = key;
    apply();
}

const showForm = ref(false);

const columns = [
    { key: 'name', labelKey: 'clients.name', sortable: true },
    { key: 'company_name', labelKey: 'clients.company_name', sortable: true },
    { key: 'nif', labelKey: 'clients.nif' },
    { key: 'client_type', labelKey: 'clients.type' },
    { key: 'contact_person', labelKey: 'clients.contact_person' },
    { key: 'phone', labelKey: 'clients.phone' },
    { key: 'city', labelKey: 'clients.city', sortable: true },
    { key: 'projects_count', labelKey: 'clients.tab_projects', align: 'end' },
    { key: 'active', labelKey: 'clients.active' },
];
</script>

<template>
    <Head title="Clientes" />
    <AppLayout>
        <VPageHeader k="clients.title">
            <VButton v-if="can.create" icon="plus" @click="showForm = true">
                <Bilingual k="clients.new" inline />
            </VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <div class="w-full sm:w-60"><VSearchInput v-model="filters.search" /></div>
            <VSelect v-model="filters.client_type" class="w-40" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.clients.type }}</option>
                <option v-for="t in clientTypes" :key="t" :value="t">{{ $page.props.lang.es.clients[`type_${t}`] }}</option>
            </VSelect>
            <VSelect v-model="filters.status" class="w-36" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.clients.active }} / {{ $page.props.lang.en.clients.active }}</option>
                <option value="active">{{ $page.props.lang.es.clients.active }}</option>
                <option value="inactive">Inactivo</option>
            </VSelect>
        </div>

        <VTable :columns="columns" :sort="{ key: filters.sort, dir: filters.dir }" @sort="sortBy">
            <tr v-for="client in clients.data" :key="client.id"
                class="cursor-pointer hover:bg-surface-hover" @click="router.get(`/clients/${client.id}`)">
                <td class="px-3 py-2.5">
                    <span class="flex items-center gap-2">
                        <VAvatar :name="client.name" size="sm" />
                        <span class="text-sm font-medium">{{ client.name }}</span>
                    </span>
                </td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ client.company_name ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ client.nif ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ $page.props.lang.es.clients[`type_${client.client_type}`] }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ client.contact_person ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ client.phone ?? '—' }}</td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ client.city ?? '—' }}</td>
                <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ client.projects_count }}</td>
                <td class="px-3 py-2.5">
                    <VBadge :status="client.active ? 'ok' : 'neutral'">
                        <Bilingual :k="client.active ? 'clients.active' : 'employees.inactive'" inline />
                    </VBadge>
                </td>
            </tr>
            <template v-if="clients.data.length === 0" #empty><VEmptyState icon="clients" /></template>
        </VTable>

        <VPagination :page="clients.current_page" :pages="clients.last_page" :per-page="filters.per_page"
            :total="clients.total" @update:page="(p) => apply({ page: p })"
            @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />

        <ClientFormModal :open="showForm" @close="showForm = false" />
    </AppLayout>
</template>
