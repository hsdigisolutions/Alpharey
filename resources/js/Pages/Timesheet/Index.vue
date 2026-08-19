<script setup>
/**
 * Timesheet — a weekly (or monthly) per-employee view of attendance. Admins
 * pick any employee, navigate by week/month, filter by project, and export.
 */
import { computed, reactive } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VCard from '@/Components/ui/VCard.vue';
import VButton from '@/Components/ui/VButton.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import AppIcon from '@/Components/AppIcon.vue';

const props = defineProps({
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
    filters: { type: Object, required: true },
    period: { type: Object, required: true },
    sheet: { type: Object, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const locale = computed(() => (page.props.locale?.primary === 'es' ? 'es-ES' : 'en-GB'));

const state = reactive({
    employee: props.filters.employee ?? '',
    mode: props.filters.mode ?? 'week',
    project: props.filters.project ?? '',
});

function go(date) {
    router.get('/timesheet', {
        employee: state.employee || undefined,
        mode: state.mode,
        date: date ?? props.period.start,
        project: state.project || undefined,
    }, { preserveScroll: true, preserveState: true });
}

function shift(dir) {
    const d = new Date(props.period.start);
    if (state.mode === 'month') d.setMonth(d.getMonth() + dir);
    else d.setDate(d.getDate() + dir * 7);
    go(d.toISOString().slice(0, 10));
}

const weekdayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const dayTypeBadge = { full: 'ok', half: 'warn', hourly: 'info', per_meter: 'info' };
const statusBadge = { present: 'ok', late: 'warn', early_leave: 'warn', absent: 'danger', leave: 'info' };

const periodLabel = computed(() => {
    const fmt = (s) => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }).format(new Date(s));
    return `${fmt(props.period.start)} — ${fmt(props.period.end)}`;
});

function exportSheet(format) {
    const params = new URLSearchParams();
    if (state.employee) params.append('employee', state.employee);
    params.append('mode', state.mode);
    params.append('date', props.period.start);
    if (state.project) params.append('project', state.project);
    params.append('format', format);
    window.location.href = `/timesheet/export?${params.toString()}`;
}
</script>

<template>
    <Head :title="$t('timesheet.title')" />

    <AppLayout>
        <VPageHeader k="timesheet.title" />

        <!-- Controls -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <VSelect v-model="state.employee" class="w-full sm:w-64" @update:model-value="go()">
                <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option>
            </VSelect>
            <VSelect v-model="state.mode" class="w-32" @update:model-value="go()">
                <option value="week">{{ $t('timesheet.week') }}</option>
                <option value="month">{{ $t('timesheet.month') }}</option>
            </VSelect>
            <VSelect v-model="state.project" class="w-full sm:w-52" @update:model-value="go()">
                <option value="">{{ $t('timesheet.all_projects') }}</option>
                <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </VSelect>

            <div class="ms-auto flex items-center gap-2">
                <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="shift(-1)">
                    <AppIcon name="chevron-left" class="h-4 w-4" />
                </button>
                <span class="min-w-40 text-center text-sm font-semibold">{{ periodLabel }}</span>
                <button type="button" class="rounded-md border border-line p-1.5 hover:bg-surface-hover" @click="shift(1)">
                    <AppIcon name="chevron-right" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <VCard>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-ink">
                    {{ $t('timesheet.week_total') }}: <span class="tabular-nums">{{ sheet.total_hours }}h</span>
                    · {{ $t('timesheet.days_present') }}: <span class="tabular-nums">{{ sheet.days_present }}</span>
                </p>
                <div v-if="can.export" class="flex items-center gap-2">
                    <VButton variant="secondary" size="sm" icon="download" @click="exportSheet('excel')">Excel</VButton>
                    <VButton variant="secondary" size="sm" icon="download" @click="exportSheet('pdf')">PDF</VButton>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.day') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.project') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.check_in') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.check_out') }}</th>
                            <th class="px-2 py-2 text-end font-medium">{{ $t('timesheet.hours') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.day_type') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('timesheet.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in sheet.rows" :key="row.date" class="border-b border-line"
                            :class="row.weekday >= 6 ? 'bg-surface-sunken/40' : ''">
                            <td class="px-2 py-2">
                                <span class="font-medium text-ink">{{ $t(`weekdays.${weekdayKeys[row.weekday - 1]}`) }}</span>
                                <span class="ms-1 text-xs text-muted">{{ row.date.slice(8, 10) }}/{{ row.date.slice(5, 7) }}</span>
                            </td>
                            <td class="px-2 py-2 text-ink-soft">{{ row.project ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_in ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_out ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-end text-ink">{{ row.hours != null ? `${row.hours}h` : '—' }}</td>
                            <td class="px-2 py-2">
                                <VBadge v-if="row.day_type" :status="dayTypeBadge[row.day_type] ?? 'neutral'">
                                    <Bilingual :k="`attendance.day_type_${row.day_type}`" inline />
                                </VBadge>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="px-2 py-2">
                                <VBadge :status="statusBadge[row.status] ?? 'neutral'">
                                    <Bilingual :k="`attendance.roster_status_${row.status}`" inline />
                                </VBadge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </VCard>
    </AppLayout>
</template>
