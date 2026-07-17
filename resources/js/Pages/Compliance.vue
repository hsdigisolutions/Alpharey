<script setup>
/**
 * Screen 16 — Compliance Center: traffic-light rollup across all tracked
 * documents. Summary cards, per-company score bars, filterable detail
 * table with mark-exempt action.
 */
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VStatBar from '@/Components/ui/VStatBar.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    summary: { type: Object, required: true },
    companyScores: { type: Array, required: true },
    rows: { type: Array, required: true },
    canExempt: { type: Boolean, required: true },
});

const filters = reactive({ status: '', category: '', company: '' });

const statusBadge = { ok: 'ok', warn: 'warn', danger: 'danger', neutral: 'neutral', exempt: 'info' };
const rank = { danger: 3, warn: 2, neutral: 1, ok: 0, exempt: 0 };

const filtered = computed(() =>
    props.rows
        .filter((r) => !filters.status || r.status === filters.status)
        .filter((r) => !filters.category || r.category === filters.category)
        .filter((r) => !filters.company || r.company === filters.company)
        .slice()
        .sort((a, b) => (rank[b.status] ?? 0) - (rank[a.status] ?? 0)),
);

const summaryCards = [
    { key: 'ok', labelKey: 'compliance.valid', status: 'ok' },
    { key: 'warn', labelKey: 'compliance.expiring', status: 'warn' },
    { key: 'danger', labelKey: 'compliance.expired', status: 'danger' },
    { key: 'neutral', labelKey: 'compliance.missing', status: 'neutral' },
    { key: 'exempt', labelKey: 'compliance.exempt', status: 'info' },
];

const companies = computed(() => [...new Set(props.rows.map((r) => r.company).filter(Boolean))]);

const columns = [
    { key: 'entity', labelKey: 'compliance.entity' },
    { key: 'company', labelKey: 'employees.company' },
    { key: 'type', labelKey: 'compliance.type' },
    { key: 'expiry', labelKey: 'documents.expiry_date' },
    { key: 'status', labelKey: 'documents.status' },
    { key: 'actions', labelKey: 'common.actions' },
];

function markExempt(row) {
    router.post(`/documents/${row.id}/exempt`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('compliance.title')" />

    <AppLayout>
        <VPageHeader k="compliance.title" />

        <!-- Summary cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div v-for="card in summaryCards" :key="card.key"
                class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full" :class="{
                        'bg-status-ok': card.status === 'ok',
                        'bg-status-warn': card.status === 'warn',
                        'bg-status-danger': card.status === 'danger',
                        'bg-status-neutral': card.status === 'neutral',
                        'bg-status-info': card.status === 'info',
                    }" />
                    <Bilingual :k="card.labelKey" class="text-xs text-ink-soft" />
                </div>
                <p class="tabular-nums mt-2 text-2xl font-semibold">{{ summary[card.key] }}</p>
            </div>
        </div>

        <!-- Per-company scores -->
        <VCard v-if="companyScores.length" title-key="compliance.per_company" class="mt-5">
            <div class="space-y-2.5">
                <VStatBar v-for="score in companyScores" :key="score.company_id"
                    :label="score.name" :sublabel="score.province" :percent="score.percent" />
            </div>
        </VCard>

        <!-- Filters -->
        <div class="mt-5 flex flex-wrap gap-2">
            <VSelect v-model="filters.status" class="w-full sm:w-52">
                <option value="">{{ $tPair('compliance.filter_status') }}</option>
                <option v-for="s in ['ok', 'warn', 'danger', 'neutral', 'exempt']" :key="s" :value="s">
                    {{ $page.props.lang.es[`documents`][`status_${s}`] }}
                </option>
            </VSelect>
            <VSelect v-if="companies.length > 1" v-model="filters.company" class="w-44">
                <option value="">{{ $t('employees.company') }}</option>
                <option v-for="c in companies" :key="c" :value="c">{{ c }}</option>
            </VSelect>
        </div>

        <!-- Detail table -->
        <div class="mt-3">
            <VTable :columns="columns">
                <tr v-for="row in filtered" :key="row.id" class="hover:bg-surface-hover">
                    <td class="px-3 py-2.5 text-sm font-medium">{{ row.entity_name ?? '—' }}</td>
                    <td class="px-3 py-2.5 text-sm text-ink-soft">{{ row.company ?? '—' }}</td>
                    <td class="px-3 py-2.5">
                        <Bilingual :k="`doc_types.${row.type_key}`" class="text-sm" />
                    </td>
                    <td class="tabular-nums px-3 py-2.5 text-xs text-muted">
                        {{ row.expiry_date ?? '—' }}
                        <span v-if="row.days_left !== null"> ({{ row.days_left }}d)</span>
                    </td>
                    <td class="px-3 py-2.5">
                        <VBadge :status="statusBadge[row.status]">
                            <Bilingual :k="`documents.status_${row.status}`" inline />
                        </VBadge>
                    </td>
                    <td class="px-3 py-2.5 text-end">
                        <VButton v-if="canExempt && !row.is_exempt" variant="ghost" size="sm" @click="markExempt(row)">
                            <Bilingual k="documents.mark_exempt" inline />
                        </VButton>
                    </td>
                </tr>
                <template v-if="filtered.length === 0" #empty>
                    <VEmptyState icon="check" />
                </template>
            </VTable>
        </div>
    </AppLayout>
</template>
