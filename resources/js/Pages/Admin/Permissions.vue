<script setup>
/**
 * Screen 17 — Permission Matrix (Super Admin + Company Admin).
 * Left: users of the active company. Right: module × action toggle grid
 * with presets and copy-from. Admin-role users bypass the matrix.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
import VConfirmDialog from '@/Components/ui/VConfirmDialog.vue';
import VInput from '@/Components/ui/VInput.vue';
import VModal from '@/Components/ui/VModal.vue';
import VPageHeader from '@/Components/ui/VPageHeader.vue';
import VPermissionToggle from '@/Components/ui/VPermissionToggle.vue';
import VSearchInput from '@/Components/ui/VSearchInput.vue';
import VSelect from '@/Components/ui/VSelect.vue';
import VToggle from '@/Components/ui/VToggle.vue';

const props = defineProps({
    users: { type: Array, required: true },
    matrix: { type: Array, required: true }, // [{module, actions: {view: bool applicable, …}}]
    availableCompanies: { type: Array, default: () => [] },
    selectedUser: { type: Number, default: null },
    permissions: { type: Object, default: () => ({}) },
    copySourcePermissions: { type: Object, default: null },
});

const actionOrder = ['view', 'create', 'edit', 'delete', 'upload', 'download', 'export', 'approve'];

const confirm = ref({ open: false, message: '', fn: null });
function askDelete(message, fn) { confirm.value = { open: true, message, fn }; }
function runDelete() { confirm.value.fn?.(); confirm.value.open = false; }

const search = ref('');
const filteredUsers = computed(() =>
    props.users.filter((user) =>
        (user.name + user.email).toLowerCase().includes(search.value.toLowerCase()),
    ),
);

const selected = computed(() => props.users.find((u) => u.id === props.selectedUser) ?? null);

// Compact role tag: SA stands out on the accent tint; Admin/Manager stay muted.
function roleTag(role) {
    return role === 'super_admin' ? 'bg-accent-soft text-accent' : 'bg-surface-sunken text-ink-soft';
}

// Local editable grid state: { [module]: { [action]: bool } }
const grid = reactive({});

function loadGrid(source) {
    props.matrix.forEach((row) => {
        grid[row.module] = {};
        actionOrder.forEach((action) => {
            grid[row.module][action] = row.actions[action]
                ? Boolean(source?.[row.module]?.[action])
                : null; // not applicable
        });
    });
}

watch(() => props.permissions, (value) => loadGrid(value), { immediate: true, deep: true });

// Copy-from arrives via partial reload, then applies to the local grid
watch(() => props.copySourcePermissions, (value) => {
    if (value !== null) loadGrid(value);
});

function selectUser(user) {
    router.get('/admin/permissions', { user: user.id }, {
        preserveScroll: true,
        preserveState: true,
        only: ['selectedUser', 'permissions'],
    });
}

function applyPreset(preset) {
    Object.keys(grid).forEach((module) => {
        Object.keys(grid[module]).forEach((action) => {
            if (grid[module][action] === null) return;
            grid[module][action] = preset === 'full' ? true : preset === 'read' ? action === 'view' : false;
        });
    });
}

const copyFrom = ref('');

function requestCopy() {
    if (!copyFrom.value) return;
    router.get('/admin/permissions', { user: props.selectedUser, copy_from: copyFrom.value }, {
        preserveScroll: true,
        preserveState: true,
        only: ['copySourcePermissions'],
    });
}

const saving = ref(false);

function save() {
    saving.value = true;
    router.put(`/admin/permissions/${props.selectedUser}`, {
        permissions: Object.entries(grid).map(([module, actions]) => ({
            module,
            ...Object.fromEntries(
                actionOrder.map((action) => [`can_${action}`, actions[action] === true]),
            ),
        })),
    }, {
        preserveScroll: true,
        onFinish: () => (saving.value = false),
    });
}

// --- Second factor ---
// Only a Super Admin holds the lost-phone lever (TwoFactorController::reset
// enforces it server-side too — this only decides whether to draw the button).
const page = usePage();
const isSuperAdmin = computed(() => page.props.auth?.user?.role === 'super_admin');

function resetTwoFactor(user) {
    if (!window.confirm(`${user.name} — ${t('two_factor.reset_confirm')}`)) return;

    router.post(`/admin/permissions/${user.id}/reset-2fa`, {}, {
        preserveScroll: true,
        onSuccess: () => { showUserModal.value = false; },
    });
}

// Super Admin fulfils a user's "request password reset": fires the reset-link
// email (SA never sets the password) and clears the pending flag.
function sendPasswordReset(user) {
    if (!window.confirm(`${user.name} — ${t('permissions.password_reset_confirm')}`)) return;

    router.post(`/admin/permissions/${user.id}/reset-password`, {}, {
        preserveScroll: true,
        onSuccess: () => { showUserModal.value = false; },
    });
}

// --- User create/edit modal ---
const showUserModal = ref(false);
const editingUser = ref(null);

const userForm = useForm({
    name: '', email: '', password: '', role: 'manager', locale: 'es', active: true,
});

function openCreate() {
    editingUser.value = null;
    userForm.reset();
    userForm.clearErrors();
    showUserModal.value = true;
}

function openEdit(user) {
    editingUser.value = user;
    userForm.name = user.name;
    userForm.email = user.email;
    userForm.password = '';
    userForm.role = user.role;
    userForm.locale = user.locale ?? 'es';
    userForm.active = user.active;
    userForm.clearErrors();
    showUserModal.value = true;
}

// The modal captures a snapshot; company assignment posts refresh the page
// props, so the companies panel reads the LIVE row for the same user.
const editingUserLive = computed(
    () => props.users.find((u) => u.id === editingUser.value?.id) ?? editingUser.value,
);

// --- Company assignment (Super Admin anywhere; Admin within own companies) ---
const companyToAssign = ref('');

const assignableCompanies = computed(() => {
    const assigned = new Set((editingUserLive.value?.assigned_companies ?? []).map((c) => c.id));
    return props.availableCompanies.filter((c) => !assigned.has(c.id));
});

function assignCompany() {
    if (!companyToAssign.value || !editingUser.value) return;
    router.post(`/admin/permissions/${editingUser.value.id}/companies`,
        { company_id: companyToAssign.value },
        { preserveScroll: true, onSuccess: () => (companyToAssign.value = '') });
}

function removeCompany(companyId) {
    if (!editingUser.value) return;
    const company = props.availableCompanies.find((c) => c.id === companyId);
    askDelete(company?.name ?? '',
        () => router.delete(`/admin/permissions/${editingUser.value.id}/companies/${companyId}`,
            { preserveScroll: true }));
}

function deleteUser(user) {
    askDelete(user.name,
        () => router.delete(`/admin/users/${user.id}`, { preserveScroll: true }));
}

function submitUser() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showUserModal.value = false;
        },
    };

    if (editingUser.value) {
        userForm.put(`/admin/users/${editingUser.value.id}`, options);
    } else {
        userForm.post('/admin/users', options);
    }
}
</script>

<template>
    <Head :title="$t('permissions.title')" />

    <AppLayout>
        <VPageHeader k="permissions.title">
            <VButton icon="plus" @click="openCreate">
                <Bilingual k="permissions.new_user" inline />
            </VButton>
        </VPageHeader>

        <div class="space-y-5">
            <!-- User directory: every user as a full row — name/email, role,
                 assigned companies, status, actions. Clicking a row opens the
                 permission matrix for that user below the table. -->
            <div class="rounded-lg border border-line bg-surface-raised shadow-card">
                <div class="border-b border-line px-4 py-3">
                    <VSearchInput v-model="search" class="max-w-xs" />
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-max text-sm">
                        <thead>
                            <tr class="border-b border-line bg-surface-sunken/60 text-xs uppercase tracking-wide text-muted">
                                <th class="px-4 py-2.5 text-start font-semibold"><Bilingual k="permissions.user" inline /></th>
                                <th class="px-4 py-2.5 text-start font-semibold"><Bilingual k="permissions.role" inline /></th>
                                <th class="px-4 py-2.5 text-start font-semibold"><Bilingual k="permissions.assigned_companies" inline /></th>
                                <th class="px-4 py-2.5 text-start font-semibold"><Bilingual k="permissions.status" inline /></th>
                                <th class="px-4 py-2.5 text-end font-semibold"><Bilingual k="common.actions" inline /></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="user in filteredUsers" :key="user.id"
                                class="cursor-pointer transition-colors"
                                :class="user.id === props.selectedUser ? 'bg-accent-soft/60' : 'hover:bg-surface-hover'"
                                @click="selectUser(user)">
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        <VAvatar :name="user.name" size="sm" />
                                        <div class="min-w-0 max-w-56">
                                            <p class="truncate text-sm font-medium text-ink">{{ user.name }}</p>
                                            <p class="truncate text-xs text-muted">{{ user.email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <!-- Role labels are identical in both languages (like admin_tag) -->
                                    <span class="rounded-sm px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        :class="roleTag(user.role)">
                                        {{ $t(`permissions.role_${user.role}`) }}
                                    </span>
                                </td>
                                <td class="max-w-72 px-4 py-2.5">
                                    <div v-if="user.assigned_companies?.length" class="flex flex-wrap gap-1">
                                        <span v-for="c in user.assigned_companies" :key="c.id"
                                            class="rounded-sm bg-surface-sunken px-1.5 py-0.5 text-xs text-ink-soft">
                                            {{ c.name }}
                                        </span>
                                    </div>
                                    <span v-else class="text-xs text-muted">—</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs"
                                        :class="user.active ? 'text-ink-soft' : 'text-status-danger'">
                                        <span class="h-2 w-2 shrink-0 rounded-full"
                                            :class="user.active ? 'bg-status-ok' : 'bg-status-danger'" />
                                        {{ $t(user.active ? 'permissions.active' : 'permissions.inactive') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        <VButton v-if="user.editable" variant="ghost" size="sm" @click.stop="selectUser(user)">
                                            <Bilingual k="permissions.manage" inline />
                                        </VButton>
                                        <button type="button"
                                            class="rounded-sm p-1.5 text-muted transition hover:bg-surface-sunken hover:text-ink"
                                            :aria-label="`${user.name} — ${$t('permissions.edit_user')}`"
                                            @click.stop="openEdit(user)">
                                            <AppIcon name="edit" class="h-4 w-4" />
                                        </button>
                                        <button v-if="user.deletable" type="button"
                                            class="rounded-sm p-1.5 text-muted transition hover:bg-status-danger-soft hover:text-status-danger"
                                            :aria-label="`${user.name} — ${$t('permissions.delete_user')}`"
                                            @click.stop="deleteUser(user)">
                                            <AppIcon name="trash" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!filteredUsers.length">
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-muted">—</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Matrix — appears below the directory once a user is selected -->
            <div v-if="selected !== null" class="min-w-0 rounded-lg border border-line bg-surface-raised shadow-card">
                <template v-if="!selected.editable">
                    <div class="p-6">
                        <VAlert status="info">
                            <Bilingual k="permissions.admins_bypass" />
                        </VAlert>
                    </div>
                </template>

                <template v-else>
                    <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
                        <p class="me-auto text-sm font-semibold">{{ selected.name }}</p>
                        <VButton variant="secondary" size="sm" @click="applyPreset('full')">
                            <Bilingual k="permissions.preset_full" inline />
                        </VButton>
                        <VButton variant="secondary" size="sm" @click="applyPreset('read')">
                            <Bilingual k="permissions.preset_read" inline />
                        </VButton>
                        <VButton variant="secondary" size="sm" @click="applyPreset('none')">
                            <Bilingual k="permissions.preset_none" inline />
                        </VButton>
                        <div class="flex items-center gap-1.5">
                            <VSelect v-model="copyFrom" class="w-full sm:w-52" @update:model-value="requestCopy">
                                <option value="">{{ $t('permissions.copy_from') }}</option>
                                <option v-for="user in props.users.filter((u) => u.editable && u.id !== props.selectedUser)"
                                    :key="user.id" :value="user.id">
                                    {{ user.name }}
                                </option>
                            </VSelect>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-max text-sm">
                            <thead>
                                <tr class="border-b border-line bg-surface-sunken/60">
                                    <th class="px-4 py-2.5 text-start">
                                        <Bilingual k="permissions.module" class="text-xs font-semibold text-ink-soft" />
                                    </th>
                                    <th v-for="action in actionOrder" :key="action" class="px-2 py-2.5 text-center">
                                        <Bilingual :k="`perm_actions.${action}`" class="items-center text-xs font-semibold text-ink-soft" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="row in props.matrix" :key="row.module" class="hover:bg-surface-hover">
                                    <td class="px-4 py-2">
                                        <Bilingual :k="`modules.${row.module}`" class="text-sm" />
                                    </td>
                                    <td v-for="action in actionOrder" :key="action" class="px-2 py-2 text-center">
                                        <VPermissionToggle v-model="grid[row.module][action]"
                                            :label="`${row.module} — ${action}`" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sticky bottom-0 flex justify-end border-t border-line bg-surface-raised px-4 py-3">
                        <VButton :loading="saving" @click="save">
                            <Bilingual k="permissions.save" inline />
                        </VButton>
                    </div>
                </template>
            </div>
        </div>

        <!-- User create/edit modal -->
        <VModal :open="showUserModal" :title-key="editingUser ? 'permissions.edit_user' : 'permissions.new_user'"
            @close="showUserModal = false">
            <form id="user-form" class="space-y-4" @submit.prevent="submitUser">
                <FormField k="companies.name" :error="userForm.errors.name" required>
                    <VInput v-model="userForm.name" :invalid="Boolean(userForm.errors.name)" />
                </FormField>
                <FormField k="auth.email" :error="userForm.errors.email" required>
                    <VInput v-model="userForm.email" type="email" :invalid="Boolean(userForm.errors.email)" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField k="permissions.role" :error="userForm.errors.role" required>
                        <VSelect v-model="userForm.role" :disabled="editingUser?.role === 'super_admin'">
                            <!-- An SA's role is LOCKED server-side (no demotion path) — the
                                 select renders their role read-only so email/password edits
                                 still submit a valid value. -->
                            <template v-if="editingUser?.role === 'super_admin'">
                                <option value="super_admin">{{ $tPair('permissions.role_super_admin') }}</option>
                            </template>
                            <template v-else>
                                <option value="manager">{{ $tPair('permissions.role_manager') }}</option>
                                <option v-if="$page.props.auth.user?.role === 'super_admin'" value="admin">
                                    {{ $tPair('permissions.role_admin') }}
                                </option>
                            </template>
                        </VSelect>
                    </FormField>
                    <FormField k="permissions.locale" :error="userForm.errors.locale" required>
                        <VSelect v-model="userForm.locale">
                            <option value="es">Español / Spanish</option>
                            <option value="en">Inglés / English</option>
                        </VSelect>
                    </FormField>
                </div>
                <FormField k="auth.password" :error="userForm.errors.password" :required="!editingUser">
                    <VInput v-model="userForm.password" type="password" autocomplete="new-password"
                        :invalid="Boolean(userForm.errors.password)" />
                    <p v-if="editingUser" class="mt-1 text-xs text-muted">
                        <Bilingual k="permissions.password_hint" />
                    </p>
                </FormField>
                <label v-if="editingUser" class="flex items-center gap-2">
                    <VToggle v-model="userForm.active" label="Activo / Active" size="sm" />
                    <Bilingual k="permissions.active" inline class="text-sm" />
                </label>

                <!-- Company assignments (Super Admin anywhere; Admin within
                     their own companies). Immediate actions, not form fields:
                     each add/remove posts and the chips refresh from props.
                     Hidden for SA targets — they are never on the pivot. -->
                <div v-if="editingUser && editingUser.role !== 'super_admin'"
                    class="space-y-2 rounded-md border border-line bg-surface-sunken p-3">
                    <p class="text-xs font-semibold text-ink-soft"><Bilingual k="permissions.assigned_companies" inline /></p>
                    <div class="flex flex-wrap gap-1.5">
                        <span v-for="c in editingUserLive?.assigned_companies ?? []" :key="c.id"
                            class="inline-flex items-center gap-1 rounded-sm bg-surface-raised px-2 py-1 text-xs text-ink">
                            {{ c.name }}
                            <button v-if="(editingUserLive?.assigned_companies?.length ?? 0) > 1" type="button"
                                class="text-muted transition hover:text-status-danger"
                                :aria-label="`${c.name} — ${$t('common.delete')}`" @click="removeCompany(c.id)">
                                <AppIcon name="x" class="h-3 w-3" />
                            </button>
                        </span>
                        <span v-if="!(editingUserLive?.assigned_companies?.length)" class="text-xs text-muted">
                            {{ $t('permissions.no_companies') }}
                        </span>
                    </div>
                    <div v-if="assignableCompanies.length" class="flex items-center gap-1.5">
                        <VSelect v-model="companyToAssign" class="w-full">
                            <option value="">{{ $t('permissions.assign_company') }}</option>
                            <option v-for="c in assignableCompanies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </VSelect>
                        <VButton variant="secondary" size="sm" :disabled="!companyToAssign" @click="assignCompany">
                            <Bilingual k="permissions.assign_company" inline />
                        </VButton>
                    </div>
                </div>

                <!-- Lost-phone lever (Super Admin only): clears the second
                     factor so the user re-enrols on their next login. Lives
                     here, not on every list row, where it read as a warning. -->
                <div v-if="editingUser && isSuperAdmin" class="space-y-3 rounded-md border border-line bg-surface-sunken p-3">
                    <div>
                        <p class="mb-2 text-xs text-muted"><Bilingual k="two_factor.reset_hint" /></p>
                        <VButton variant="secondary" size="sm" @click="resetTwoFactor(editingUser)">
                            <Bilingual k="two_factor.reset" inline />
                        </VButton>
                    </div>
                    <!-- Password reset lever (Super Admin only): fulfils the
                         user's My Account request by emailing a reset link. -->
                    <div class="border-t border-line pt-3">
                        <div class="mb-2 flex items-center gap-2">
                            <p class="text-xs text-muted"><Bilingual k="permissions.password_reset_hint" /></p>
                            <VBadge v-if="editingUser.password_reset_requested" status="warn">
                                <Bilingual k="permissions.password_requested" inline />
                            </VBadge>
                        </div>
                        <VButton variant="secondary" size="sm" @click="sendPasswordReset(editingUser)">
                            <Bilingual k="permissions.password_reset_send" inline />
                        </VButton>
                    </div>
                </div>
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showUserModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="user-form" :loading="userForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
        <VConfirmDialog :open="confirm.open" :message="confirm.message" @confirm="runDelete" @cancel="confirm.open = false" />
    </AppLayout>
</template>
