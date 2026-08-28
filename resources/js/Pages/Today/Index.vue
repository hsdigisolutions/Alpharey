<script setup>
/**
 * Screen 15 — Today's Report. 6 KPI cards, today's live attendance table
 * (with a home-company column for workers deployed in), and the four
 * pending-action lists. Reloads itself every 5 minutes.
 */
import { onMounted, onBeforeUnmount, reactive, computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VCard from '@/Components/ui/VCard.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';

const props = defineProps({
    data: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const REFRESH_MS = 60 * 1000; // auto-refresh every 60 seconds
let timer = null;

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const statusBadge = { present: 'ok', late: 'warn', early_leave: 'warn', absent: 'danger', leave: 'info' };

function statusLabel(s) {
    return t(`today.status_${s}`);
}

/* ── Filters (server-side, preserved across auto-refresh) ── */
const STATUS_ORDER = ['present', 'late', 'early_leave', 'leave', 'absent'];

// "Last activity" label for the no-activity section: Never / Yesterday / N days ago.
function lastActivityLabel(p) {
    if (p.last_activity == null) return t('today.na_never');
    const d = p.days_ago ?? 0;
    if (d <= 1) return t('today.na_yesterday');
    return t('today.na_days_ago', { count: d });
}

function todayIso() { return new Date().toISOString().slice(0, 10); }
function isoDaysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d.toISOString().slice(0, 10); }

const filters = reactive({
    search: props.data.filters?.search ?? '',
    project: props.data.filters?.project ?? '',
    statuses: [...(props.data.filters?.statuses ?? [])],
    from: props.data.filters?.from ?? todayIso(),
    to: props.data.filters?.to ?? todayIso(),
});

function computePreset() {
    const f = filters.from, t = filters.to;
    if (f === todayIso() && t === todayIso()) return 'today';
    if (f === isoDaysAgo(1) && t === isoDaysAgo(1)) return 'yesterday';
    if (f === isoDaysAgo(6) && t === todayIso()) return 'last7';
    if (f === isoDaysAgo(29) && t === todayIso()) return 'last30';
    return 'custom';
}
const datePreset = ref(computePreset());

function setPreset(p) {
    datePreset.value = p;
    if (p === 'today') { filters.from = todayIso(); filters.to = todayIso(); }
    else if (p === 'yesterday') { filters.from = isoDaysAgo(1); filters.to = isoDaysAgo(1); }
    else if (p === 'last7') { filters.from = isoDaysAgo(6); filters.to = todayIso(); }
    else if (p === 'last30') { filters.from = isoDaysAgo(29); filters.to = todayIso(); }
    if (p !== 'custom') applyFilters();
}

function toggleStatus(s) {
    const i = filters.statuses.indexOf(s);
    if (i >= 0) filters.statuses.splice(i, 1); else filters.statuses.push(s);
    applyFilters();
}

const hasFilters = computed(() => filters.search !== '' || filters.project !== '' || filters.statuses.length > 0 || computePreset() !== 'today');

// Attendance grouped into status sections (Present, Late, Early leave, Leave, Absent).
const groupedAttendance = computed(() => {
    const byStatus = {};
    for (const r of props.data.attendance) (byStatus[r.status] ??= []).push(r);
    return STATUS_ORDER.filter((s) => byStatus[s]?.length).map((s) => ({ status: s, rows: byStatus[s] }));
});

let searchDebounce = null;

function applyFilters() {
    router.get('/today', {
        search: filters.search || undefined,
        project: filters.project || undefined,
        statuses: filters.statuses.length ? filters.statuses : undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true, only: ['data'] });
}

function onSearch(v) {
    filters.search = v;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 350);
}

function clearFilters() {
    filters.search = '';
    filters.project = '';
    filters.statuses = [];
    setPreset('today');
}

// Export the current filtered view (Excel or PDF).
function exportToday(format) {
    const params = new URLSearchParams();
    if (filters.search) params.append('search', filters.search);
    if (filters.project) params.append('project', filters.project);
    filters.statuses.forEach((s) => params.append('statuses[]', s));
    if (filters.from) params.append('from', filters.from);
    if (filters.to) params.append('to', filters.to);
    params.append('format', format);
    window.location.href = `/today/export?${params.toString()}`;
}

onMounted(() => {
    timer = setInterval(() => {
        // Partial reload of just the data prop — keeps scroll + the active
        // filters (they live in the URL query) and is cheap.
        router.reload({ only: ['data'] });
    }, REFRESH_MS);
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
    clearTimeout(searchDebounce);
});
</script>

<template>
    <Head :title="$t('today.title')" />

    <AppLayout>
        <VPageHeader k="today.title" />

        <p class="mb-3 text-xs text-muted">
            {{ $t('today.auto_refresh') }} · {{ $t('today.updated') }} {{ data.generated_at?.slice(11, 16) }}
        </p>

        <!-- Date range -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-md border border-line-strong bg-surface-raised p-0.5">
                <button v-for="p in ['today', 'yesterday', 'last7', 'last30', 'custom']" :key="p" type="button"
                    class="rounded px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="datePreset === p ? 'bg-accent text-on-accent' : 'text-ink-soft hover:text-ink'"
                    @click="setPreset(p)">{{ $t(`today.date_${p}`) }}</button>
            </div>
            <template v-if="datePreset === 'custom'">
                <input v-model="filters.from" type="date"
                    class="rounded-md border border-line-strong bg-surface-sunken px-2 py-1 text-sm text-ink" @change="applyFilters" />
                <span class="text-muted">→</span>
                <input v-model="filters.to" type="date"
                    class="rounded-md border border-line-strong bg-surface-sunken px-2 py-1 text-sm text-ink" @change="applyFilters" />
            </template>
        </div>

        <!-- KPI cards -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4 xl:grid-cols-7">
            <VKpiCard k="today.total_workers" icon="employees" :value="data.kpis.total_workers" />
            <VKpiCard k="today.active_today" icon="attendance" :value="data.kpis.active_today" status="ok" />
            <VKpiCard k="today.checked_in_now" icon="attendance" :value="data.kpis.checked_in_now"
                :status="data.kpis.checked_in_now > 0 ? 'info' : null" />
            <VKpiCard k="today.absent_today" icon="attendance" :value="data.kpis.absent_today"
                :status="data.kpis.absent_today > 0 ? 'danger' : null" />
            <VKpiCard k="today.on_leave_today" icon="attendance" :value="data.kpis.on_leave_today"
                :status="data.kpis.on_leave_today > 0 ? 'warn' : null" />
            <VKpiCard k="today.hours_today" icon="attendance" :value="data.kpis.hours_today" />
            <VKpiCard k="today.pending_calls" icon="calls" :value="data.kpis.pending_calls"
                :status="data.kpis.pending_calls > 0 ? 'warn' : null" />
        </div>

        <!-- Project breakdown -->
        <VCard v-if="data.project_breakdown && data.project_breakdown.length" class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="today.project_breakdown" inline /></h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.project') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.pb_assigned') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.pb_present') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.pb_absent') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.pb_hours') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(pb, i) in data.project_breakdown" :key="i" class="border-b border-line">
                            <td class="px-2 py-2">{{ pb.project ?? $t('today.no_project') }}</td>
                            <td class="tabular-nums px-2 py-2 text-end">{{ pb.assigned }}</td>
                            <td class="tabular-nums px-2 py-2 text-end text-status-ok">{{ pb.present }}</td>
                            <td class="tabular-nums px-2 py-2 text-end" :class="pb.absent > 0 ? 'text-status-danger' : ''">{{ pb.absent }}</td>
                            <td class="tabular-nums px-2 py-2 text-end">{{ pb.hours }}h</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </VCard>

        <!-- Projects with no activity today -->
        <VCard v-if="data.projects_no_activity && data.projects_no_activity.length" class="mt-6">
            <h2 class="mb-1 text-sm font-semibold text-ink"><Bilingual k="today.no_activity_title" inline /></h2>
            <p class="mb-3 text-xs text-muted">{{ $t('today.no_activity_hint') }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.project') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.na_assigned') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.na_last_activity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in data.projects_no_activity" :key="p.project_id" class="border-b border-line">
                            <td class="px-2 py-2 text-ink">{{ p.project }}</td>
                            <td class="tabular-nums px-2 py-2 text-end">{{ p.assigned }}</td>
                            <td class="px-2 py-2 text-end"
                                :class="p.last_activity == null ? 'text-status-danger' : 'text-ink-soft'">
                                {{ lastActivityLabel(p) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </VCard>

        <!-- Attendance table -->
        <VCard class="mt-6">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-ink"><Bilingual k="today.attendance_table" inline /></h2>
                <span class="text-xs text-muted">{{ t('today.showing', { shown: data.attendance.length, total: data.attendance_total }) }}</span>
            </div>

            <!-- Filters -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <VSearchInput :model-value="filters.search" class="w-full sm:w-56"
                    :placeholder="$t('today.filter_search')" @update:model-value="onSearch" />
                <VSelect v-model="filters.project" class="w-full sm:w-52" @update:model-value="applyFilters">
                    <option value="">{{ $t('today.all_projects') }}</option>
                    <option v-for="p in data.filter_options.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                </VSelect>
                <!-- Status multi-select: tick to show only those sections -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <button v-for="s in STATUS_ORDER" :key="s" type="button"
                        class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                        :class="filters.statuses.includes(s) ? 'border-accent bg-accent-soft text-accent' : 'border-line-strong text-ink-soft hover:text-ink'"
                        @click="toggleStatus(s)">
                        {{ statusLabel(s) }}
                    </button>
                </div>
                <VButton v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">
                    <Bilingual k="today.clear_filters" inline />
                </VButton>
                <div class="ms-auto flex items-center gap-2">
                    <VButton variant="secondary" size="sm" icon="download" @click="exportToday('excel')">Excel</VButton>
                    <VButton variant="secondary" size="sm" icon="download" @click="exportToday('pdf')">PDF</VButton>
                </div>
            </div>

            <VEmptyState v-if="data.attendance.length === 0" title-key="today.no_attendance" message-key="today.no_attendance" />

            <!-- Grouped into status sections (Present, Late, Early leave, Leave, Absent) -->
            <div v-for="group in groupedAttendance" :key="group.status" class="mb-5 overflow-x-auto">
                <div class="mb-1.5 flex items-center gap-2">
                    <VBadge :status="statusBadge[group.status] ?? 'neutral'">{{ statusLabel(group.status) }}</VBadge>
                    <span class="text-xs text-muted">{{ group.rows.length }}</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-start text-xs uppercase text-muted">
                            <th v-if="!data.single_day" class="px-2 py-2 text-start font-medium">{{ $t('timesheet.day') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.employee') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.home_company') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.project') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.check_in') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.check_out') }}</th>
                            <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('today.hours') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('today.distance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in group.rows" :key="row.id" class="border-b border-line">
                            <td v-if="!data.single_day" class="tabular-nums px-2 py-2 text-ink-soft">{{ row.date }}</td>
                            <td class="px-2 py-2 text-ink">{{ row.employee }}</td>
                            <td class="px-2 py-2">
                                <VBadge v-if="row.home_company" status="info">{{ row.home_company }}</VBadge>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="px-2 py-2 text-ink-soft">{{ row.project ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.worked ? (row.check_in ?? '—') : '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.worked ? (row.check_out ?? '—') : '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-end text-ink">
                                <template v-if="row.worked">{{ row.hours }}h<span v-if="row.still_working" class="ms-1 text-xs text-status-info">· {{ $t('today.still_working') }}</span></template>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="tabular-nums px-2 py-2 text-end text-ink-soft">{{ row.distance != null ? `${Math.round(row.distance)}m` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </VCard>

        <!-- Pending actions -->
        <h2 class="mb-3 mt-6 text-sm font-semibold text-ink"><Bilingual k="today.pending_actions" inline /></h2>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <VCard>
                <h3 class="mb-2 text-xs font-semibold uppercase text-muted"><Bilingual k="today.documents_expiring" inline /></h3>
                <VEmptyState v-if="data.pending.documents_expiring.length === 0" title-key="today.none" message-key="today.none" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="d in data.pending.documents_expiring" :key="d.id" class="flex justify-between gap-2 py-1.5 text-sm">
                        <span class="truncate text-ink">{{ d.name }}</span>
                        <span class="tabular-nums shrink-0 text-ink-soft">{{ d.expiry_date }}</span>
                    </li>
                </ul>
            </VCard>

            <VCard>
                <h3 class="mb-2 text-xs font-semibold uppercase text-muted"><Bilingual k="today.follow_up_calls" inline /></h3>
                <VEmptyState v-if="data.pending.follow_up_calls.length === 0" title-key="today.none" message-key="today.none" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="c in data.pending.follow_up_calls" :key="c.id" class="flex justify-between gap-2 py-1.5 text-sm">
                        <span class="truncate text-ink">{{ c.employee }}</span>
                        <span class="tabular-nums shrink-0 text-status-warn">{{ c.follow_up_date }}</span>
                    </li>
                </ul>
            </VCard>

            <VCard>
                <h3 class="mb-2 text-xs font-semibold uppercase text-muted"><Bilingual k="today.payroll_approvals" inline /></h3>
                <VEmptyState v-if="data.pending.payroll_approvals.length === 0" title-key="today.none" message-key="today.none" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="p in data.pending.payroll_approvals" :key="p.month" class="flex justify-between gap-2 py-1.5 text-sm">
                        <span class="text-ink">{{ p.month }}</span>
                        <span class="tabular-nums shrink-0 text-ink-soft">{{ p.count }} {{ $t('today.payrolls_count') }}</span>
                    </li>
                </ul>
            </VCard>

            <VCard>
                <h3 class="mb-2 text-xs font-semibold uppercase text-muted"><Bilingual k="today.advances_pending" inline /></h3>
                <VEmptyState v-if="data.pending.advances_pending.length === 0" title-key="today.none" message-key="today.none" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="a in data.pending.advances_pending" :key="a.id" class="flex justify-between gap-2 py-1.5 text-sm">
                        <span class="truncate text-ink">{{ a.employee }}</span>
                        <span v-if="can.view_pay && a.amount !== undefined" class="tabular-nums shrink-0 text-ink-soft">{{ eur(a.amount) }}</span>
                        <span v-else class="tabular-nums shrink-0 text-muted">{{ a.request_date }}</span>
                    </li>
                </ul>
            </VCard>
        </div>
    </AppLayout>
</template>
