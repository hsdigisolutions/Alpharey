<script setup>
/**
 * Global Document Command Center (/documents) — five views over one cached
 * dataset: Urgent, All, By Entity, Timeline, Compliance Dashboard. Missing docs
 * are computed server-side; vehicles appear as read-only pseudo-documents. All
 * actions (View / Renovar / Subir / bulk exempt / export) happen inline.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { tPair } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import DocumentDetailPanel from '@/Components/Documents/DocumentDetailPanel.vue';
import DocumentFieldForm from '@/Components/Documents/DocumentFieldForm.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VInput from '@/Components/ui/VInput.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VTabs from '@/Components/ui/VTabs.vue';

const props = defineProps({
    summary: { type: Object, required: true },
    urgent: { type: Object, required: true },
    byEntity: { type: Object, required: true },
    timeline: { type: Array, required: true },
    dashboard: { type: Object, required: true },
    allPage: { type: Object, required: true },
    filters: { type: Object, required: true },
    fieldDefs: { type: Object, required: true },
    companyOptions: { type: Array, default: () => [] },
    entityTypes: { type: Array, required: true },
    statuses: { type: Array, required: true },
    can: { type: Object, required: true },
});

const tab = ref('urgent');
const statusBadge = { ok: 'ok', warn: 'warn', danger: 'danger', neutral: 'neutral', exempt: 'info', missing: 'neutral' };

function statusLabel(status) {
    return status === 'missing' ? tPair('doc_center.status_missing') : tPair(`documents.status_${status}`);
}
function typeLabel(row) {
    return row.is_vehicle ? tPair(`vehicles.${row.type_key}`) : tPair(`doc_types.${row.type_key}`);
}
function entityLabel(type) {
    return tPair(`doc_center.entity_${type}`);
}

/* ---------- filters + pagination (server-side) ---------- */
const filterForm = reactive({ ...props.filters });

function reload(extra = {}) {
    router.get('/documents', { ...filterForm, ...extra }, { preserveState: true, preserveScroll: true });
}
function applyFilters() {
    reload({ page: 1 });
}
function resetFilters() {
    Object.keys(filterForm).forEach((k) => (filterForm[k] = ''));
    reload({ page: 1 });
}

const typeOptions = computed(() => {
    const map = filterForm.category && props.fieldDefs[filterForm.category] ? props.fieldDefs[filterForm.category] : {};
    return Object.keys(map);
});

/* ---------- inline View (fetch panel on demand) ---------- */
const viewDoc = ref(null);
const viewRow = ref(null);
const viewError = ref(null);

async function openView(row) {
    if (!row.document_id) {
        return;
    }
    viewError.value = null;
    viewRow.value = row;
    const res = await fetch(`/documents/${row.document_id}/panel`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    // A 403/404 returns non-JSON (or an error payload) — surface it instead of
    // parsing blindly, which would leave the panel silently unopened.
    if (!res.ok) {
        viewRow.value = null;
        viewError.value = tPair('doc_center.panel_error');
        return;
    }
    viewDoc.value = (await res.json()).doc;
}

/* ---------- inline upload (Subir / Renovar) ---------- */
const uploadTarget = ref(null); // the row being uploaded for
const file = ref(null);
const uploadForm = useForm({
    entity_type: '', entity_id: null, type_key: '', category: '',
    issue_date: null, expiry_date: null, notes: '', metadata: {}, contacts: [], file: null,
});

const uploadFields = computed(() => {
    if (!uploadTarget.value) {
        return [];
    }
    return props.fieldDefs[uploadTarget.value.entity_type]?.[uploadTarget.value.type_key]?.fields ?? [];
});

function openUpload(row) {
    if (row.is_vehicle) {
        router.visit(`/vehicles/${row.entity_id}`);
        return;
    }
    uploadTarget.value = row;
    uploadForm.clearErrors();
    uploadForm.entity_type = row.entity_type;
    uploadForm.entity_id = row.entity_id;
    uploadForm.type_key = row.type_key;
    uploadForm.category = row.category;
    uploadForm.issue_date = null;
    uploadForm.expiry_date = null;
    uploadForm.notes = '';
    uploadForm.metadata = {};
    uploadForm.contacts = [];
    uploadForm.file = null;
    file.value = null;
}
function pickFile(files) {
    file.value = files[0];
    uploadForm.file = files[0];
}
function submitUpload() {
    uploadForm.post('/documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadTarget.value = null;
            router.reload({ preserveScroll: true });
        },
    });
}
// New version from the detail panel → open the upload modal for the viewed row.
function newVersionFromPanel() {
    const row = viewRow.value;
    viewDoc.value = null;
    if (row) {
        openUpload(row);
    }
}

