<script setup>
/**
 * Smart company-document detail — a right-hand slide-over opened from a document
 * row. Shows the current version (who/when/status), the type-specific fields and
 * the point-of-contact block, with Download / Edit fields / New version / Replace
 * actions. Previous versions (Step 4) render collapsed at the bottom.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { tPair } from '@/translate';
import AppIcon from '@/Components/AppIcon.vue';
import DocumentFieldForm from '@/Components/Documents/DocumentFieldForm.vue';
import VButton from '@/Components/ui/VButton.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VModal from '@/Components/ui/VModal.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';

const props = defineProps({
    doc: { type: Object, default: null }, // the current-version row payload
    can: { type: Object, required: true }, // upload / download / editDocs
});

const emit = defineEmits(['close', 'new-version', 'refresh']);

// Full class strings (Tailwind scans these literals) for the status accent.
const accent = {
    ok: { strip: 'bg-status-ok', soft: 'bg-status-ok-soft', text: 'text-status-ok' },
    warn: { strip: 'bg-status-warn', soft: 'bg-status-warn-soft', text: 'text-status-warn' },
    danger: { strip: 'bg-status-danger', soft: 'bg-status-danger-soft', text: 'text-status-danger' },
    neutral: { strip: 'bg-status-neutral', soft: 'bg-status-neutral-soft', text: 'text-status-neutral' },
    missing: { strip: 'bg-status-neutral', soft: 'bg-status-neutral-soft', text: 'text-status-neutral' },
    exempt: { strip: 'bg-status-info', soft: 'bg-status-info-soft', text: 'text-status-info' },
};

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
    previewOpen.value = false;
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

function isEmpty(field) {
    return displayValue(field) === '—';
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

// Inline preview: images render in a lightbox, PDFs in an embedded viewer.
const previewOpen = ref(false);
const previewIsImage = computed(() => (props.doc?.mime ?? '').startsWith('image/'));
const previewIsPdf = computed(() => props.doc?.mime === 'application/pdf');
const canPreview = computed(() => props.doc?.has_file && (previewIsImage.value || previewIsPdf.value));
const previewUrl = computed(() => (props.doc ? `/documents/${props.doc.id}/preview` : ''));
</script>

<template>
    <VSlideOver :open="doc !== null" title-key="documents.detail" width="md:max-w-xl" @close="emit('close')">
        <template v-if="doc">
            <!-- Status-accented header card -->
            <div class="relative overflow-hidden rounded-xl border border-line bg-surface-raised shadow-card">
                <span class="absolute inset-y-0 start-0 w-1" :class="(accent[doc.status] ?? accent.neutral).strip" />
                <div class="p-4 ps-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-[17px] font-semibold leading-tight">
                                <Bilingual :k="`doc_types.${doc.type_key}`" />
                            </h3>
                            <p class="tabular-nums mt-1 text-xs text-muted">
                                <Bilingual k="documents.current_version" inline /> · v{{ doc.version }}
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="[(accent[doc.status] ?? accent.neutral).soft, (accent[doc.status] ?? accent.neutral).text]">
                            <span class="h-1.5 w-1.5 rounded-full" :class="(accent[doc.status] ?? accent.neutral).strip" />
                            <Bilingual :k="doc.status === 'missing' ? 'doc_center.status_missing' : `documents.status_${doc.status}`" inline />
                            <span v-if="doc.days_left !== null" class="tabular-nums">· {{ doc.days_left }}d</span>
                        </span>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line pt-2.5 text-xs text-ink-soft">
                        <span class="flex items-center gap-1.5"><AppIcon name="calendar" class="h-3.5 w-3.5 text-muted" />{{ doc.uploaded_at ?? '—' }}</span>
                        <span v-if="doc.uploaded_by" class="flex items-center gap-1.5"><AppIcon name="user" class="h-3.5 w-3.5 text-muted" />{{ doc.uploaded_by }}</span>
                        <span class="flex min-w-0 items-center gap-1.5">
                            <AppIcon :name="doc.has_file ? 'file' : 'x'" class="h-3.5 w-3.5 shrink-0 text-muted" />
                            <span class="truncate">{{ doc.has_file ? doc.original_name : $tPair('documents.no_file') }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- VIEW MODE -->
            <template v-if="!editing">
                <!-- Type-specific fields -->
                <section v-if="doc.field_defs && doc.field_defs.length" class="mt-4 rounded-xl border border-line bg-surface-raised p-4">
                    <p class="mb-3 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        <span class="h-3 w-0.5 rounded-full bg-accent" /><Bilingual k="doc_fields.section_details" inline />
                    </p>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-for="field in doc.field_defs" :key="field.key"
                            :class="field.type === 'textarea' || field.type === 'checkbox_group' ? 'sm:col-span-2' : ''">
                            <dt class="text-[11px] uppercase tracking-wide text-muted"><Bilingual :k="field.label" inline /></dt>
                            <dd class="mt-0.5 text-sm" :class="isEmpty(field) ? 'text-faint' : 'font-medium text-ink'">{{ displayValue(field) }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Contacts (every type; a document may have several) -->
                <section class="mt-4">
                    <p class="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        <span class="h-3 w-0.5 rounded-full bg-accent" /><Bilingual k="doc_fields.contacts_section" inline />
                        <span v-if="contacts.length" class="tabular-nums text-muted">· {{ contacts.length }}</span>
                    </p>
                    <p v-if="contacts.length === 0" class="text-sm text-faint">—</p>
                    <div class="grid gap-2">
                        <div v-for="(c, i) in contacts" :key="i" class="flex gap-3 rounded-xl border border-line bg-surface-raised p-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent">
                                <AppIcon name="user" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                    {{ c.name || '—' }}
                                    <span v-if="c.role" class="rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-normal text-ink-soft">{{ c.role }}</span>
                                </p>
                                <div class="mt-1 flex flex-col gap-0.5 text-xs text-ink-soft">
                                    <span v-if="c.phone" class="flex items-center gap-1.5"><AppIcon name="calls" class="h-3 w-3 text-muted" />{{ c.phone }}</span>
                                    <span v-if="c.email" class="truncate">{{ c.email }}</span>
                                    <span v-if="c.notes" class="text-muted">{{ c.notes }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Actions -->
                <div class="mt-5 flex flex-wrap gap-2">
                    <VButton v-if="can.download && canPreview" variant="secondary" size="sm" icon="eye" @click="previewOpen = true">
                        <Bilingual k="documents.preview" inline />
                    </VButton>
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
                <div v-if="doc.history && doc.history.length" class="mt-5 border-t border-line pt-4">
                    <button type="button"
                        class="flex w-full items-center justify-between text-sm font-medium text-ink-soft hover:text-ink"
                        @click="showHistory = !showHistory">
                        <span class="flex items-center gap-2">
                            <Bilingual k="documents.versions_history" inline />
                            <span class="tabular-nums rounded-full bg-surface-sunken px-2 py-0.5 text-[11px]">{{ doc.history.length }}</span>
                        </span>
                        <span class="text-xs text-muted">{{ showHistory ? '−' : '+' }}</span>
                    </button>

                    <ol v-if="showHistory" class="relative mt-3 space-y-2 ps-4">
                        <span class="absolute inset-y-1 start-1 w-px bg-line" />
                        <li v-for="v in doc.history" :key="v.id"
                            class="relative flex items-center justify-between rounded-lg bg-surface-sunken/50 px-3 py-2 text-xs">
                            <span class="absolute -start-3 top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-line-strong" />
                            <span class="tabular-nums text-ink-soft">
                                <span class="font-medium text-ink">v{{ v.version }}</span> · {{ v.uploaded_at ?? '—' }}
                                <span v-if="v.uploaded_by" class="text-muted">· {{ v.uploaded_by }}</span>
                            </span>
                            <button v-if="can.download && v.has_file" type="button"
                                class="rounded-md p-1 text-ink-soft hover:bg-surface-hover hover:text-ink"
                                :aria-label="`download v${v.version}`" @click="downloadVersion(v.id)">
                                <AppIcon name="download" class="h-3.5 w-3.5" />
                            </button>
                            <span v-else class="text-muted"><Bilingual k="documents.no_file" inline /></span>
                        </li>
                    </ol>
                </div>
            </template>

            <!-- EDIT MODE -->
            <form v-else class="mt-4" @submit.prevent="submitEdit">
                <DocumentFieldForm :fields="doc.field_defs" :form="editForm"
                    :ccc="doc.ccc" :dates-editable="true" />
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

        <!-- Inline preview: image lightbox / embedded PDF viewer (download stays available) -->
        <div v-if="previewOpen && doc" class="fixed inset-0 z-[60] flex flex-col bg-black/85"
            @click.self="previewOpen = false">
            <div class="flex items-center justify-between gap-3 px-4 py-3">
                <span class="truncate text-sm font-medium text-white/90">{{ doc.original_name ?? '—' }}</span>
                <div class="flex items-center gap-1">
                    <button type="button" class="rounded-md p-2 text-white/80 hover:bg-white/10 hover:text-white"
                        :title="$t('documents.download')" @click="download">
                        <AppIcon name="download" class="h-5 w-5" />
                    </button>
                    <button type="button" class="rounded-md p-2 text-white/80 hover:bg-white/10 hover:text-white"
                        :title="$t('common.close')" @click="previewOpen = false">
                        <AppIcon name="x" class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <div class="flex flex-1 items-center justify-center overflow-auto p-4 pt-0">
                <img v-if="previewIsImage" :src="previewUrl" :alt="doc.original_name ?? ''"
                    class="max-h-full max-w-full rounded-lg object-contain" @click.stop />
                <iframe v-else-if="previewIsPdf" :src="previewUrl" title="PDF"
                    class="h-full w-full rounded-lg bg-white" @click.stop></iframe>
            </div>
        </div>
    </VSlideOver>
</template>
