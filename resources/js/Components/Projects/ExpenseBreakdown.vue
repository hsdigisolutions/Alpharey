<script setup>
// Item 4 — the two labeled expense sections for the project P&L:
//   • Company operational costs — our cost, subtracted from profit.
//   • Client-billable costs — reimbursed by the client (recovered on unit-billed
//     projects, or already in the invoice on invoice-billed projects).
// Purely presentational; the money math lives in ProfitabilityService.
import Bilingual from '@/Components/Bilingual.vue';
import { t } from '@/translate';

defineProps({ breakdown: { type: Object, default: null } });

const eur = (n) => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n) || 0);
</script>

<template>
    <div v-if="breakdown && (breakdown.operational.total > 0 || breakdown.client_billable.total > 0)" class="space-y-3">
        <!-- Company operational costs — subtract from profit -->
        <div v-if="breakdown.operational.total > 0" class="overflow-hidden rounded-md border border-line">
            <div class="flex items-center justify-between border-b border-line bg-surface-sunken px-3 py-1.5">
                <Bilingual k="profitability.company_costs" class="text-xs font-medium text-ink-soft" inline />
                <span class="tabular-nums text-xs font-medium text-status-danger">− {{ eur(breakdown.operational.total) }}</span>
            </div>
            <table class="w-full text-xs">
                <tbody>
                    <tr v-for="(it, i) in breakdown.operational.items" :key="'op' + i" class="border-b border-line/60 last:border-0">
                        <td class="px-3 py-1">{{ it.vendor }}<span v-if="it.category" class="ms-1 text-muted">· {{ it.category }}</span></td>
                        <td class="tabular-nums px-3 py-1 text-muted">{{ it.date }}</td>
                        <td class="tabular-nums px-3 py-1 text-end">{{ eur(it.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Client-billable costs — recoverable (or already invoiced) -->
        <div v-if="breakdown.client_billable.total > 0" class="overflow-hidden rounded-md border border-line">
            <div class="flex items-center justify-between border-b border-line bg-surface-sunken px-3 py-1.5">
                <Bilingual k="profitability.client_costs" class="text-xs font-medium text-ink-soft" inline />
                <span class="tabular-nums text-xs font-medium" :class="breakdown.client_billable.in_cost ? 'text-ink-soft' : 'text-status-ok'">
                    {{ breakdown.client_billable.in_cost ? eur(breakdown.client_billable.total) : '+ ' + eur(breakdown.client_billable.total) }}
                </span>
            </div>
            <p class="border-b border-line/60 px-3 py-1 text-[11px] text-muted">
                {{ breakdown.client_billable.in_cost ? t('profitability.client_costs_invoiced') : t('profitability.client_costs_recovered') }}
            </p>
            <table class="w-full text-xs">
                <tbody>
                    <tr v-for="(it, i) in breakdown.client_billable.items" :key="'cl' + i" class="border-b border-line/60 last:border-0">
                        <td class="px-3 py-1">{{ it.vendor }}<span v-if="it.category" class="ms-1 text-muted">· {{ it.category }}</span></td>
                        <td class="tabular-nums px-3 py-1 text-muted">{{ it.date }}</td>
                        <td class="tabular-nums px-3 py-1 text-end">{{ eur(it.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