/* ---------- bulk actions ---------- */
const selected = ref(new Set());
function toggleSelect(row) {
    if (!row.document_id) {
        return;
    }
    const next = new Set(selected.value);
    next.has(row.document_id) ? next.delete(row.document_id) : next.add(row.document_id);
    selected.value = next;
}
function selectAllVisible() {
    const ids = props.allPage.data.filter((r) => r.document_id).map((r) => r.document_id);
    const all = ids.every((id) => selected.value.has(id));
    selected.value = all ? new Set() : new Set(ids);
}
function bulkExempt(exempt) {
    router.post('/documents/bulk-exempt', { ids: [...selected.value], exempt }, {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = new Set();
            router.reload({ preserveScroll: true });
        },
    });
}

function exportView(format) {
    const params = new URLSearchParams({ ...filterForm, format }).toString();
    window.location.href = `/documents/export?${params}`;
}

const urgentGroups = computed(() => [
    { key: 'expired', rows: props.urgent.expired, status: 'danger', total: props.urgent.expired.length },
    { key: 'week', rows: props.urgent.week, status: 'warn', total: props.urgent.week.length },
    { key: 'month', rows: props.urgent.month, status: 'warn', total: props.urgent.month.length },
    // The missing bucket is capped for payload size; show the true total.
    { key: 'missing', rows: props.urgent.missing, status: 'neutral', total: props.urgent.missing_total ?? props.urgent.missing.length },
]);

const entityGroups = computed(() => props.entityTypes.filter((t) => props.byEntity[t]?.length));

// Clicking a timeline week drills into the All view filtered to that week.
function openWeek(w) {
    filterForm.from = w.from;
    filterForm.to = w.to;
    tab.value = 'all';
    reload({ page: 1 });
}

function entityHref(row) {
    return {
        company: '/companies', employee: `/employees/${row.entity_id}`, project: `/projects/${row.entity_id}`,
        client: `/clients/${row.entity_id}`, vendor: `/vendors/${row.entity_id}`, vehicle: `/vehicles/${row.entity_id}`,
    }[row.entity_type] ?? '/documents';
}
</script>

