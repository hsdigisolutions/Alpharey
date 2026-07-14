<script setup>
/**
 * Unified document panel (Screens 04/06): one row per defined type —
 * Yes/No flag, file, issue/expiry, traffic-light status, version,
 * upload/download/delete — plus free "custom" slots. Works for both
 * employees (grouped sets) and companies (the 13 official types).
 */
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';

const props = defineProps({
    entityType: { type: String, required: true }, // employee | company
    entityId: { type: Number, required: true },
    documents: { type: Array, required: true },
    /** employee: {category: {typeKey: cfg}} · company: {typeKey: cfg} */
    sets: { type: Object, required: true },
    can: { type: Object, required: true }, // upload / download / deleteDocs
});

const groups = computed(() => {
    if (props.entityType === 'company') {
        return [{ category: 'company', types: props.sets }];
    }

    return Object.entries(props.sets).map(([category, types]) => ({ category, types }));
});

const customDocs = computed(() => props.documents.filter((d) => d.category === 'custom'));

function currentDoc(typeKey) {
    return props.documents.find((d) => d.type_key === typeKey && d.category !== 'custom') ?? null;
}

const statusBadge = { ok: 'ok', warn: 'warn', danger: 'danger', neutral: 'neutral', exempt: 'info' };

/* ---------- upload modal ---------- */
const uploadTarget = ref(null); // { typeKey, category, cfg, custom }
const file = ref(null);

const uploadForm = useForm({
    entity_type: props.entityType,
    entity_id: props.entityId,
    type_key: '',
    category: '',
    name: '',
    has_flag: false,
    issue_date: null,
    expiry_date: null,
    notes: '',
    file: null,
});

function openUpload(typeKey, category, cfg, custom = false) {
    const existing = custom ? null : currentDoc(typeKey);
    uploadTarget.value = { typeKey, category, cfg, custom };
    uploadForm.clearErrors();
    uploadForm.type_key = typeKey;
    uploadForm.category = category;
    uploadForm.name = custom ? '' : null;
    uploadForm.has_flag = existing?.has_flag ?? false;
    uploadForm.issue_date = existing?.issue_date ?? null;
    uploadForm.expiry_date = existing?.expiry_date ?? null;
    uploadForm.notes = '';
    uploadForm.file = null;
    file.value = null;
}

function pickFile(files) {
    file.value = files[0];
    uploadForm.file = files[0];
}

function submitUpload() {
    uploadForm.post('/documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (uploadTarget.value = null),
    });
}

function download(doc) {
    window.location.href = `/documents/${doc.id}/download`;
}

