<script setup>
/**
 * Subcontratistas (thaekedar) — list. Click a row to open the detail page
 * (record + workers + payment schedule). Create opens a modal.
 */
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppLayout from '@/Layouts/AppLayout.vue';
import SubcontractorFormModal from '@/Components/Subcontractors/SubcontractorFormModal.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';

const props = defineProps({
    subcontractors: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const showCreate = ref(false);
const statusVariant = { active: 'ok', completed: 'info', cancelled: 'danger' };

function eur(v) {
    return `${Number(v ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}
</script>

<template>
    <Head :title="t('subcontractors.title')" />
    <AppLayout>
        <div class="mb-5 flex items-center justify-between gap-3">
            <h1 class="text-lg font-semibold"><Bilingual k="subcontractors.title" /></h1>
            <VButton v-if="can.create" icon="plus" @click="showCreate = true">
                <Bilingual k="subcontractors.new" inline />
            </VButton>
        </div>

        <VCard v-if="!subcontractors.length">
            <VEmptyState icon="employees" title-key="subcontractors.none" />
        </VCard>

        <VCard v-else class="overflow-x-auto p-0">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs uppercase text-muted">
                        <th class="px-4 py-2.5 text-start font-medium"><Bilingual k="subcontractors.name" inline /></th>
                        <th class="px-4 py-2.5 text-start font-medium"><Bilingual k="subcontractors.project" inline /></th>
                        <th class="px-4 py-2.5 text-start font-medium"><Bilingual k="subcontractors.status" inline /></th>
                        <th class="px-4 py-2.5 text-end font-medium"><Bilingual k="subcontractors.workers" inline /></th>
                        <th class="px-4 py-2.5 text-end font-medium"><Bilingual k="subcontractors.total_agreed" inline /></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in subcontractors" :key="s.id" class="border-b border-line last:border-0 hover:bg-surface-hover">
                        <td class="px-4 py-2.5">
                            <Link :href="`/subcontractors/${s.id}`" class="font-medium text-accent hover:underline">{{ s.name }}</Link>
                        </td>
                        <td class="px-4 py-2.5 text-ink-soft">{{ s.project ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <VBadge :status="statusVariant[s.status]">{{ t(`subcontractors.status_${s.status}`) }}</VBadge>
                        </td>
                        <td class="tabular-nums px-4 py-2.5 text-end">{{ s.workers_count }}</td>
                        <td class="tabular-nums px-4 py-2.5 text-end font-medium">{{ eur(s.agreed_total) }}</td>
                    </tr>
                </tbody>
            </table>
        </VCard>

        <SubcontractorFormModal :open="showCreate" :projects="projects" :statuses="statuses" @close="showCreate = false" />
    </AppLayout>
</template>
