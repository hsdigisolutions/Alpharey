<script setup>
/**
 * Renders a company document type's fields dynamically from the registry
 * (field_defs shipped by the backend). Used by the edit-metadata form and the
 * new-version / first-upload forms.
 *
 * Column-bound dates (start/end/valid_until → issue_date/expiry_date) follow the
 * `datesEditable` flag. The CCC is always read-only (mirrored from the company).
 * Dates are editable both on a NEW VERSION and when EDITING fields — the metadata
 * endpoint now corrects issue/expiry in place (client 2026-09), so a mistyped
 * alert date no longer forces a re-upload.
 */
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VInput from '@/Components/ui/VInput.vue';
import VPhoneInput from '@/Components/ui/VPhoneInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    fields: { type: Array, required: true },
    form: { type: Object, required: true }, // Inertia useForm — carries metadata{}, contacts[], issue_date, expiry_date
    ccc: { type: String, default: null },
    datesEditable: { type: Boolean, default: true },
});

function addContact() {
    if (!Array.isArray(props.form.contacts)) {
        props.form.contacts = [];
    }
    props.form.contacts.push({ name: '', role: '', phone: '', email: '', notes: '' });
}

function removeContact(index) {
    props.form.contacts.splice(index, 1);
}

function wide(field) {
    return field.type === 'textarea' || field.type === 'checkbox_group';
}

function errorFor(field) {
    if (field.type === 'ccc') {
        return null;
    }
    if (field.column) {
        return props.form.errors?.[field.column];
    }

    return props.form.errors?.[`metadata.${field.key}`];
}

function isChecked(key, opt) {
    return Array.isArray(props.form.metadata[key]) && props.form.metadata[key].includes(opt);
}

function toggleCheckbox(key, opt, checked) {
    const current = Array.isArray(props.form.metadata[key]) ? [...props.form.metadata[key]] : [];
    const index = current.indexOf(opt);

    if (checked && index === -1) {
        current.push(opt);
    }
    if (!checked && index !== -1) {
        current.splice(index, 1);
    }

    props.form.metadata[key] = current;
}
</script>

<template>
    <div class="space-y-4">
        <section class="space-y-3">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">
                <Bilingual k="doc_fields.section_details" inline />
            </p>
            <div class="grid gap-3 sm:grid-cols-2">
                <FormField v-for="field in fields" :key="field.key" :k="field.label" :error="errorFor(field)"
                    :class="wide(field) ? 'sm:col-span-2' : ''">
                    <!-- Read-only CCC from the company record -->
                    <VInput v-if="field.type === 'ccc'" :model-value="ccc ?? '—'" disabled />

                    <!-- Column-bound dates: editable on a new version, read-only when editing fields -->
                    <VDateInput v-else-if="field.column"
                        v-model="form[field.column]" :disabled="!datesEditable"
                        :invalid="Boolean(errorFor(field))" />

                    <VDateInput v-else-if="field.type === 'date'"
                        v-model="form.metadata[field.key]" :disabled="!datesEditable" />
                    <VDateInput v-else-if="field.type === 'month_year'" type="month"
                        v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))" />

                    <VCurrencyInput v-else-if="field.type === 'money'"
                        v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))" />
                    <VInput v-else-if="field.type === 'number'" type="number"
                        v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))" />
                    <VInput v-else-if="field.type === 'email'" type="email"
                        v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))" />
                    <VPhoneInput v-else-if="field.type === 'tel'" v-model="form.metadata[field.key]" />
                    <VTextarea v-else-if="field.type === 'textarea'" v-model="form.metadata[field.key]" :rows="2" />

                    <VSelect v-else-if="field.type === 'select'"
                        v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))">
                        <option value="">—</option>
                        <option v-for="opt in field.options" :key="opt" :value="opt">
                            {{ $tPair(`doc_fields.opt_${opt}`) }}
                        </option>
                    </VSelect>

                    <div v-else-if="field.type === 'checkbox_group'" class="flex flex-wrap gap-x-4 gap-y-1.5">
                        <VCheckbox v-for="opt in field.options" :key="opt"
                            :model-value="isChecked(field.key, opt)"
                            @update:model-value="(value) => toggleCheckbox(field.key, opt, value)">
                            <span class="text-sm">{{ $tPair(`doc_fields.opt_${opt}`) }}</span>
                        </VCheckbox>
                    </div>

                    <VInput v-else v-model="form.metadata[field.key]" :invalid="Boolean(errorFor(field))" />
                </FormField>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted">
                    <Bilingual k="doc_fields.contacts_section" inline />
                </p>
                <button type="button"
                    class="flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-accent hover:bg-accent-soft"
                    @click="addContact">
                    <AppIcon name="plus" class="h-3.5 w-3.5" />
                    <Bilingual k="doc_fields.add_contact" inline />
                </button>
            </div>

            <p v-if="!form.contacts || form.contacts.length === 0" class="text-xs text-muted">
                <Bilingual k="doc_fields.no_contacts" inline />
            </p>

            <div v-for="(contact, i) in form.contacts" :key="i"
                class="space-y-3 rounded-lg border border-line bg-surface-sunken/40 p-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-ink-soft">#{{ i + 1 }}</span>
                    <button type="button" class="rounded-md p-1 text-status-danger hover:bg-status-danger-soft"
                        :aria-label="`remove contact ${i + 1}`" @click="removeContact(i)">
                        <AppIcon name="trash" class="h-3.5 w-3.5" />
                    </button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField k="doc_fields.contact_name">
                        <VInput v-model="contact.name" />
                    </FormField>
                    <FormField k="doc_fields.contact_role">
                        <VInput v-model="contact.role" />
                    </FormField>
                    <FormField k="doc_fields.contact_phone">
                        <VPhoneInput v-model="contact.phone" />
                    </FormField>
                    <FormField k="doc_fields.contact_email" :error="form.errors?.[`contacts.${i}.email`]">
                        <VInput v-model="contact.email" type="email" />
                    </FormField>
                    <FormField k="doc_fields.contact_notes" class="sm:col-span-2">
                        <VTextarea v-model="contact.notes" :rows="2" />
                    </FormField>
                </div>
            </div>
        </section>
    </div>
</template>
