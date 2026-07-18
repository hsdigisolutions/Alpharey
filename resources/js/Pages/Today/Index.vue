<script setup>
/**
 * Screen 15 — Today's Report. 6 KPI cards, today's live attendance table
 * (with a home-company column for workers deployed in), and the four
 * pending-action lists. Reloads itself every 5 minutes.
 */
import { onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VCard from '@/Components/ui/VCard.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';

const props = defineProps({
    data: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const REFRESH_MS = 5 * 60 * 1000;
let timer = null;

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const statusBadge = { present: 'ok', late: 'warn', early_leave: 'warn', absent: 'danger', leave: 'info' };

onMounted(() => {
    timer = setInterval(() => {
        // Partial reload of just the data prop — keeps scroll and is cheap.
        router.reload({ only: ['data'] });
    }, REFRESH_MS);
});

onBeforeUnmount(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head :title="$t('today.title')" />

    <AppLayout>
        <VPageHeader k="today.title" />

        <p class="mb-4 text-xs text-muted">
            {{ $t('today.auto_refresh') }} · {{ $t('today.updated') }} {{ data.generated_at?.slice(11, 16) }}
        </p>

        <!-- 6 KPI cards -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
            <VKpiCard k="today.total_workers" icon="employees" :value="data.kpis.total_workers" />
            <VKpiCard k="today.active_today" icon="attendance" :value="data.kpis.active_today" status="ok" />
            <VKpiCard k="today.on_leave_today" icon="attendance" :value="data.kpis.on_leave_today"
                :status="data.kpis.on_leave_today > 0 ? 'warn' : null" />
            <VKpiCard k="today.absent_today" icon="attendance" :value="data.kpis.absent_today"
                :status="data.kpis.absent_today > 0 ? 'danger' : null" />
            <VKpiCard k="today.hours_today" icon="attendance" :value="data.kpis.hours_today" />
            <VKpiCard k="today.pending_calls" icon="calls" :value="data.kpis.pending_calls"
                :status="data.kpis.pending_calls > 0 ? 'warn' : null" />
        </div>

        <!-- Attendance table -->
        <VCard class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-ink"><Bilingual k="today.attendance_table" inline /></h2>
            <VEmptyState v-if="data.attendance.length === 0" title-key="today.no_attendance" message-key="today.no_attendance" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-start text-xs uppercase text-muted">
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.employee') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.home_company') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.project') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.check_in') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.check_out') }}</th>
                            <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('today.hours') }}</th>
                            <th class="px-2 py-2 text-start font-medium">{{ $t('today.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in data.attendance" :key="row.id" class="border-b border-line">
                            <td class="px-2 py-2 text-ink">{{ row.employee }}</td>
                            <td class="px-2 py-2">
                                <VBadge v-if="row.home_company" status="info">{{ row.home_company }}</VBadge>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="px-2 py-2 text-ink-soft">{{ row.project ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_in ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.check_out ?? '—' }}</td>
                            <td class="tabular-nums px-2 py-2 text-end text-ink">{{ row.hours }}</td>
                            <td class="px-2 py-2"><VBadge :status="statusBadge[row.status] ?? 'neutral'">{{ row.status }}</VBadge></td>
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
