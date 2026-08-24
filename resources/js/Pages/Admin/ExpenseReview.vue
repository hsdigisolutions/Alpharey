<script setup>
/**
 * Part C — Expenses awaiting review (Super Admin only). Every expense a manager
 * or admin escalated with "Send to review" lands here; the Super Admin's
 * approve/reject is the final decision.
 */
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Bilingual from '@/Components/Bilingual.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';

defineProps({
    expenses: { type: Array, required: true },
});

const eur = (n) => new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(Number(n) || 0);

function decide(id, action) {
    router.post(`/expense-review/${id}/${action}`, {}, { preserveScroll: true });
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
                        <span class="tabular-nums text-base font-semibold text-ink">{{ eur(e.amount) }}</span>
                        <VBadge status="warn"><Bilingual k="expenses.in_review" inline /></VBadge>
                        <span class="text-xs text-muted">{{ e.company }}</span>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[13px] text-ink-soft">
                        <span class="tabular-nums">{{ e.date }}</span>
                        <span v-if="e.category">· {{ e.category }}</span>
                        <span v-if="e.employee">· {{ e.employee }}</span>
                        <span v-if="e.vehicle">· {{ e.vehicle }}</span>
                    </div>
                    <p v-if="e.notes" class="mt-1 truncate text-[13px] text-muted">{{ e.notes }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <VButton variant="secondary" size="sm" @click="decide(e.id, 'reject')">
                        <Bilingual k="expenses.review_reject" inline />
                    </VButton>
                    <VButton size="sm" @click="decide(e.id, 'approve')">
                        <Bilingual k="expenses.review_approve" inline />
                    </VButton>
                </div>
            </VCard>
        </div>
    </AppLayout>
</template>
