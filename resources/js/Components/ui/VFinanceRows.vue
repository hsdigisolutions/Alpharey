<script setup>
import VCard from '@/Components/ui/VCard.vue';
import VBadge from '@/Components/ui/VBadge.vue';
/**
 * A read-only list of finance rows (invoices or expenses) for the detail-screen
 * finance tabs — project Facturas/Gastos, client Facturas, vendor Gastos. The
 * standalone screens remain the canonical place to create/edit; this is a view
 * of the same data scoped to one entity.
 *
 * Gating is server-side: the controller passes an empty list and canView=false
 * when the user may not see the module, and this shows a permission notice
 * rather than an empty table.
 */
defineProps({
    rows: { type: Array, default: () => [] },
    canView: { type: Boolean, default: false },
    // lang key shown when the user has permission but there are no rows
    emptyKey: { type: String, required: true },
    // show the counterparty column (client/vendor name) — off for client/vendor
    // detail where the party is implicit
    showParty: { type: Boolean, default: true },
    // show the billing-period column — on for the project invoices tab (the
    // invoiced-periods history), off elsewhere.
    showPeriod: { type: Boolean, default: false },
});

function eur(value) {
    return `${Number(value ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function fmtShort(iso) {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('es-ES', { day: 'numeric', month: 'short' });
}
function periodLabel(row) {
    if (row.period_start && row.period_end) {
        return `${fmtShort(row.period_start)} – ${fmtShort(row.period_end)} ${row.period_end.split('-')[0]}`;
    }
    return row.period_text || '—';
}

const statusBadge = { paid: 'ok', partial: 'warn', unpaid: 'danger', pending: 'warn' };
</script>

<template>
    <VCard>
        <p v-if="!canView" class="py-8 text-center text-sm text-muted">
            <Bilingual k="finance.no_permission" class="items-center" />
        </p>
        <p v-else-if="rows.length === 0" class="py-8 text-center text-sm text-muted">
            <Bilingual :k="emptyKey" class="items-center" />
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs uppercase text-muted">
                        <th class="px-2 py-2 text-start font-medium">{{ $t('finance.number') }}</th>
                        <th v-if="showParty" class="px-2 py-2 text-start font-medium">{{ $t('finance.party') }}</th>
                        <th class="px-2 py-2 text-start font-medium">{{ $t('finance.date') }}</th>
                        <th v-if="showPeriod" class="px-2 py-2 text-start font-medium">{{ $t('finance.billing_period') }}</th>
                        <th class="tabular-nums px-2 py-2 text-end font-medium">{{ $t('finance.total') }}</th>
                        <th class="px-2 py-2 text-start font-medium">{{ $t('finance.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-line">
                        <td class="px-2 py-2 text-ink">{{ row.number ?? '—' }}</td>
                        <td v-if="showParty" class="px-2 py-2 text-ink-soft">{{ row.party ?? '—' }}</td>
                        <td class="tabular-nums px-2 py-2 text-ink-soft">{{ row.date ?? '—' }}</td>
                        <td v-if="showPeriod" class="px-2 py-2 text-ink-soft">{{ periodLabel(row) }}</td>
                        <td class="tabular-nums px-2 py-2 text-end text-ink">{{ eur(row.total) }}</td>
                        <td class="px-2 py-2">
                            <VBadge :status="statusBadge[row.status] ?? 'neutral'">{{ row.status }}</VBadge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </VCard>
</template>
