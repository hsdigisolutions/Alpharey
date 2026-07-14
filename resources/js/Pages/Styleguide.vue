<script setup>
/**
 * D1–D3 SIGN-OFF PAGE — a living styleguide rendering every token and
 * component in the system. Available outside production only (/styleguide).
 * This page is the review artifact for design phases D1 (tokens),
 * D2 (components), and D3 (shell — visible around this page).
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AppIcon from '@/Components/AppIcon.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VBulkBar from '@/Components/ui/VBulkBar.vue';
import VButton from '@/Components/ui/VButton.vue';
import VCalendarCell from '@/Components/ui/VCalendarCell.vue';
import VCard from '@/Components/ui/VCard.vue';
import VCheckbox from '@/Components/ui/VCheckbox.vue';
import VCurrencyInput from '@/Components/ui/VCurrencyInput.vue';
import VDateInput from '@/Components/ui/VDateInput.vue';
import VEmptyState from '@/Components/ui/VEmptyState.vue';
import VFileDrop from '@/Components/ui/VFileDrop.vue';
import VInput from '@/Components/ui/VInput.vue';
import VKpiCard from '@/Components/ui/VKpiCard.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPagination from '@/Components/ui/VPagination.vue';
import VPermissionToggle from '@/Components/ui/VPermissionToggle.vue';
import VProgressBar from '@/Components/ui/VProgressBar.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VSlideOver from '@/Components/ui/VSlideOver.vue';
import VStatBar from '@/Components/ui/VStatBar.vue';
import VStatusDot from '@/Components/ui/VStatusDot.vue';
import VTable from '@/Components/ui/VTable.vue';
import VTableToolbar from '@/Components/ui/VTableToolbar.vue';
import VTabs from '@/Components/ui/VTabs.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';
import VTimeline from '@/Components/ui/VTimeline.vue';
import VTimelineItem from '@/Components/ui/VTimelineItem.vue';
import VToggle from '@/Components/ui/VToggle.vue';
import VVatSelect from '@/Components/ui/VVatSelect.vue';

defineProps({
    vatOptions: { type: Array, required: true },
});

// demo state
const text = ref('');
const search = ref('');
const money = ref(1250.5);
const date = ref('2026-07-13');
const checked = ref(true);
const toggled = ref(true);
const vat = ref(null);
const tab = ref('info');
const modalOpen = ref(false);
const slideOpen = ref(false);
const sort = ref({ key: 'name', dir: 'asc' });
const selected = ref(2);

const swatches = [
    ['surface', 'bg-surface border border-line'],
    ['surface-raised', 'bg-surface-raised border border-line'],
    ['surface-sunken', 'bg-surface-sunken border border-line'],
    ['accent', 'bg-accent'],
    ['accent-soft', 'bg-accent-soft'],
    ['status-ok', 'bg-status-ok'],
    ['status-warn', 'bg-status-warn'],
    ['status-danger', 'bg-status-danger'],
    ['status-info', 'bg-status-info'],
    ['status-neutral', 'bg-status-neutral'],
];

const icons = [
    'dashboard', 'companies', 'employees', 'clients', 'projects', 'invoices',
    'attendance', 'payroll', 'calls', 'reports', 'apps', 'plus', 'search',
    'edit', 'trash', 'upload', 'download', 'export', 'filter', 'columns',
    'settings', 'logout', 'camera', 'eye', 'check', 'x', 'bell', 'alert',
    'info', 'calendar', 'euro', 'file', 'user',
];

const tableColumns = [
    { key: 'name', labelKey: 'nav.employees', sortable: true },
    { key: 'company', labelKey: 'nav.companies' },
    { key: 'wage', labelKey: 'nav.payroll', align: 'end', sortable: true },
    { key: 'status', labelKey: 'table.selected' },
];

const calendarStates = ['present', 'late', 'absent', 'leave', 'weekend', 'empty'];
</script>

<template>
    <Head title="Styleguide" />

    <AppLayout>
        <VPageHeader k="app.name">
            <VBadge status="accent">D1 · D2 · D3</VBadge>
        </VPageHeader>

        <div class="space-y-10">
            <!-- ============ D1: TOKENS ============ -->
            <VCard title-key="styleguide.tokens">
                <div class="space-y-6">
                    <div class="grid grid-cols-5 gap-3 md:grid-cols-10">
                        <div v-for="[name, cls] in swatches" :key="name" class="text-center">
                            <div class="h-12 rounded-md" :class="cls" />
                            <p class="mt-1 truncate text-[10px] text-muted">{{ name }}</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <p class="text-2xl font-semibold">Display 24 — Empresa Uno · Madrid</p>
                        <p class="text-lg font-semibold">Title 18 — Detalle del empleado / Employee detail</p>
                        <p class="text-[15px] font-semibold">Section 15 — Información / Information</p>
                        <p class="text-sm">Body 14 — Texto de interfaz por defecto / Default UI text</p>
                        <p class="text-[13px] text-ink-soft">Small 13 — Texto secundario / Secondary text</p>
                        <p class="text-xs font-medium text-ink-soft">CAPTION 12 — CABECERAS DE TABLA / TABLE HEADERS</p>
                        <p class="tabular-nums text-sm">Números tabulares: 1.234,56 € · 08:00–17:30 · 172,5 h</p>
                    </div>

                    <div class="flex flex-wrap items-end gap-6">
                        <div>
                            <p class="mb-1 text-xs text-muted">Bilingual label (stacked)</p>
                            <Bilingual k="nav.attendance" class="text-sm" />
                        </div>
                        <div>
                            <p class="mb-1 text-xs text-muted">Bilingual label (inline)</p>
                            <Bilingual k="nav.attendance" inline class="text-sm" />
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="h-10 w-10 rounded-sm border border-line bg-surface-sunken" title="radius-sm 6px" />
                            <span class="h-10 w-10 rounded-md border border-line bg-surface-sunken" title="radius-md 10px" />
                            <span class="h-10 w-10 rounded-lg border border-line bg-surface-sunken" title="radius-lg 14px" />
                            <span class="h-10 w-10 rounded-xl border border-line bg-surface-sunken" title="radius-xl 20px" />
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="h-10 w-16 rounded-md bg-surface-raised shadow-card" title="shadow-card" />
                            <span class="h-10 w-16 rounded-md bg-surface-raised shadow-raised" title="shadow-raised" />
                            <span class="h-10 w-16 rounded-md bg-surface-raised shadow-overlay" title="shadow-overlay" />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <span v-for="n in 6" :key="n" class="h-8 w-16 rounded-md" :style="{ background: `var(--color-chart-${n})` }" />
                    </div>
                </div>
            </VCard>

            <!-- ============ D2: ICONS ============ -->
            <VCard title-key="styleguide.icons">
                <div class="flex flex-wrap gap-4">
                    <span v-for="icon in icons" :key="icon" class="flex flex-col items-center gap-1 text-muted" :title="icon">
                        <AppIcon :name="icon" class="h-5 w-5 text-ink-soft" />
                        <span class="text-[9px]">{{ icon }}</span>
                    </span>
                </div>
            </VCard>

            <!-- ============ D2: BUTTONS ============ -->
            <VCard title-key="styleguide.buttons">
                <div class="flex flex-wrap items-center gap-3">
                    <VButton variant="primary" icon="plus"><Bilingual k="common.save" inline /></VButton>
                    <VButton variant="secondary" icon="download"><Bilingual k="common.actions" inline /></VButton>
                    <VButton variant="ghost"><Bilingual k="common.cancel" inline /></VButton>
                    <VButton variant="danger" icon="trash"><Bilingual k="common.close" inline /></VButton>
                    <VButton variant="primary" loading><Bilingual k="common.save" inline /></VButton>
                    <VButton variant="primary" disabled><Bilingual k="common.save" inline /></VButton>
                    <VButton variant="secondary" size="sm" icon="export">Excel</VButton>
                </div>
            </VCard>

            <!-- ============ D2: FORMS ============ -->
            <VCard title-key="styleguide.forms">
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <!-- Error state demo lives on email (genuinely required in real
                         forms). NEVER on VAT — VAT is optional everywhere (DECISIONS.md). -->
                    <FormField k="auth.email" required for-id="sg-input" error="Campo obligatorio / Required field">
                        <VInput id="sg-input" v-model="text" type="email" placeholder="nombre@empresa.es" invalid />
                    </FormField>
                    <FormField k="common.search" for-id="sg-search">
                        <VSearchInput id="sg-search" v-model="search" />
                    </FormField>
                    <FormField k="nav.payroll" for-id="sg-money">
                        <VCurrencyInput id="sg-money" v-model="money" />
                    </FormField>
                    <FormField k="nav.attendance" for-id="sg-date">
                        <VDateInput id="sg-date" v-model="date" />
                    </FormField>
                    <FormField k="nav.companies" for-id="sg-select">
                        <VSelect id="sg-select" model-value="1">
                            <option value="1">Empresa Uno — Madrid</option>
                            <option value="2">Empresa Dos — Barcelona</option>
                        </VSelect>
                    </FormField>
                    <FormField k="vat.label">
                        <VVatSelect v-model="vat" :options="vatOptions" />
                    </FormField>
                    <FormField k="common.notifications">
                        <VTextarea v-model="text" :rows="2" placeholder="Notas… / Notes…" />
                    </FormField>
                    <div class="flex items-center gap-6 pt-6">
                        <VCheckbox v-model="checked"><Bilingual k="auth.remember_me" inline /></VCheckbox>
                        <VToggle v-model="toggled" label="Demo toggle" />
                    </div>
                    <FormField k="nav.documents" class="md:col-span-2 lg:col-span-1">
                        <VFileDrop capture multiple />
                    </FormField>
                </div>
            </VCard>

            <!-- ============ D2: VAT DROPDOWN (DECISIONS.md) ============ -->
            <VCard title-key="styleguide.vat">
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField k="vat.label">
                        <VVatSelect v-model="vat" :options="vatOptions" />
                    </FormField>
                    <div class="rounded-md bg-surface-sunken p-3 text-sm">
                        <p class="tabular-nums flex justify-between"><span>Subtotal</span><span>1.000,00 €</span></p>
                        <p class="tabular-nums flex justify-between text-ink-soft">
                            <span>IVA / VAT</span>
                            <span>{{ vat === null ? '—' : `${vatOptions.find(o => o.value === vat)?.percent ?? 0}%` }}</span>
                        </p>
                    </div>
                </div>
            </VCard>

            <!-- ============ D2: STATUS ============ -->
            <VCard title-key="styleguide.status">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <VBadge status="ok">Válido / Valid</VBadge>
                        <VBadge status="warn">Por vencer / Expiring</VBadge>
                        <VBadge status="danger">Vencido / Expired</VBadge>
                        <VBadge status="info">Ausencia / Leave</VBadge>
                        <VBadge status="neutral">Sin subir / Missing</VBadge>
                        <VBadge status="accent">Desplegado / Deployed · Empresa Dos</VBadge>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="flex items-center gap-1.5 text-sm"><VStatusDot status="ok" /> Al día / On track</span>
                        <span class="flex items-center gap-1.5 text-sm"><VStatusDot status="warn" /> Hoy / Due today</span>
                        <span class="flex items-center gap-1.5 text-sm"><VStatusDot status="danger" pulse /> Atrasado / Overdue</span>
                    </div>
                    <VAlert status="warn">
                        <Bilingual k="common.coming_soon" />
                    </VAlert>
                    <div class="space-y-2.5">
                        <VStatBar label="Empresa Uno" sublabel="Madrid" :percent="97" />
                        <VStatBar label="Empresa Dos" sublabel="Barcelona" :percent="82" />
                        <VStatBar label="Empresa Tres" sublabel="Valencia" :percent="54" />
                    </div>
                    <VProgressBar :percent="64" />
                </div>
            </VCard>

            <!-- ============ D2: KPI + AVATARS ============ -->
            <VCard title-key="styleguide.cards">
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <VKpiCard k="nav.employees" value="128" icon="employees" />
                    <VKpiCard k="nav.invoices" value="46.900 €" icon="euro" status="ok" />
                    <VKpiCard k="nav.compliance" value="3" icon="alert" status="danger" />
                    <VKpiCard k="nav.attendance" value="93 / 128" icon="attendance" />
                </div>
                <div class="mt-4 flex items-center gap-3">
                    <VAvatar name="María García" size="lg" />
                    <VAvatar name="José Luis Pérez" />
                    <VAvatar name="Ana" size="sm" />
                </div>
            </VCard>

            <!-- ============ D2: TABS ============ -->
            <VCard title-key="styleguide.tabs" :padded="false">
                <div class="px-4 pt-2">
                    <VTabs v-model="tab" :tabs="[
                        { key: 'info', labelKey: 'nav.employees' },
                        { key: 'docs', labelKey: 'nav.compliance', count: 13 },
                        { key: 'att', labelKey: 'nav.attendance' },
                        { key: 'pay', labelKey: 'nav.payroll' },
                    ]" />
                </div>
                <p class="px-4 py-6 text-sm text-muted">Tab: {{ tab }}</p>
            </VCard>

            <!-- ============ D2: TABLE SYSTEM ============ -->
            <VCard title-key="styleguide.table" :padded="false">
                <div class="p-4">
                    <VTableToolbar v-model:search="search">
                        <template #actions>
                            <VButton icon="plus" size="sm"><Bilingual k="common.save" inline /></VButton>
                        </template>
                    </VTableToolbar>

                    <VBulkBar :count="selected" @clear="selected = 0">
                        <VButton variant="secondary" size="sm" icon="download">PDF</VButton>
                        <VButton variant="danger" size="sm" icon="trash"><Bilingual k="common.close" inline /></VButton>
                    </VBulkBar>

                    <VTable :columns="tableColumns" :sort="sort" selectable
                        @sort="(key) => (sort = { key, dir: sort.key === key && sort.dir === 'asc' ? 'desc' : 'asc' })">
                        <tr v-for="(row, i) in [
                            { name: 'María García', company: 'Empresa Uno', wage: '2.150,00 €', ok: true },
                            { name: 'Ahmed Ben Ali', company: 'Empresa Dos', wage: '14,50 €/h', ok: true, deployed: true },
                            { name: 'Carlos Ruiz', company: 'Empresa Uno', wage: '1.980,00 €', ok: false },
                        ]" :key="i" class="hover:bg-surface-hover">
                            <td class="px-3 py-2.5"><input type="checkbox" :checked="i < selected" class="h-4 w-4 rounded-sm accent-[var(--color-accent)]" /></td>
                            <td class="px-3 py-2.5">
                                <span class="flex items-center gap-2">
                                    <VAvatar :name="row.name" size="sm" />
                                    <span class="text-sm font-medium">{{ row.name }}</span>
                                    <VBadge v-if="row.deployed" status="accent">Desplegado</VBadge>
                                </span>
                            </td>
                            <td class="px-3 py-2.5 text-sm text-ink-soft">{{ row.company }}</td>
                            <td class="tabular-nums px-3 py-2.5 text-end text-sm">{{ row.wage }}</td>
                            <td class="px-3 py-2.5">
                                <VBadge :status="row.ok ? 'ok' : 'danger'">{{ row.ok ? 'Activo / Active' : 'Docs vencidos / Expired' }}</VBadge>
                            </td>
                        </tr>
                    </VTable>

                    <VPagination :page="1" :pages="6" :per-page="25" :total="128" />
                </div>
            </VCard>

            <!-- ============ D2: CALENDAR CELLS ============ -->
            <VCard title-key="styleguide.calendar">
                <div class="grid grid-cols-3 gap-2 md:grid-cols-6">
                    <div v-for="state in calendarStates" :key="state">
                        <VCalendarCell :status="state" :hours="state === 'absent' || state === 'weekend' || state === 'empty' ? null : 8"
                            :label="state === 'present' || state === 'late' ? 'Obra Castellana 120' : null" />
                        <p class="mt-1 text-center text-[10px] text-muted">{{ state }}</p>
                    </div>
                </div>
            </VCard>

            <!-- ============ D2: PERMISSION MATRIX CELLS ============ -->
            <VCard title-key="styleguide.permissions">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-max text-sm">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="px-3 py-2 text-start"><Bilingual k="nav.apps" class="text-xs font-semibold text-ink-soft" /></th>
                                <th v-for="action in ['Ver / View', 'Crear / Create', 'Editar / Edit', 'Eliminar / Delete']" :key="action"
                                    class="px-3 py-2 text-center text-xs font-semibold text-ink-soft">{{ action }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr>
                                <td class="px-3 py-2.5"><Bilingual k="nav.employees" class="text-sm" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="true" label="Empleados — Ver" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="true" label="Empleados — Crear" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="false" label="Empleados — Editar" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="false" label="Empleados — Eliminar" /></td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2.5"><Bilingual k="nav.reports" class="text-sm" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="true" label="Informes — Ver" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="null" label="Informes — Crear" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="null" label="Informes — Editar" /></td>
                                <td class="px-3 py-2.5 text-center"><VPermissionToggle :model-value="null" label="Informes — Eliminar" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </VCard>

            <!-- ============ D2: TIMELINE ============ -->
            <VCard title-key="styleguide.timeline">
                <VTimeline>
                    <VTimelineItem time="13/07/2026 09:42" author="María García" type-label="Llamada / Call" type-status="info">
                        Confirmada la asistencia del equipo al proyecto de la Castellana.
                    </VTimelineItem>
                    <VTimelineItem time="12/07/2026 17:05" author="Admin Empresa Uno" type-label="Incidencia / Issue" type-status="warn">
                        Falta el certificado médico — recordatorio enviado.
                    </VTimelineItem>
                    <VTimelineItem time="10/07/2026 08:30" author="Super Admin" type-label="Nota / Note" type-status="neutral">
                        Alta inicial del empleado desde la migración del sistema anterior.
                    </VTimelineItem>
                </VTimeline>
            </VCard>

            <!-- ============ D2: OVERLAYS + EMPTY ============ -->
            <VCard title-key="styleguide.overlays">
                <div class="flex flex-wrap gap-3">
                    <VButton variant="secondary" @click="modalOpen = true">Modal</VButton>
                    <VButton variant="secondary" @click="slideOpen = true">Slide-over</VButton>
                </div>
                <VEmptyState icon="calls" title-key="common.empty_title" message-key="common.empty_message">
                    <VButton icon="plus" size="sm"><Bilingual k="common.save" inline /></VButton>
                </VEmptyState>
            </VCard>
        </div>

        <VModal :open="modalOpen" title-key="styleguide.modal_title" @close="modalOpen = false">
            <div class="space-y-4">
                <FormField k="auth.email"><VInput type="email" /></FormField>
                <FormField k="vat.label"><VVatSelect v-model="vat" :options="vatOptions" /></FormField>
            </div>
            <template #footer>
                <VButton variant="ghost" @click="modalOpen = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton @click="modalOpen = false"><Bilingual k="common.save" inline /></VButton>
            </template>
        </VModal>

        <VSlideOver :open="slideOpen" title-key="styleguide.slideover_title" @close="slideOpen = false">
            <p class="text-sm text-ink-soft">
                <Bilingual k="common.coming_soon" />
            </p>
            <template #footer>
                <VButton variant="ghost" @click="slideOpen = false"><Bilingual k="common.close" inline /></VButton>
            </template>
        </VSlideOver>
    </AppLayout>
</template>
