<script setup>
/**
 * Screen 25 — Audit Logs (Super Admin + Company Admin, read-only).
 * Filters + statistics + CSV export. Row click opens a detail modal
 * with old/new values. No edit or delete anywhere.
 */
import { reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTable from '@/Components/ui/VTable.vue';

const props = defineProps({
    logs: { type: Object, required: true }, // paginator
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    actionOptions: { type: Array, required: true },
});

const filters = reactive({
    action: props.filters.action ?? '',
    module: props.filters.module ?? '',
    user: props.filters.user ?? '',
    date_from: props.filters.date_from ?? null,
    date_to: props.filters.date_to ?? null,
    per_page: Number(props.filters.per_page ?? 25),
});

function apply(extra = {}) {
    router.get('/admin/audit-logs', { ...filters, ...extra }, {
        preserveScroll: true,
        preserveState: true,
    });
}

function exportCsv() {
    const params = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value !== '' && value !== null),
    );
    window.location.href = `/admin/audit-logs/export?${params.toString()}`;
}

const detail = ref(null);

const actionStatus = {
    created: 'ok',
    updated: 'info',
    deleted: 'danger',
    exported: 'warn',
    login: 'neutral',
    logout: 'neutral',
};

const columns = [
    { key: 'created_at', labelKey: 'audit.time' },
    { key: 'user', labelKey: 'audit.user' },
    { key: 'action', labelKey: 'audit.action' },
    { key: 'module', labelKey: 'audit.module' },
    { key: 'entity', labelKey: 'audit.entity' },
    { key: 'ip', labelKey: 'audit.ip' },
];
</script>

<template>
    <Head title="Registro de Actividad" />

    <AppLayout>
        <VPageHeader k="audit.title">
            <VButton variant="secondary" icon="export" @click="exportCsv">
                <Bilingual k="audit.export" inline />
            </VButton>
        </VPageHeader>

        <!-- Statistics -->
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <VCard title-key="audit.by_action" :padded="true">
                <div class="flex flex-wrap gap-2">
                    <VBadge v-for="(count, action) in props.stats.by_action" :key="action"
                        :status="actionStatus[action] ?? 'neutral'">
                        {{ action }} · {{ count }}
                    </VBadge>
                </div>
            </VCard>
            <VCard title-key="audit.by_module">
                <div class="flex flex-wrap gap-2">
                    <VBadge v-for="(count, module) in props.stats.by_module" :key="module" status="accent">
                        {{ module }} · {{ count }}
                    </VBadge>
                </div>
            </VCard>
            <VCard title-key="audit.by_user">
                <div class="flex flex-wrap gap-2">
                    <VBadge v-for="(count, name) in props.stats.by_user" :key="name" status="neutral">
                        {{ name }} · {{ count }}
                    </VBadge>
                </div>
            </VCard>
        </div>

        <!-- Filters -->
        <div class="mb-4 grid gap-3 rounded-lg border border-line bg-surface-raised p-4 shadow-card sm:grid-cols-2 lg:grid-cols-5">
            <FormField k="audit.filter_action">
                <VSelect v-model="filters.action" @update:model-value="apply()">
                    <option value="">{{ $page.props.lang.es.audit.all }} / {{ $page.props.lang.en.audit.all }}</option>
                    <option v-for="action in props.actionOptions" :key="action" :value="action">{{ action }}</option>
                </VSelect>
            </FormField>
            <FormField k="audit.filter_module">
                <VInput v-model="filters.module" @keydown.enter="apply()" @blur="apply()" />
            </FormField>
            <FormField k="audit.filter_user">
                <VInput v-model="filters.user" @keydown.enter="apply()" @blur="apply()" />
            </FormField>
            <FormField k="audit.date_from">
                <VDateInput v-model="filters.date_from" @update:model-value="apply()" />
            </FormField>
            <FormField k="audit.date_to">
                <VDateInput v-model="filters.date_to" @update:model-value="apply()" />
            </FormField>
        </div>

        <!-- Table -->
        <VTable :columns="columns">
            <tr v-for="log in props.logs.data" :key="log.id"
                class="cursor-pointer hover:bg-surface-hover" @click="detail = log">
                <td class="tabular-nums px-3 py-2.5 text-xs text-ink-soft">{{ log.created_at }}</td>
                <td class="px-3 py-2.5">
                    <span class="block text-sm">{{ log.user_name ?? '—' }}</span>
                    <span class="block text-xs text-muted">{{ log.user_email }}</span>
                </td>
                <td class="px-3 py-2.5">
                    <VBadge :status="actionStatus[log.action] ?? 'neutral'">{{ log.action }}</VBadge>
                </td>
                <td class="px-3 py-2.5 text-sm text-ink-soft">{{ log.module ?? '—' }}</td>
                <td class="px-3 py-2.5">
                    <span class="block text-sm">{{ log.entity_name ?? log.model_type ?? '—' }}</span>
                    <span v-if="log.model_id" class="tabular-nums block text-xs text-muted">#{{ log.model_id }}</span>
                </td>
                <td class="tabular-nums px-3 py-2.5 text-xs text-muted">{{ log.ip_address ?? '—' }}</td>
            </tr>
            <template v-if="props.logs.data.length === 0" #empty>
                <VEmptyState icon="reports" />
            </template>
        </VTable>

        <VPagination
            :page="props.logs.current_page"
            :pages="props.logs.last_page"
            :per-page="filters.per_page"
            :total="props.logs.total"
            @update:page="(page) => apply({ page })"
            @update:per-page="(perPage) => { filters.per_page = perPage; apply(); }" />

        <!-- Detail modal (read-only) -->
        <VModal :open="detail !== null" title-key="audit.details" @close="detail = null">
            <div v-if="detail" class="space-y-4 text-sm">
                <div class="grid grid-cols-2 gap-3">
                    <div><Bilingual k="audit.time" class="text-xs text-muted" /><p class="tabular-nums">{{ detail.created_at }}</p></div>
                    <div><Bilingual k="audit.user" class="text-xs text-muted" /><p>{{ detail.user_name ?? '—' }}</p></div>
                    <div><Bilingual k="audit.action" class="text-xs text-muted" /><p>{{ detail.action }}</p></div>
                    <div><Bilingual k="audit.module" class="text-xs text-muted" /><p>{{ detail.module ?? '—' }}</p></div>
                </div>
                <div v-if="detail.old_values">
                    <Bilingual k="audit.old_values" class="text-xs text-muted" />
                    <pre class="mt-1 overflow-x-auto rounded-md bg-surface-sunken p-3 text-xs">{{ JSON.stringify(detail.old_values, null, 2) }}</pre>
                </div>
                <div v-if="detail.new_values">
                    <Bilingual k="audit.new_values" class="text-xs text-muted" />
                    <pre class="mt-1 overflow-x-auto rounded-md bg-surface-sunken p-3 text-xs">{{ JSON.stringify(detail.new_values, null, 2) }}</pre>
                </div>
            </div>
        </VModal>
    </AppLayout>
</template>
