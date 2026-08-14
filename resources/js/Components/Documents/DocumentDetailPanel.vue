<script setup>
/**
 * Smart company-document detail — a right-hand slide-over opened from a document
 * row. Shows the current version (who/when/status), the type-specific fields and
 * the point-of-contact block, with Download / Edit fields / New version / Replace
 * actions. Previous versions (Step 4) render collapsed at the bottom.
 */
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { tPair } from '@/translate';
import AppIcon from '@/Components/AppIcon.vue';
import DocumentFieldForm from '@/Components/Documents/DocumentFieldForm.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';

const props = defineProps({
    doc: { type: Object, default: null }, // the current-version row payload
    can: { type: Object, required: true }, // upload / download / editDocs
});

const emit = defineEmits(['close', 'new-version', 'refresh']);

const statusBadge = { ok: 'ok', warn: 'warn', danger: 'danger', neutral: 'neutral', exempt: 'info' };

const editing = ref(false);
const showReplace = ref(false);
const showHistory = ref(false);
const replaceFile = ref(null);

const editForm = useForm({
    metadata: {},
    issue_date: null,
    expiry_date: null,
    contacts: [],
});

const replaceForm = useForm({ file: null });

// Reset transient state whenever a different document is opened.
watch(() => props.doc?.id, () => {
    editing.value = false;
    showReplace.value = false;
    showHistory.value = false;
    replaceFile.value = null;
});

const money = new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function displayValue(field) {
    if (field.type === 'ccc') {
        return props.doc?.ccc || '—';
    }

    const raw = field.column ? props.doc?.[field.column] : props.doc?.metadata?.[field.key];

    if (raw === null || raw === undefined || raw === '' || (Array.isArray(raw) && raw.length === 0)) {
        return '—';
    }
    if (field.type === 'money') {
        return `${money.format(Number(raw))} €`;
    }
    if (field.type === 'select') {
        return tPair(`doc_fields.opt_${raw}`);
    }
    if (field.type === 'checkbox_group') {
        return raw.map((opt) => tPair(`doc_fields.opt_${opt}`)).join(', ');
    }

    return raw;
}

const contacts = computed(() => props.doc?.contacts ?? []);

function startEdit() {
    const doc = props.doc;
    editForm.clearErrors();
    // Fresh copy, coerced to a plain OBJECT — the backend ships empty metadata
    // as [] (a JS array), and string keys set on an array never serialize, so a
    // metadata edit would silently save nothing. Spreading [] yields {}.
    editForm.metadata = { ...(doc.metadata || {}) };
    editForm.issue_date = doc.issue_date ?? null;
    editForm.expiry_date = doc.expiry_date ?? null;
    editForm.contacts = (doc.contacts || []).map((c) => ({ ...c }));
    editing.value = true;
}

function submitEdit() {
    editForm.patch(`/documents/${props.doc.id}/metadata`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
            emit('refresh');
        },
    });
}

function pickReplace(files) {
    replaceFile.value = files[0];
    replaceForm.file = files[0];
}

function submitReplace() {
    replaceForm.post(`/documents/${props.doc.id}/replace`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showReplace.value = false;
            replaceFile.value = null;
            replaceForm.reset();
            emit('refresh');
        },
    });
}

function download() {
    window.location.href = `/documents/${props.doc.id}/download`;
}

function downloadVersion(id) {
    window.location.href = `/documents/${id}/download`;
}
</script>

