<script setup>
/**
 * Screen 08 — Projects list with Table / Kanban toggle. Company-owned
 * (the list is already scoped to the active company server-side).
 */
import { reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectFormModal from '@/Components/Projects/ProjectFormModal.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    projects: { type: Object, default: null },
    kanban: { type: Object, default: null },
    view: { type: String, required: true },
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
    can: { type: Object, required: true },
    // vatOptions comes from a shared page prop when needed; fetch via the modal below
    vatOptions: { type: Array, default: () => ([{ value: null, label_es: 'No aplica', label_en: 'Not applicable' }]) },
});

const filters = reactive({
    search: props.filters.search ?? '',
    client_id: props.filters.client_id ?? '',
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    sort: props.filters.sort ?? 'created_at',
    dir: props.filters.dir ?? 'desc',
    per_page: Number(props.filters.per_page ?? 25),
    view: props.view,
});
let timer = null;
watch(() => filters.search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 350); });
function apply(extra = {}) { router.get('/projects', { ...filters, ...extra }, { preserveScroll: true, preserveState: true }); }
function setView(v) { filters.view = v; apply(); }
function sortBy(key) { filters.dir = filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc'; filters.sort = key; apply(); }

const showForm = ref(false);

const statusBadge = { active: 'ok', in_progress: 'info', completed: 'ok', cancelled: 'danger', on_hold: 'warn' };
const priorityBadge = { low: 'neutral', medium: 'info', high: 'warn', urgent: 'danger' };
const kanbanColumns = ['active', 'in_progress', 'completed', 'cancelled', 'on_hold'];

const columns = [
    { key: 'code', labelKey: 'projects.code', sortable: true },
    { key: 'name', labelKey: 'projects.name', sortable: true },
    { key: 'client', labelKey: 'projects.client' },
    { key: 'status', labelKey: 'projects.status', sortable: true },
    { key: 'priority', labelKey: 'projects.priority', sortable: true },
    { key: 'workers', labelKey: 'projects.workers', align: 'end' },
    { key: 'budget', labelKey: 'projects.budget', align: 'end', sortable: true },
];
</script>

<template>
    <Head title="Proyectos" />
    <AppLayout>
        <VPageHeader k="projects.title">
            <div class="flex overflow-hidden rounded-md border border-line">
                <button type="button" class="px-3 py-1.5 text-xs font-medium" :class="view === 'table' ? 'bg-accent text-on-accent' : 'text-ink-soft hover:bg-surface-hover'" @click="setView('table')">
                    <Bilingual k="projects.view_table" inline />
                </button>
                <button type="button" class="px-3 py-1.5 text-xs font-medium" :class="view === 'kanban' ? 'bg-accent text-on-accent' : 'text-ink-soft hover:bg-surface-hover'" @click="setView('kanban')">
                    <Bilingual k="projects.view_kanban" inline />
                </button>
            </div>
            <VButton v-if="can.create" icon="plus" @click="showForm = true"><Bilingual k="projects.new" inline /></VButton>
        </VPageHeader>

        <div class="flex flex-wrap items-end gap-2 pb-3">
            <div class="w-full sm:w-56"><VSearchInput v-model="filters.search" /></div>
            <VSelect v-model="filters.client_id" class="w-full sm:w-56" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.projects.client }}</option>
                <option v-for="c in filterOptions.clients" :key="c.id" :value="c.id">{{ c.name }}</option>
            </VSelect>
            <VSelect v-if="view === 'table'" v-model="filters.status" class="w-full sm:w-52" @update:model-value="apply()">
                <option value="">{{ $page.props.lang.es.projects.status }}</option>
                <option v-for="s in filterOptions.statuses" :key="s" :value="s">{{ $page.props.lang.es.projects[`status_${s}`] }}</option>
            </VSelect>
        </div>

        <!-- Table view -->
        <template v-if="view === 'table' && projects">
            <VTable :columns="columns" :sort="{ key: filters.sort, dir: filters.dir }" @sort="sortBy">
                <tr v-for="p in projects.data" :key="p.id" class="cursor-pointer hover:bg-surface-hover" @click="router.get(`/projects/${p.id}`)">
                    <td class="tabular-nums px-3 py-2.5 text-sm">{{ p.code }}</td>
                    <td class="px-3 py-2.5 text-sm font-medium">{{ p.name }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ p.client ?? '—' }}</td>
                    <td class="px-3 py-2.5"><VBadge :status="statusBadge[p.status]"><Bilingual :k="`projects.status_${p.status}`" inline /></VBadge></td>
                    <td class="px-3 py-2.5"><VBadge :status="priorityBadge[p.priority]"><Bilingual :k="`projects.priority_${p.priority}`" inline /></VBadge></td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ p.workers }}</td>
                    <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ p.budget ?? '—' }}</td>
                </tr>
                <template v-if="projects.data.length === 0" #empty><VEmptyState icon="projects" /></template>
            </VTable>
            <VPagination :page="projects.current_page" :pages="projects.last_page" :per-page="filters.per_page"
                :total="projects.total" @update:page="(p) => apply({ page: p })" @update:per-page="(pp) => { filters.per_page = pp; apply(); }" />
        </template>

        <!-- Kanban view -->
        <div v-else-if="view === 'kanban' && kanban" class="grid gap-3 overflow-x-auto md:grid-cols-5">
            <div v-for="col in kanbanColumns" :key="col" class="min-w-56 rounded-lg bg-surface-sunken/60 p-2">
                <div class="mb-2 flex items-center justify-between px-1">
                    <Bilingual :k="`projects.status_${col}`" class="text-xs font-semibold text-ink-soft" />
                    <span class="tabular-nums rounded-sm bg-surface-raised px-1.5 text-[11px] text-muted">{{ kanban[col]?.length ?? 0 }}</span>
                </div>
                <div class="space-y-2">
                    <button v-for="p in kanban[col]" :key="p.id" type="button"
                        class="w-full rounded-md border border-line bg-surface-raised p-2.5 text-start shadow-card hover:border-line-strong"
                        @click="router.get(`/projects/${p.id}`)">
                        <p class="text-sm font-medium">{{ p.name }}</p>
                        <p class="text-xs text-muted">{{ p.client ?? '—' }}</p>
                        <div class="mt-1.5 flex items-center justify-between">
                            <VBadge :status="priorityBadge[p.priority]"><Bilingual :k="`projects.priority_${p.priority}`" inline /></VBadge>
                            <span class="tabular-nums text-[11px] text-muted">{{ p.workers }} 👷</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <ProjectFormModal :open="showForm" :clients="filterOptions.clients" :vat-options="vatOptions" @close="showForm = false" />
    </AppLayout>
</template>