function removeDoc(doc) {
    router.delete(`/documents/${doc.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <section v-for="group in groups" :key="group.category">
            <Bilingual :k="`documents.cat_${group.category}`" class="mb-2 text-[15px] font-semibold" />

            <div class="overflow-x-auto rounded-lg border border-line bg-surface-raised">
                <table class="w-full min-w-max text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="(cfg, typeKey) in group.types" :key="typeKey" class="hover:bg-surface-hover">
                            <td class="px-3 py-2.5">
                                <Bilingual :k="`doc_types.${typeKey}`" class="text-sm" />
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                <template v-if="cfg.flag">
                                    <VBadge :status="currentDoc(typeKey)?.has_flag ? 'ok' : 'neutral'">
                                        {{ currentDoc(typeKey)?.has_flag ? 'Sí / Yes' : 'No' }}
                                    </VBadge>
                                </template>
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="max-w-40 truncate px-3 py-2.5 text-xs text-ink-soft">
                                {{ currentDoc(typeKey)?.original_name ?? '—' }}
                            </td>
                            <td class="tabular-nums px-3 py-2.5 text-xs text-muted">
                                {{ currentDoc(typeKey)?.expiry_date ?? '—' }}
                                <span v-if="currentDoc(typeKey)?.days_left !== null && currentDoc(typeKey)"
                                    class="ms-1">({{ currentDoc(typeKey).days_left }}d)</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <VBadge v-if="currentDoc(typeKey)" :status="statusBadge[currentDoc(typeKey).status]">
                                    <Bilingual :k="`documents.status_${currentDoc(typeKey).status}`" inline />
                                </VBadge>
                                <VBadge v-else status="neutral">
                                    <Bilingual k="documents.status_neutral" inline />
                                </VBadge>
                            </td>
                            <td class="tabular-nums px-3 py-2.5 text-center text-xs text-muted">
                                v{{ currentDoc(typeKey)?.version ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex items-center justify-end gap-1">
                                    <button v-if="can.upload" type="button"
                                        class="rounded-md p-1.5 text-ink-soft hover:bg-surface-sunken hover:text-ink"
                                        :aria-label="`${typeKey} — upload`"
                                        @click="openUpload(typeKey, group.category === 'company' ? 'company' : group.category, cfg)">
                                        <AppIcon name="upload" class="h-4 w-4" />
                                    </button>
                                    <button v-if="can.download && currentDoc(typeKey)?.has_file" type="button"
                                        class="rounded-md p-1.5 text-ink-soft hover:bg-surface-sunken hover:text-ink"
                                        :aria-label="`${typeKey} — download`"
                                        @click="download(currentDoc(typeKey))">
                                        <AppIcon name="download" class="h-4 w-4" />
                                    </button>
                                    <button v-if="can.deleteDocs && currentDoc(typeKey)" type="button"
                                        class="rounded-md p-1.5 text-status-danger hover:bg-status-danger-soft"
                                        :aria-label="`${typeKey} — delete`"
                                        @click="removeDoc(currentDoc(typeKey))">
                                        <AppIcon name="trash" class="h-4 w-4" />
                                    </button>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Custom slots -->
        <section>
            <div class="mb-2 flex items-center justify-between">
                <Bilingual k="documents.cat_custom" class="text-[15px] font-semibold" />
                <VButton v-if="can.upload" variant="secondary" size="sm" icon="plus"
                    @click="openUpload(`custom_${Date.now()}`, 'custom', { flag: false, file: true, expiry: true }, true)">
                    <Bilingual k="documents.custom" inline />
                </VButton>
            </div>
            <div v-if="customDocs.length" class="overflow-x-auto rounded-lg border border-line bg-surface-raised">
                <table class="w-full min-w-max text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="doc in customDocs" :key="doc.id" class="hover:bg-surface-hover">
                            <td class="px-3 py-2.5 text-sm font-medium">{{ doc.name }}</td>
                            <td class="max-w-40 truncate px-3 py-2.5 text-xs text-ink-soft">{{ doc.original_name ?? '—' }}</td>
                            <td class="tabular-nums px-3 py-2.5 text-xs text-muted">{{ doc.expiry_date ?? '—' }}</td>
                            <td class="px-3 py-2.5">
                                <VBadge :status="statusBadge[doc.status]">
                                    <Bilingual :k="`documents.status_${doc.status}`" inline />
                                </VBadge>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex items-center justify-end gap-1">
                                    <button v-if="can.download && doc.has_file" type="button"
                                        class="rounded-md p-1.5 text-ink-soft hover:bg-surface-sunken hover:text-ink"
                                        :aria-label="`${doc.name} — download`" @click="download(doc)">
                                        <AppIcon name="download" class="h-4 w-4" />
                                    </button>
                                    <button v-if="can.deleteDocs" type="button"
                                        class="rounded-md p-1.5 text-status-danger hover:bg-status-danger-soft"
                                        :aria-label="`${doc.name} — delete`" @click="removeDoc(doc)">
                                        <AppIcon name="trash" class="h-4 w-4" />
                                    </button>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Upload modal -->
        <VModal :open="uploadTarget !== null" title-key="documents.upload" size="sm" @close="uploadTarget = null">
            <form v-if="uploadTarget" id="doc-upload" class="space-y-3" @submit.prevent="submitUpload">
                <p v-if="!uploadTarget.custom" class="text-sm font-medium">
                    <Bilingual :k="`doc_types.${uploadTarget.typeKey}`" />
                </p>
                <FormField v-else k="documents.custom_name" :error="uploadForm.errors.name" required>
                    <VInput v-model="uploadForm.name" :invalid="Boolean(uploadForm.errors.name)" />
                </FormField>

                <VFileDrop capture accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" @files="pickFile" />
                <p v-if="file" class="truncate text-xs text-ink-soft">{{ file.name }}</p>
                <p v-if="uploadForm.errors.file" class="text-xs text-status-danger">{{ uploadForm.errors.file }}</p>

                <VCheckbox v-if="uploadTarget.cfg.flag" v-model="uploadForm.has_flag">
                    <Bilingual k="documents.flag" inline class="text-sm" />
                </VCheckbox>

                <div class="grid grid-cols-2 gap-3">
                    <FormField k="documents.issue_date" :error="uploadForm.errors.issue_date">
                        <VDateInput v-model="uploadForm.issue_date" />
                    </FormField>
                    <FormField v-if="uploadTarget.cfg.expiry !== false" k="documents.expiry_date" :error="uploadForm.errors.expiry_date">
                        <VDateInput v-model="uploadForm.expiry_date" />
                    </FormField>
                </div>

                <FormField k="documents.notes" :error="uploadForm.errors.notes">
                    <VInput v-model="uploadForm.notes" />
                </FormField>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="uploadTarget = null"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="doc-upload" :loading="uploadForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </div>
</template>