<template>
    <VSlideOver :open="doc !== null" title-key="documents.detail" width="md:max-w-xl" @close="emit('close')">
        <template v-if="doc">
            <!-- Header: type name + traffic-light status -->
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-[17px] font-semibold">
                        <Bilingual :k="`doc_types.${doc.type_key}`" />
                    </h3>
                    <p class="tabular-nums mt-1 text-xs text-muted">
                        <Bilingual k="documents.current_version" inline /> · v{{ doc.version }}
                    </p>
                </div>
                <VBadge :status="statusBadge[doc.status] ?? 'neutral'">
                    <Bilingual :k="`documents.status_${doc.status}`" inline />
                    <span v-if="doc.days_left !== null" class="tabular-nums ms-1">({{ doc.days_left }}d)</span>
                </VBadge>
            </div>

            <!-- Version meta -->
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-y border-line py-2.5 text-xs text-ink-soft">
                <span><Bilingual k="documents.uploaded" inline />: {{ doc.uploaded_at ?? '—' }}</span>
                <span v-if="doc.uploaded_by"><Bilingual k="documents.by" inline />: {{ doc.uploaded_by }}</span>
                <span class="flex items-center gap-1">
                    <AppIcon :name="doc.has_file ? 'file' : 'x'" class="h-3.5 w-3.5" />
                    {{ doc.has_file ? doc.original_name : $tPair('documents.no_file') }}
                </span>
            </div>

            <!-- VIEW MODE -->
            <template v-if="!editing">
                <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2">
                    <div v-for="field in doc.field_defs" :key="field.key"
                        :class="field.type === 'textarea' || field.type === 'checkbox_group' ? 'sm:col-span-2' : ''">
                        <dt class="text-xs text-muted"><Bilingual :k="field.label" inline /></dt>
                        <dd class="text-sm text-ink">{{ displayValue(field) }}</dd>
                    </div>
                    <p v-if="!doc.field_defs || doc.field_defs.length === 0" class="text-sm text-muted sm:col-span-2">—</p>
                </dl>

                <!-- Contacts (every type; a document may have several) -->
                <div class="mt-5">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-muted"><Bilingual k="doc_fields.contacts_section" inline /></p>
                    <p v-if="contacts.length === 0" class="mt-2 text-sm text-muted">—</p>
                    <div v-for="(c, i) in contacts" :key="i" class="mt-2 rounded-lg border border-line p-3">
                        <p class="text-sm font-medium text-ink">
                            {{ c.name || '—' }}<span v-if="c.role" class="ms-2 text-xs font-normal text-muted">· {{ c.role }}</span>
                        </p>
                        <div class="mt-1 flex flex-col gap-0.5 text-xs text-ink-soft">
                            <span v-if="c.phone">{{ c.phone }}</span>
                            <span v-if="c.email">{{ c.email }}</span>
                            <span v-if="c.notes" class="text-muted">{{ c.notes }}</span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-6 flex flex-wrap gap-2">
                    <VButton v-if="can.download && doc.has_file" variant="secondary" size="sm" icon="download" @click="download">
                        <Bilingual k="documents.download" inline />
                    </VButton>
                    <VButton v-if="can.editDocs" variant="secondary" size="sm" icon="edit" @click="startEdit">
                        <Bilingual k="documents.edit_fields" inline />
                    </VButton>
                    <VButton v-if="can.upload" variant="secondary" size="sm" icon="upload" @click="emit('new-version', doc.type_key)">
                        <Bilingual k="documents.new_version" inline />
                    </VButton>
                    <VButton v-if="can.editDocs && doc.has_file" variant="ghost" size="sm" icon="copy" @click="showReplace = true">
                        <Bilingual k="documents.replace_file" inline />
                    </VButton>
                </div>

                <!-- Version history — never deletable (legal record) -->
                <div v-if="doc.history && doc.history.length" class="mt-6 border-t border-line pt-4">
                    <button type="button"
                        class="flex w-full items-center justify-between text-sm font-medium text-ink-soft hover:text-ink"
                        @click="showHistory = !showHistory">
                        <span>
                            <Bilingual k="documents.versions_history" inline /> ({{ doc.history.length }})
                        </span>
                        <span class="text-xs text-muted">{{ showHistory ? '−' : '+' }}</span>
                    </button>

                    <ul v-if="showHistory" class="mt-3 space-y-1.5">
                        <li v-for="v in doc.history" :key="v.id"
                            class="flex items-center justify-between rounded-md bg-surface-sunken/50 px-3 py-2 text-xs">
                            <span class="tabular-nums text-ink-soft">
                                v{{ v.version }} · {{ v.uploaded_at ?? '—' }}
                                <span v-if="v.uploaded_by" class="text-muted">· {{ v.uploaded_by }}</span>
                                <span v-if="v.expiry_date" class="text-muted">· {{ v.issue_date ?? '—' }} → {{ v.expiry_date }}</span>
                            </span>
                            <button v-if="can.download && v.has_file" type="button"
                                class="rounded-md p-1 text-ink-soft hover:bg-surface-hover hover:text-ink"
                                :aria-label="`download v${v.version}`" @click="downloadVersion(v.id)">
                                <AppIcon name="download" class="h-3.5 w-3.5" />
                            </button>
                            <span v-else class="text-muted"><Bilingual k="documents.no_file" inline /></span>
                        </li>
                    </ul>
                </div>
            </template>

            <!-- EDIT MODE -->
            <form v-else class="mt-4" @submit.prevent="submitEdit">
                <DocumentFieldForm :fields="doc.field_defs" :form="editForm"
                    :ccc="doc.ccc" :dates-editable="false" />
                <div class="mt-5 flex justify-end gap-2">
                    <VButton variant="ghost" size="sm" @click="editing = false">
                        <Bilingual k="common.cancel" inline />
                    </VButton>
                    <VButton type="submit" size="sm" :loading="editForm.processing">
                        <Bilingual k="common.save" inline />
                    </VButton>
                </div>
            </form>
        </template>

        <!-- Replace-file modal -->
        <VModal :open="showReplace" title-key="documents.replace_file" size="sm" @close="showReplace = false">
            <p class="mb-3 text-xs text-ink-soft"><Bilingual k="documents.replace_hint" /></p>
            <form id="doc-replace" @submit.prevent="submitReplace">
                <VFileDrop accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" @files="pickReplace" />
                <p v-if="replaceFile" class="mt-2 truncate text-xs text-ink-soft">{{ replaceFile.name }}</p>
                <p v-if="replaceForm.errors.file" class="mt-1 text-xs text-status-danger">{{ replaceForm.errors.file }}</p>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showReplace = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="doc-replace" :loading="replaceForm.processing" :disabled="!replaceFile">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </VSlideOver>
</template>
