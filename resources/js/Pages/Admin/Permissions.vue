<script setup>
/**
 * Screen 17 — Permission Matrix (Super Admin + Company Admin).
 * Left: users of the active company. Right: module × action toggle grid
 * with presets and copy-from. Admin-role users bypass the matrix.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormField from '@/Components/ui/FormField.vue';
import VAlert from '@/Components/ui/VAlert.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VBadge from '@/Components/ui/VBadge.vue';
import VButton from '@/Components/ui/VButton.vue';
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
    selectedUser: { type: Number, default: null },
    permissions: { type: Object, default: () => ({}) },
    copySourcePermissions: { type: Object, default: null },
});

const actionOrder = ['view', 'create', 'edit', 'delete', 'upload', 'download', 'export', 'approve'];

const search = ref('');
const filteredUsers = computed(() =>
    props.users.filter((user) =>
        (user.name + user.email).toLowerCase().includes(search.value.toLowerCase()),
    ),
);

const selected = computed(() => props.users.find((u) => u.id === props.selectedUser) ?? null);

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

// --- User create/edit modal ---
const showUserModal = ref(false);
const editingUser = ref(null);

const userForm = useForm({
    name: '', email: '', password: '', role: 'user', locale: 'es', active: true,
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
    userForm.locale = 'es';
    userForm.active = user.active;
    userForm.clearErrors();
    showUserModal.value = true;
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
    <Head title="Permisos" />

    <AppLayout>
        <VPageHeader k="permissions.title">
            <VButton icon="plus" @click="openCreate">
                <Bilingual k="permissions.new_user" inline />
            </VButton>
        </VPageHeader>

        <div class="grid gap-5 lg:grid-cols-[280px_1fr]">
            <!-- User list -->
            <div class="rounded-lg border border-line bg-surface-raised p-3 shadow-card lg:self-start">
                <VSearchInput v-model="search" />
                <ul class="mt-2 space-y-0.5">
                    <li v-for="user in filteredUsers" :key="user.id">
                        <div role="button" tabindex="0"
                            class="flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-start transition-colors"
                            :class="user.id === props.selectedUser ? 'bg-accent-soft' : 'hover:bg-surface-hover'"
                            @click="selectUser(user)"
                            @keydown.enter="selectUser(user)">
                            <VAvatar :name="user.name" size="sm" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium">{{ user.name }}</span>
                                <span class="block truncate text-xs text-muted">{{ user.email }}</span>
                            </span>
                            <VBadge v-if="!user.editable" status="accent">
                                <Bilingual k="permissions.role_company_admin" inline />
                            </VBadge>
                            <span v-else-if="!user.active" class="h-2 w-2 rounded-full bg-status-danger" />
                            <button type="button" class="rounded-sm p-1 text-muted hover:text-ink"
                                :aria-label="`${user.name} — edit`" @click.stop="openEdit(user)">
                                <AppIcon name="edit" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Matrix -->
            <div class="rounded-lg border border-line bg-surface-raised shadow-card">
                <template v-if="selected === null">
                    <p class="px-6 py-16 text-center text-sm text-muted">
                        <Bilingual k="permissions.select_user_hint" class="items-center" />
                    </p>
                </template>

                <template v-else-if="!selected.editable">
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
                            <VSelect v-model="copyFrom" class="w-40" @update:model-value="requestCopy">
                                <option value="">{{ $page.props.lang.es.permissions.copy_from }}</option>
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
                        <VSelect v-model="userForm.role">
                            <option value="user">{{ $page.props.lang.es.permissions.role_user }} / {{ $page.props.lang.en.permissions.role_user }}</option>
                            <option v-if="$page.props.auth.user?.role === 'super_admin'" value="company_admin">
                                {{ $page.props.lang.es.permissions.role_company_admin }} / {{ $page.props.lang.en.permissions.role_company_admin }}
                            </option>
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
            </form>
            <template #footer>
                <VButton variant="ghost" @click="showUserModal = false"><Bilingual k="common.cancel" inline /></VButton>
                <VButton type="submit" form="user-form" :loading="userForm.processing">
                    <Bilingual k="common.save" inline />
                </VButton>
            </template>
        </VModal>
    </AppLayout>
</template>
