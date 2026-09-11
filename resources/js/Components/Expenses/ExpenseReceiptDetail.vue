<script setup>
/**
 * Premium two-pane receipt review (BUG 2): a context summary BESIDE the
 * image/PDF, so an admin/Super-Admin understands "what is this receipt for" at
 * a glance — vendor, amount, date, category, project/client, submitting worker,
 * payment method, and the approval / review trail — without leaving the preview.
 *
 * Reused by the Expenses "Recibos" tab and the Super-Admin review queue (BUG 4).
 * The `row` is the enriched receipt/expense payload from ExpenseReceiptExport::row
 * (or ExpenseController::row). Money is shown here deliberately — this is an
 * admin/SA surface (company books), never a worker screen.
 */
import { computed } from 'vue';
import AppIcon from '@/Components/AppIcon.vue';
import Bilingual from '@/Components/Bilingual.vue';
import VBadge from '@/Components/ui/VBadge.vue';

const props = defineProps({
    row: { type: Object, required: true },
});

const previewUrl = computed(() => `/expenses/${props.row.id}/receipt/preview`);
const downloadUrl = computed(() => `/expenses/${props.row.id}/receipt`);

// The row comes from either ExpenseReceiptExport::row (is_image/is_pdf set) or
// ExpenseController::row (only `ext`) — derive the type from ext as a fallback.
const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const isImage = computed(() => props.row.is_image ?? IMAGE_EXTS.includes(String(props.row.ext ?? '').toLowerCase()));
const isPdf = computed(() => props.row.is_pdf ?? (String(props.row.ext ?? '').toLowerCase() === 'pdf'));
const hasFile = computed(() => props.row.has_file !== false && (isImage.value || isPdf.value || props.row.ext));

function eur(v) {
    return `${Number(v ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

const status = computed(() => {
    if (props.row.review_status === 'in_review') return { variant: 'info', key: 'expenses.in_review' };
    return props.row.approved ? { variant: 'ok', key: 'expenses.is_approved' } : { variant: 'warn', key: 'expenses.pending' };
});

// base/vat may arrive as base/vat OR subtotal/vat_amount depending on the caller.
const base = computed(() => props.row.base ?? props.row.subtotal ?? null);
const vat = computed(() => props.row.vat ?? props.row.vat_amount ?? null);
</script>

<template>
    <div class="grid gap-4 lg:grid-cols-[minmax(0,19rem)_minmax(0,1fr)]">
        <!-- Context summary -->
        <div class="rounded-xl border border-line bg-surface-raised p-4 shadow-card">
            <div class="mb-3 flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-[15px] font-semibold text-ink">{{ row.vendor || row.category || '—' }}</p>
                    <p class="tabular-nums text-lg font-semibold text-ink">{{ eur(row.total) }}</p>
                </div>
                <VBadge :variant="status.variant"><Bilingual :k="status.key" inline /></VBadge>
            </div>

            <!-- Escalation trail — only when the receipt is in the review queue. -->
            <div v-if="row.review_status === 'in_review'" class="mb-3 rounded-lg border border-status-info/30 bg-status-info-soft p-2.5">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-status-info">
                    <Bilingual k="expenses.sent_to_review" inline />
                </p>
                <p v-if="row.escalated_by" class="mt-0.5 text-xs text-ink">
                    <Bilingual k="expenses.escalated_by" inline />: <span class="font-medium">{{ row.escalated_by }}</span>
                </p>
                <p v-if="row.review_note" class="mt-1 text-xs italic text-ink-soft">“{{ row.review_note }}”</p>
            </div>

            <dl class="space-y-2 text-sm">
                <div v-if="row.is_worker_submitted && row.employee" class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="worker_expenses.employee" inline /></dt>
                    <dd class="text-end font-medium text-ink">
                        {{ row.employee }}<span v-if="row.employee_code" class="text-muted"> · {{ row.employee_code }}</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="expenses.rc_date" inline /></dt>
                    <dd class="tabular-nums text-end text-ink">{{ row.date ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="expenses.category" inline /></dt>
                    <dd class="text-end text-ink">{{ row.category ?? '—' }}</dd>
                </div>
                <div v-if="row.project" class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="expenses.project" inline /></dt>
                    <dd class="text-end text-ink">{{ row.project }}<span v-if="row.client" class="block text-xs text-muted">{{ row.client }}</span></dd>
                </div>
                <div v-if="row.payment_method" class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="expenses.payment_method" inline /></dt>
                    <dd class="text-end text-ink">{{ $t('expenses.pm_' + row.payment_method) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-t border-line pt-2">
                    <dt class="text-muted"><Bilingual k="expenses.subtotal" inline /></dt>
                    <dd class="tabular-nums text-end text-ink-soft">{{ base != null ? eur(base) : '—' }}</dd>
                </div>
                <div v-if="vat" class="flex justify-between gap-3">
                    <dt class="text-muted"><Bilingual k="expenses.vat" inline /></dt>
                    <dd class="tabular-nums text-end text-ink-soft">{{ eur(vat) }}</dd>
                </div>
                <div v-if="row.concept || row.notes" class="border-t border-line pt-2">
                    <dt class="mb-0.5 text-muted"><Bilingual k="expenses.notes" inline /></dt>
                    <dd class="text-ink-soft">{{ row.concept ?? row.notes }}</dd>
                </div>
            </dl>

            <a :href="downloadUrl" class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline">
                <AppIcon name="download" class="h-4 w-4" /> <Bilingual k="common.download" inline />
            </a>
        </div>

        <!-- Receipt image / PDF -->
        <div class="rounded-xl border border-line bg-surface-sunken p-2">
            <img v-if="isImage" :src="previewUrl" alt=""
                class="max-h-[72vh] w-full rounded-lg border border-line bg-white object-contain" />
            <iframe v-else-if="isPdf" :src="previewUrl" title="PDF"
                class="h-[72vh] w-full rounded-lg border border-line bg-white" />
            <div v-else class="flex h-64 flex-col items-center justify-center gap-2 text-sm text-muted">
                <AppIcon name="file" class="h-8 w-8" />
                <span>{{ row.original_name ?? row.filename ?? '—' }}</span>
                <a v-if="hasFile" :href="downloadUrl" class="text-accent hover:underline"><Bilingual k="common.download" inline /></a>
            </div>
        </div>
    </div>
</template>
