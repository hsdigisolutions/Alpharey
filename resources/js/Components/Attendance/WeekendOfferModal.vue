<script setup>
/**
 * Admin publishes a Weekend Work Offer for a specific Saturday/Sunday: the
 * project, the weekend rate, and which workers are invited. Only then can those
 * workers check in that day on the PWA (weekends are days off by default).
 */
import { useForm } from '@inertiajs/vue3';
import Bilingual from '@/Components/Bilingual.vue';
import FormField from '@/Components/ui/FormField.vue';
import VButton from '@/Components/ui/VButton.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    employees: { type: Array, required: true },
    projects: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const rateTypes = [
    { value: 'normal', k: 'attendance.offer_rate_normal' },
    { value: 'x1.5', k: 'attendance.offer_rate_x15' },
    { value: 'x2', k: 'attendance.offer_rate_x2' },
    { value: 'custom', k: 'attendance.offer_rate_custom' },
];

const form = useForm({
    offer_date: null,
    project_id: '',
    weekend_rate_type: 'normal',
    weekend_rate_amount: null,
    invited_employee_ids: [],
});

function toggle(id) {
    const i = form.invited_employee_ids.indexOf(id);
    if (i === -1) form.invited_employee_ids.push(id);
    else form.invited_employee_ids.splice(i, 1);
}

function submit() {
    form.transform((d) => ({ ...d, project_id: d.project_id || null }))
        .post('/weekend-offers', {
            preserveScroll: true,
            onSuccess: () => { form.reset(); emit('close'); },
        });
}
</script>

<template>
    <VModal :open="open" title-key="attendance.weekend_offer" size="md" @close="emit('close')">
        <form id="weekend-offer-form" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField k="attendance.offer_date" :error="form.errors.offer_date" required>
                    <VDateInput v-model="form.offer_date" />
                </FormField>
                <FormField k="attendance.offer_project" :error="form.errors.project_id">
                    <VSelect v-model="form.project_id">
                        <option value="">—</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </VSelect>
                </FormField>
            </div>

            <FormField k="attendance.offer_rate" :error="form.errors.weekend_rate_type">
                <div class="flex flex-wrap gap-3">
                    <label v-for="r in rateTypes" :key="r.value" class="flex items-center gap-1.5 text-sm">
                        <input v-model="form.weekend_rate_type" type="radio" :value="r.value" class="accent-accent" />
                        <Bilingual :k="r.k" inline />
                    </label>
                </div>
            </FormField>

            <FormField v-if="form.weekend_rate_type === 'custom'" k="attendance.offer_rate_amount" :error="form.errors.weekend_rate_amount">
                <VCurrencyInput v-model="form.weekend_rate_amount" />
            </FormField>

            <FormField k="attendance.offer_workers" :error="form.errors.invited_employee_ids">
                <div class="max-h-56 space-y-1 overflow-y-auto rounded-md border border-line p-2">
                    <label v-for="e in employees" :key="e.id"
                        class="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1 text-sm hover:bg-surface-hover">
                        <input type="checkbox" :checked="form.invited_employee_ids.includes(e.id)"
                            class="accent-accent" @change="toggle(e.id)" />
                        <span>{{ e.full_name }}</span>
                        <span v-if="e.designation" class="text-xs text-muted">· {{ e.designation }}</span>
                    </label>
                </div>
            </FormField>
        </form>

        <template #footer>
            <VButton variant="ghost" @click="emit('close')"><Bilingual k="common.cancel" inline /></VButton>
            <VButton type="submit" form="weekend-offer-form" :loading="form.processing">
                <Bilingual k="attendance.offer_create" inline />
            </VButton>
        </template>
    </VModal>
</template>
