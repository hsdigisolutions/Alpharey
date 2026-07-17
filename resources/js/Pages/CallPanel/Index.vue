<script setup>
/**
 * Screen 13 — Call Panel. Two columns: who to call on the left, the log for
 * the selected worker on the right, with the log form always visible.
 *
 * The indicator and the "not contacted" flag are computed server-side — the
 * page renders the decision, it does not make it.
 */
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCard from '@/Components/ui/VCard.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    employees: { type: Array, required: true },
    filters: { type: Object, required: true },
    selected: { type: Object, default: null },
    stats: { type: Object, required: true },
    can: { type: Object, required: true },
});

const state = reactive({
    search: props.filters.search ?? '',
    tab: props.filters.tab ?? 'all',
});

const tabs = [
    { key: 'all', labelKey: 'calls.tab_all' },
    { key: 'pending', labelKey: 'calls.tab_pending' },
    { key: 'not_contacted', labelKey: 'calls.tab_not_contacted' },
];

function apply(extra = {}) {
    router.get('/calls', { ...state, employee: props.selected?.id, ...extra }, {
        preserveScroll: true,
        preserveState: true,
    });
}
function select(employee) {
    router.get('/calls', { ...state, employee: employee.id }, { preserveScroll: true, preserveState: true });
}

/* The log form — always visible on the right column, per the spec. */
const form = useForm({ employee_id: null, called_at: null, remarks: '', follow_up_date: null });

watch(() => props.selected?.id, (id) => { form.employee_id = id ?? null; }, { immediate: true });

function submit() {
    form.post('/calls', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); form.employee_id = props.selected?.id ?? null; },
    });
}

/* Traffic-light tones map to the same status vocabulary as everywhere else. */
const dotStatus = { red: 'danger', amber: 'warn', green: 'ok' };
</script>

<template>
    <Head :title="$t('calls.title')" />
    <AppLayout>
        <VPageHeader k="calls.title" />

        <!-- Top stats bar -->
        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <VKpiCard k="calls.stat_calls_today" :value="stats.calls_today" />
            <VKpiCard k="calls.stat_pending_follow_ups" :value="stats.pending_follow_ups"
                :status="stats.pending_follow_ups > 0 ? 'warn' : 'ok'" />
            <VKpiCard k="calls.stat_not_contacted" :value="stats.not_contacted_this_week"
                :status="stats.not_contacted_this_week > 0 ? 'warn' : 'ok'" />
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <!-- Left: who to call -->
            <div class="flex flex-col gap-3">
                <VSearchInput v-model="state.search" :placeholder="$t('calls.search')" @update:model-value="apply()" />
                <VTabs v-model="state.tab" :tabs="tabs" @update:model-value="apply({ tab: $event })" />

                <div class="flex max-h-[32rem] flex-col overflow-y-auto rounded-lg border border-line">
                    <button v-for="e in employees" :key="e.id" type="button"
                        class="flex items-center gap-3 border-b border-line px-3 py-2.5 text-start transition-colors duration-150 last:border-b-0"
                        :class="selected?.id === e.id ? 'bg-accent-soft' : 'hover:bg-surface-hover'"
                        @click="select(e)">
                        <VAvatar :name="e.name" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{ e.name }}</span>
                            <span class="block truncate text-xs text-muted">
                                {{ e.last_contacted ?? $t('calls.never_contacted') }}
                            </span>
                        </span>
                        <VStatusDot :status="dotStatus[e.indicator]" :pulse="e.indicator === 'red'" />
                    </button>
                    <p v-if="employees.length === 0" class="px-3 py-6 text-center text-sm text-muted">
                        <Bilingual k="calls.no_calls" inline />
                    </p>
                </div>
            </div>

            <!-- Right: the selected worker -->
            <div v-if="selected" class="flex flex-col gap-4">
                <VCard>
                    <div class="flex flex-wrap items-center gap-3">
                        <VAvatar :name="selected.name" size="lg" />
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-lg font-semibold">{{ selected.name }}</h2>
                            <p class="truncate text-sm text-muted">
                                {{ [selected.company, selected.designation].filter(Boolean).join(' · ') || '—' }}
                            </p>
                        </div>
                        <!-- A real anchor, not a VButton: tel: opens the dialer
                             on mobile, and only an <a> does that. -->
                        <a v-if="selected.mobile" :href="`tel:${selected.mobile}`"
                            class="tabular-nums inline-flex shrink-0 items-center gap-2 rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-on-accent shadow-card transition-colors duration-150 hover:bg-accent-hover">
                            <AppIcon name="calls" class="h-4 w-4" />
                            {{ selected.mobile }}
                        </a>
                    </div>
                </VCard>

                <VCard v-if="can.create">
                    <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <FormField k="calls.called_at" :error="form.errors.called_at">
                            <VDateInput v-model="form.called_at" />
                        </FormField>
                        <FormField k="calls.follow_up_date" :error="form.errors.follow_up_date">
                            <VDateInput v-model="form.follow_up_date" />
                        </FormField>
                        <FormField k="calls.remarks" :error="form.errors.remarks" class="sm:col-span-2" required>
                            <VTextarea v-model="form.remarks" :rows="3" />
                        </FormField>
                        <div class="flex justify-end sm:col-span-2">
                            <VButton type="submit" :loading="form.processing" icon="plus">
                                <Bilingual k="calls.log_call" inline />
                            </VButton>
                        </div>
                    </form>
                </VCard>

                <VCard>
                    <h3 class="mb-3 text-sm font-semibold"><Bilingual k="calls.call_history" inline /></h3>
                    <ul v-if="selected.calls.length" class="flex flex-col gap-3">
                        <li v-for="c in selected.calls" :key="c.id" class="border-b border-line pb-3 last:border-b-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="tabular-nums text-sm font-medium">{{ c.called_at }}</span>
                                <span class="text-xs text-muted">{{ c.called_by ?? '—' }}</span>
                            </div>
                            <p class="mt-1 text-sm text-ink-soft">{{ c.remarks }}</p>
                            <p v-if="c.follow_up_date" class="tabular-nums mt-1 text-xs text-status-warn">
                                <Bilingual k="calls.follow_up_date" inline />: {{ c.follow_up_date }}
                            </p>
                        </li>
                    </ul>
                    <p v-else class="py-4 text-center text-sm text-muted">
                        <Bilingual k="calls.no_calls" inline />
                    </p>
                </VCard>
            </div>

            <VEmptyState v-else icon="calls" title-key="calls.select_employee"
                message-key="calls.select_employee_hint" />
        </div>
    </AppLayout>
</template>