<template>
    <Head :title="$t('doc_center.title')" />

    <AppLayout>
        <VPageHeader k="doc_center.title">
            <div v-if="can.export" class="flex gap-2">
                <VButton variant="secondary" size="sm" icon="export" @click="exportView('excel')">
                    <Bilingual k="doc_center.export_excel" inline />
                </VButton>
                <VButton variant="secondary" size="sm" icon="export" @click="exportView('pdf')">
                    <Bilingual k="doc_center.export_pdf" inline />
                </VButton>
            </div>
        </VPageHeader>

        <!-- Summary cards -->
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-5">
            <VKpiCard k="doc_center.total_docs" :value="summary.total" icon="file" />
            <VKpiCard k="doc_center.valid" :value="summary.ok" icon="check" />
            <VKpiCard k="doc_center.expiring" :value="summary.warn" icon="alert" />
            <VKpiCard k="doc_center.expired" :value="summary.danger" icon="alert" />
            <VKpiCard k="doc_center.status_missing" :value="summary.missing" icon="file" />
        </div>

        <VTabs v-model="tab" :tabs="[
            { key: 'urgent', labelKey: 'doc_center.tab_urgent' },
            { key: 'all', labelKey: 'doc_center.tab_all' },
            { key: 'entity', labelKey: 'doc_center.tab_entity' },
            { key: 'timeline', labelKey: 'doc_center.tab_timeline' },
            { key: 'dashboard', labelKey: 'doc_center.tab_dashboard' },
        ]" />

        <!-- ══ View 1 — Urgent ══ -->
        <div v-if="tab === 'urgent'" class="mt-4 space-y-5">
            <p v-if="!urgentGroups.some((g) => g.rows.length)" class="rounded-lg border border-line bg-surface-raised p-6 text-center text-sm text-ink-soft">
                <Bilingual k="doc_center.all_good" />
            </p>
            <section v-for="group in urgentGroups" v-show="group.rows.length" :key="group.key">
                <div class="mb-2 flex items-center gap-2">
                    <VBadge :status="group.status"><Bilingual :k="`doc_center.${group.key}`" inline /></VBadge>
                    <span class="tabular-nums text-sm text-muted">{{ group.total }}</span>
                </div>
                <div class="overflow-hidden rounded-lg border border-line bg-surface-raised">
                    <div v-for="row in group.rows" :key="row.key"
                        class="flex items-center gap-3 border-b border-line px-3 py-2.5 last:border-0 hover:bg-surface-hover">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ typeLabel(row) }}</p>
                            <p class="truncate text-xs text-muted">
                                {{ row.entity_name }} · {{ entityLabel(row.entity_type) }}
                                <span v-if="row.company_name">· {{ row.company_name }}</span>
                            </p>
                        </div>
                        <span class="tabular-nums shrink-0 text-xs" :class="row.status === 'danger' ? 'text-status-danger' : 'text-ink-soft'">
                            <template v-if="row.is_missing"><Bilingual k="doc_center.status_missing" inline /></template>
                            <template v-else-if="row.days_left < 0">{{ -row.days_left }}d</template>
                            <template v-else>{{ row.days_left }}d</template>
                        </span>
                        <div class="flex shrink-0 gap-1">
                            <VButton v-if="row.is_vehicle" variant="ghost" size="sm" @click="router.visit(entityHref(row))">
                                <Bilingual k="doc_center.go" inline />
                            </VButton>
                            <template v-else>
                                <VButton v-if="row.document_id && can.view" variant="ghost" size="sm" @click="openView(row)">
                                    <Bilingual k="doc_center.view" inline />
                                </VButton>
                                <VButton v-if="can.upload" variant="secondary" size="sm" @click="openUpload(row)">
                                    <Bilingual :k="row.is_missing ? 'doc_center.upload' : 'doc_center.renew'" inline />
                                </VButton>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ══ View 2 — All Documents ══ -->
        <div v-else-if="tab === 'all'" class="mt-4 space-y-3">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <VInput v-model="filterForm.search" :placeholder="$t('doc_center.filter_search')" class="col-span-2 lg:col-span-2" @keyup.enter="applyFilters" />
                <VSelect v-model="filterForm.category" @update:model-value="applyFilters">
                    <option value=""><Bilingual k="doc_center.filter_category" inline /></option>
                    <option v-for="t in entityTypes" :key="t" :value="t">{{ entityLabel(t) }}</option>
                </VSelect>
                <VSelect v-model="filterForm.type_key" @update:model-value="applyFilters">
                    <option value="">{{ $tPair('doc_center.filter_type') }}</option>
                    <option v-for="t in typeOptions" :key="t" :value="t">{{ $tPair(`doc_types.${t}`) }}</option>
                </VSelect>
                <VSelect v-model="filterForm.status" @update:model-value="applyFilters">
                    <option value="">{{ $tPair('doc_center.filter_status') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
                </VSelect>
                <VSelect v-if="companyOptions.length" v-model="filterForm.company_id" @update:model-value="applyFilters">
                    <option value="">{{ $tPair('doc_center.filter_company') }}</option>
                    <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                </VSelect>
                <VDateInput v-model="filterForm.from" @update:model-value="applyFilters" />
                <VDateInput v-model="filterForm.to" @update:model-value="applyFilters" />
                <VButton variant="ghost" size="sm" @click="resetFilters"><Bilingual k="common.cancel" inline /></VButton>
            </div>

            <!-- Bulk bar -->
            <div v-if="selected.size" class="flex items-center gap-3 rounded-lg border border-accent bg-accent-soft px-3 py-2 text-sm">
                <span class="tabular-nums">{{ selected.size }} <Bilingual k="doc_center.selected" inline /></span>
                <VButton v-if="can.exempt" variant="secondary" size="sm" @click="bulkExempt(true)"><Bilingual k="doc_center.mark_exempt" inline /></VButton>
                <VButton v-if="can.exempt" variant="ghost" size="sm" @click="bulkExempt(false)"><Bilingual k="doc_center.unmark_exempt" inline /></VButton>
            </div>

            <div class="overflow-x-auto rounded-lg border border-line bg-surface-raised">
                <table class="w-full min-w-max text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] uppercase tracking-wide text-muted">
                            <th class="w-8 px-3 py-2"><input type="checkbox" :checked="allPage.data.some((r) => r.document_id) && allPage.data.filter((r) => r.document_id).every((r) => selected.has(r.document_id))" @change="selectAllVisible"></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_status" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_type" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_entity" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_category" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_company" inline /></th>
                            <th class="px-3 py-2 text-start"><Bilingual k="doc_center.col_expiry" inline /></th>
                            <th class="px-3 py-2 text-end"><Bilingual k="doc_center.col_actions" inline /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in allPage.data" :key="row.key" class="hover:bg-surface-hover">
                            <td class="px-3 py-2">
                                <input v-if="row.document_id" type="checkbox" :checked="selected.has(row.document_id)" @change="toggleSelect(row)">
                            </td>
                            <td class="px-3 py-2"><VBadge :status="statusBadge[row.status]">{{ statusLabel(row.status) }}</VBadge></td>
                            <td class="px-3 py-2">{{ typeLabel(row) }}</td>
                            <td class="px-3 py-2">{{ row.entity_name ?? '—' }} <span class="text-xs text-muted">· {{ entityLabel(row.entity_type) }}</span></td>
                            <td class="px-3 py-2 text-xs text-muted">{{ row.category }}</td>
                            <td class="px-3 py-2 text-xs">{{ row.company_name ?? '—' }}</td>
                            <td class="tabular-nums px-3 py-2 text-xs text-muted">{{ row.expiry_date ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1">
                                    <VButton v-if="row.is_vehicle" variant="ghost" size="sm" @click="router.visit(entityHref(row))"><Bilingual k="doc_center.go" inline /></VButton>
                                    <template v-else>
                                        <VButton v-if="row.document_id && can.view" variant="ghost" size="sm" @click="openView(row)"><Bilingual k="doc_center.view" inline /></VButton>
                                        <VButton v-if="can.upload" variant="ghost" size="sm" @click="openUpload(row)"><Bilingual :k="row.is_missing ? 'doc_center.upload' : 'doc_center.renew'" inline /></VButton>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!allPage.data.length"><td colspan="8" class="px-3 py-8 text-center text-sm text-muted"><Bilingual k="doc_center.no_results" /></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between text-sm text-ink-soft">
                <span class="tabular-nums">{{ allPage.total }}</span>
                <div class="flex items-center gap-2">
                    <VButton variant="ghost" size="sm" :disabled="allPage.current_page <= 1" @click="reload({ page: allPage.current_page - 1 })">‹</VButton>
                    <span class="tabular-nums text-xs">{{ allPage.current_page }} / {{ allPage.last_page }}</span>
                    <VButton variant="ghost" size="sm" :disabled="allPage.current_page >= allPage.last_page" @click="reload({ page: allPage.current_page + 1 })">›</VButton>
                </div>
            </div>
        </div>

        <!-- ══ View 3 — By Entity ══ -->
        <div v-else-if="tab === 'entity'" class="mt-4 space-y-5">
            <section v-for="type in entityGroups" :key="type">
                <p class="mb-2 text-[15px] font-semibold">{{ entityLabel(type) }}</p>
                <div class="overflow-hidden rounded-lg border border-line bg-surface-raised">
                    <div v-for="e in byEntity[type]" :key="e.entity_id"
                        class="flex items-center gap-3 border-b border-line px-3 py-2.5 last:border-0">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ e.entity_name ?? '—' }}</p>
                            <p class="text-xs text-muted">
                                {{ $t('doc_center.n_missing', { n: e.missing }) }} · {{ $t('doc_center.n_expiring', { n: e.expiring }) }}
                            </p>
                        </div>
                        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-surface-sunken">
                            <div class="h-full rounded-full" :class="e.percent >= 80 ? 'bg-status-ok' : e.percent >= 50 ? 'bg-status-warn' : 'bg-status-danger'" :style="{ width: `${e.percent}%` }" />
                        </div>
                        <span class="tabular-nums w-10 text-end text-sm font-medium">{{ e.percent }}%</span>
                        <VButton variant="ghost" size="sm" @click="router.visit(entityHref(e))"><Bilingual k="doc_center.go" inline /></VButton>
                    </div>
                </div>
            </section>
        </div>

        <!-- ══ View 4 — Timeline ══ -->
        <div v-else-if="tab === 'timeline'" class="mt-4 space-y-5">
            <p v-if="!timeline.length" class="text-sm text-muted"><Bilingual k="doc_center.no_results" /></p>
            <section v-for="m in timeline" :key="m.month">
                <p class="mb-2 text-[15px] font-semibold tabular-nums">{{ m.month }}</p>
                <div class="overflow-hidden rounded-lg border border-line bg-surface-raised">
                    <button v-for="w in m.weeks" :key="w.from" type="button"
                        class="flex w-full items-center justify-between border-b border-line px-3 py-2.5 text-start last:border-0 hover:bg-surface-hover"
                        @click="openWeek(w)">
                        <span class="text-sm">{{ w.label }}</span>
                        <span class="tabular-nums text-sm" :class="w.count >= 5 ? 'font-semibold text-status-danger' : 'text-ink-soft'">
                            {{ w.count }} <Bilingual k="doc_center.due_this_week" inline />
                        </span>
                    </button>
                </div>
            </section>
        </div>

        <!-- ══ View 5 — Compliance Dashboard ══ -->
        <div v-else class="mt-4 grid gap-5 lg:grid-cols-2">
            <VCard>
                <p class="mb-3 text-[15px] font-semibold"><Bilingual k="doc_center.company_scores" /></p>
                <div v-for="c in dashboard.companyScores" :key="c.company_id" class="flex items-center gap-3 border-b border-line py-2 last:border-0">
                    <span class="min-w-0 flex-1 truncate text-sm">{{ c.name }}</span>
                    <div class="h-1.5 w-28 overflow-hidden rounded-full bg-surface-sunken">
                        <div class="h-full rounded-full" :class="c.percent >= 80 ? 'bg-status-ok' : c.percent >= 50 ? 'bg-status-warn' : 'bg-status-danger'" :style="{ width: `${c.percent}%` }" />
                    </div>
                    <span class="tabular-nums w-10 text-end text-sm font-medium">{{ c.percent }}%</span>
                </div>
                <p v-if="!dashboard.companyScores.length" class="text-sm text-muted">—</p>
            </VCard>

            <VCard>
                <p class="mb-3 text-[15px] font-semibold"><Bilingual k="doc_center.category_breakdown" /></p>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[11px] uppercase tracking-wide text-muted">
                            <th class="py-1.5 text-start"><Bilingual k="doc_center.col_category" inline /></th>
                            <th class="py-1.5 text-end"><Bilingual k="doc_center.total_docs" inline /></th>
                            <th class="py-1.5 text-end"><Bilingual k="doc_center.valid" inline /></th>
                            <th class="py-1.5 text-end"><Bilingual k="doc_center.expired" inline /></th>
                            <th class="py-1.5 text-end"><Bilingual k="doc_center.status_missing" inline /></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in dashboard.categoryBreakdown" :key="c.category" class="border-t border-line">
                            <td class="py-1.5">{{ entityLabel(c.category) }}</td>
                            <td class="tabular-nums py-1.5 text-end">{{ c.total }}</td>
                            <td class="tabular-nums py-1.5 text-end text-status-ok">{{ c.ok }}</td>
                            <td class="tabular-nums py-1.5 text-end text-status-danger">{{ c.danger }}</td>
                            <td class="tabular-nums py-1.5 text-end text-muted">{{ c.missing }}</td>
                        </tr>
                    </tbody>
                </table>
            </VCard>
        </div>

        <!-- Panel fetch error (e.g. a 403 opening a document) — click to dismiss -->
        <div v-if="viewError" class="fixed inset-x-0 bottom-4 z-50 mx-auto w-full max-w-md px-4">
            <VAlert status="danger" class="cursor-pointer shadow-overlay" @click="viewError = null">{{ viewError }}</VAlert>
        </div>

        <!-- Inline View slide-over -->
        <DocumentDetailPanel :doc="viewDoc" :can="can"
            @close="viewDoc = null" @new-version="newVersionFromPanel" @refresh="router.reload({ preserveScroll: true })" />

        <!-- Inline upload (Subir / Renovar) -->
        <VModal :open="uploadTarget !== null" title-key="documents.upload" size="lg" @close="uploadTarget = null">
            <form v-if="uploadTarget" id="center-upload" class="space-y-3" @submit.prevent="submitUpload">
                <p class="text-sm font-medium">
                    <Bilingual :k="`doc_types.${uploadTarget.type_key}`" /> · {{ uploadTarget.entity_name }}
                </p>
                <VFileDrop capture accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" @files="pickFile" />
                <p v-if="file" class="truncate text-xs text-ink-soft">{{ file.name }}</p>
                <p v-if="uploadForm.errors.file" class="text-xs text-status-danger">{{ uploadForm.errors.file }}</p>
                <DocumentFieldForm :fields="uploadFields" :form="uploadForm" :dates-editable="true" />
            </form>
            <template #footer>
                <VButton variant="ghost" @click="uploadTarget = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="center-upload" :loading="uploadForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
