<script setup>
/**
 * Part C — Expenses awaiting review (Super Admin only). Every expense a manager
 * or admin escalated with "Send to review" lands here; the Super Admin's
 * approve/reject is the final decision, cascaded to the worker + payroll.
 *
 * BUG 4 — each row opens the premium receipt-detail panel (reused from the
 * Expenses tab) so the SA sees the full receipt + who submitted it + which
 * manager escalated it + their note before deciding.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Bilingual from '@/Components/Bilingual.vue';
import ExpenseReceiptDetail from '@/Components/Expenses/ExpenseReceiptDetail.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

defineProps({
    expenses: { type: Array, required: true },
});

const eur = (n) => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n) || 0);

const detailRow = ref(null);
function openDetail(e) { detailRow.value = e; }

function decide(id, action) {
    // Rejection captures an optional reason (Item 5 follow-up) — the worker sees it.
    if (action === 'reject') { rejectId.value = id; rejectReason.value = ''; return; }
    router.post(`/expense-review/${id}/approve`, {}, { preserveScroll: true });
}
function decideFromDetail(action) {
    if (!detailRow.value) return;
    decide(detailRow.value.id, action);
    detailRow.value = null;
}

const rejectId = ref(null);
const rejectReason = ref('');
function confirmReject() {
    if (!rejectId.value) return;
    router.post(`/expense-review/${rejectId.value}/reject`,
        { reason: rejectReason.value || null },
        { preserveScroll: true, onSuccess: () => { rejectId.value = null; } });
}
</script>

<template>
    <AppLayout>
        <VPageHeader>
            <template #title><Bilingual k="expenses.review_title" /></template>
        </VPageHeader>

        <VCard v-if="expenses.length === 0" padding="lg">
            <VEmptyState icon="expenses" message-key="expenses.review_empty" />
        </VCard>

        <div v-else class="space-y-3">
            <VCard v-for="e in expenses" :key="e.id" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="tabular-nums text-base font-semibold text-ink">{{ eur(e.total) }}</span>
                        <VBadge status="info"><Bilingual k="expenses.in_review" inline /></VBadge>
                        <span class="text-xs text-muted">{{ e.company }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] text-ink-soft">
                        <span class="tabular-nums">{{ e.date }}</span>
                        <span v-if="e.category">· {{ e.category }}</span>
                        <span v-if="e.employee">· {{ e.employee }}</span>
                        <span v-if="e.vehicle">· {{ e.vehicle }}</span>
                    </div>
                    <p v-if="e.escalated_by" class="mt-1 text-[13px] text-ink-soft">
                        <Bilingual k="expenses.escalated_by" inline />: <span class="font-medium">{{ e.escalated_by }}</span>
                        <span v-if="e.review_note" class="italic text-muted"> — “{{ e.review_note }}”</span>
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <VButton variant="ghost" size="sm" icon="eye" @click="openDetail(e)">
                        <Bilingual k="expenses.review" inline />
                    </VButton>
                    <VButton variant="secondary" size="sm" @click="decide(e.id, 'reject')">
                        <Bilingual k="expenses.review_reject" inline />
                    </VButton>
                    <VButton size="sm" @click="decide(e.id, 'approve')">
                        <Bilingual k="expenses.review_approve" inline />
                    </VButton>
                </div>
            </VCard>
        </div>

        <!-- Full receipt + context, with the final decision in the footer. -->
        <VModal :open="!!detailRow" size="xl" title-key="expenses.receipt_detail_title" @close="detailRow = null">
            <ExpenseReceiptDetail v-if="detailRow" :row="detailRow" />
            <template #footer>
                <VButton variant="ghost" class="text-status-danger" @click="decideFromDetail('reject')">
                    <Bilingual k="expenses.review_reject" inline />
                </VButton>
                <VButton @click="decideFromDetail('approve')">
                    <Bilingual k="expenses.review_approve" inline />
                </VButton>
            </template>
        </VModal>

        <!-- Reject with an optional reason (Item 5 follow-up) — shown to the worker -->
        <VModal :open="!!rejectId" size="sm" title-key="expenses.review_reject" @close="rejectId = null">
            <p class="mb-2 text-xs text-ink-soft">{{ $t('expenses.reject_reason_hint') }}</p>
            <VTextarea v-model="rejectReason" :rows="3" :placeholder="$t('expenses.reject_reason_placeholder')" />
            <template #footer>
                <VButton variant="ghost" @click="rejectId = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton variant="danger" @click="confirmReject"><Bilingual k="expenses.review_reject" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
