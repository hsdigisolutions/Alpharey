<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import AppLayout from '@/Layouts/AppLayout.vue';
import Bilingual from '@/Components/Bilingual.vue';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VPagination from '@/Components/ui/VPagination.vue';

const props = defineProps({
    expenses: { type: Object, required: true },
    employees: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
});

// ── New worker expense (admin adds on behalf of a worker) ────────────────────
const createOpen = ref(false);
const createForm = useForm({ employee_id: '', date: null, amount: null, category: 'fuel', description: '' });
function openCreate() {
    createForm.reset();
    createForm.category = 'fuel';
    createOpen.value = true;
}
function submitCreate() {
    createForm.post('/worker-expenses', {
        preserveScroll: true,
        onSuccess: () => { createOpen.value = false; createForm.reset(); },
    });
}

function eur(v) {
    return `${Number(v ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

function statusVariant(status) {
    return { pending: 'warn', approved: 'ok', rejected: 'danger' }[status] ?? 'neutral';
}

// Normalise a stored category (a bare code 'fuel', a stray full key
// 'worker.expense_cat_fuel', or a label 'Transport') to its lang KEY so the
// table always shows a proper name, never a raw key.
const KNOWN_CATS = ['fuel', 'transport', 'materials', 'tools', 'food', 'other'];
function categoryKey(category) {
    const code = String(category ?? 'other')
        .replace(/^worker\.expense_cat_/, '')
        .toLowerCase();
    return 'worker.expense_cat_' + (KNOWN_CATS.includes(code) ? code : 'other');
}

// ── Reject modal ─────────────────────────────────────────────────────────────
const rejectTarget = ref(null);
const rejectForm = useForm({ reason: '' });

function openReject(expense) {
    rejectTarget.value = expense;
    rejectForm.reset();
}

function submitReject() {
    rejectForm.post(route('worker-expenses.reject', rejectTarget.value.id), {
        onSuccess: () => { rejectTarget.value = null; rejectForm.reset(); },
    });
}

// ── Approve ──────────────────────────────────────────────────────────────────
function approve(expense) {
    router.post(route('worker-expenses.approve', expense.id));
}
</script>

<template>
    <Head :title="$tPair('worker_expenses.title')" />

    <AppLayout>
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-title font-semibold text-ink">
                <Bilingual k="worker_expenses.title" />
            </h1>
            <VButton v-if="can.create" icon="plus" @click="openCreate">
                <Bilingual k="worker_expenses.new" inline />
            </VButton>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-lg border border-line shadow-card">
            <table class="w-full text-left text-sm">
                <thead class="bg-surface-sunken text-[11px] font-medium uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-4 py-3"><Bilingual k="worker_expenses.employee" inline /></th>
                        <th class="px-4 py-3"><Bilingual k="worker_expenses.date" inline /></th>
                        <th class="px-4 py-3 text-right"><Bilingual k="worker_expenses.amount" inline /></th>
                        <th class="px-4 py-3"><Bilingual k="worker_expenses.category" inline /></th>
                        <th class="px-4 py-3"><Bilingual k="worker_expenses.description" inline /></th>
                        <th class="px-4 py-3 text-center"><Bilingual k="worker_expenses.receipt" inline /></th>
                        <th class="px-4 py-3 text-center"><Bilingual k="worker_expenses.status_label" inline /></th>
                        <th v-if="can.approve" class="px-4 py-3 text-center"><Bilingual k="common.actions" inline /></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!expenses.data.length">
                        <td :colspan="can.approve ? 8 : 7" class="px-4 py-8 text-center text-sm text-muted">
                            <Bilingual k="worker_expenses.empty" />
                        </td>
                    </tr>
                    <tr v-for="e in expenses.data" :key="e.id"
                        class="border-t border-line bg-surface-raised hover:bg-surface-hover">
                        <td class="px-4 py-3">
                            <p class="font-medium text-ink">{{ e.employee?.full_name }}</p>
                            <p class="text-xs text-muted">{{ e.employee?.employee_code }}</p>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-ink-soft">{{ e.date }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-ink">{{ eur(e.amount) }}</td>
                        <td class="px-4 py-3 text-ink-soft">
                            <Bilingual :k="categoryKey(e.category)" inline />
                        </td>
                        <td class="max-w-xs px-4 py-3">
                            <p class="truncate text-ink-soft">{{ e.description || '—' }}</p>
                            <p v-if="e.rejection_reason" class="mt-1 text-xs text-status-danger">
                                {{ e.rejection_reason }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a v-if="e.has_receipt"
                                :href="route('worker-expenses.receipt', e.id)"
                                target="_blank"
                                class="text-xs text-accent underline-offset-2 hover:underline">
                                <Bilingual k="worker_expenses.view_receipt" inline />
                            </a>
                            <span v-else class="text-xs text-muted">—</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <VBadge :variant="statusVariant(e.status)">
                                <Bilingual :k="'worker_expenses.status_' + e.status" inline />
                            </VBadge>
                            <p v-if="e.payroll_id" class="mt-1 text-[10px] text-muted">
                                <Bilingual k="worker_expenses.in_payroll" inline />
                            </p>
                        </td>
                        <td v-if="can.approve" class="px-4 py-3 text-center">
                            <template v-if="e.status === 'pending'">
                                <div class="flex items-center justify-center gap-2">
                                    <VButton size="sm" @click="approve(e)">
                                        <Bilingual k="worker_expenses.approve" inline />
                                    </VButton>
                                    <VButton size="sm" variant="ghost" class="text-status-danger" @click="openReject(e)">
                                        <Bilingual k="worker_expenses.reject" inline />
                                    </VButton>
                                </div>
                            </template>
                            <span v-else class="text-xs text-muted">
                                {{ e.approved_by ? `${$t('worker_expenses.by')} ${e.approved_by}` : '—' }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <VPagination :links="expenses.links" class="mt-4" />

        <!-- Reject modal -->
        <VModal :open="!!rejectTarget" title-key="worker_expenses.reject_title" size="sm"
            @close="rejectTarget = null">
            <form @submit.prevent="submitReject" class="space-y-4">
                <VTextarea v-model="rejectForm.reason" :rows="3"
                    :placeholder="$t('worker_expenses.reject_reason_placeholder')" />
                <p v-if="rejectForm.errors.reason" class="text-xs text-status-danger">
                    {{ rejectForm.errors.reason }}
                </p>
                <div class="flex gap-2">
                    <VButton variant="ghost" class="flex-1" type="button" @click="rejectTarget = null">
                        <Bilingual k="common.cancel" inline />
                    </VButton>
                    <VButton class="flex-1" type="submit" :loading="rejectForm.processing">
                        <Bilingual k="worker_expenses.reject_confirm" inline />
                    </VButton>
                </div>
            </form>
        </VModal>

        <!-- New worker expense (admin, on behalf of a worker) -->
        <VModal :open="createOpen" title-key="worker_expenses.new" @close="createOpen = false">
            <form id="wexp-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
                <FormField k="worker_expenses.employee" :error="createForm.errors.employee_id" required class="sm:col-span-2">
                    <VSelect v-model="createForm.employee_id">
                        <option value="" disabled>—</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }} ({{ e.employee_code }})</option>
                    </VSelect>
                </FormField>
                <FormField k="worker_expenses.date" :error="createForm.errors.date" required>
                    <VInput v-model="createForm.date" type="date" />
                </FormField>
                <FormField k="worker_expenses.amount" :error="createForm.errors.amount" required>
                    <VInput v-model="createForm.amount" type="number" step="0.01" min="0.01" />
                </FormField>
                <FormField k="worker_expenses.category" :error="createForm.errors.category" required>
                    <VSelect v-model="createForm.category">
                        <option v-for="c in categories" :key="c" :value="c">{{ $t('worker.expense_cat_' + c) }}</option>
                    </VSelect>
                </FormField>
                <FormField k="worker_expenses.description" :error="createForm.errors.description" class="sm:col-span-2">
                    <VTextarea v-model="createForm.description" :rows="2" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="createOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="wexp-form" :loading="createForm.processing"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
