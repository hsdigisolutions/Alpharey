<script setup>
/**
 * The application shell (D3 / REQUIREMENTS.md §6, verto5-design skill):
 *  - dark sidebar (#1F1E1B both modes), 10 primary items, no sub-menus,
 *    collapsible 240px ⇄ 56px
 *  - secondary modules behind the "apps" menu
 *  - header: global search, notification bell, ES/EN, theme, user menu
 *  - bottom navigation bar on mobile
 * Non-dashboard destinations activate in their build phases — until then
 * they render as muted, disabled rows.
 */
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VDropdown from '@/Components/ui/VDropdown.vue';
import VToastHost from '@/Components/ui/VToastHost.vue';

const page = usePage();

const primaryNav = [
    { key: 'dashboard', icon: 'dashboard', href: '/dashboard' },
    { key: 'companies', icon: 'companies', href: null, superAdminOnly: true },
    { key: 'employees', icon: 'employees', href: null },
    { key: 'clients', icon: 'clients', href: null },
    { key: 'projects', icon: 'projects', href: null },
    { key: 'invoices', icon: 'invoices', href: null },
    { key: 'attendance', icon: 'attendance', href: null },
    { key: 'payroll', icon: 'payroll', href: null },
    { key: 'calls', icon: 'calls', href: null },
    { key: 'reports', icon: 'reports', href: null },
];

const secondaryNav = [
    'vendors', 'vehicles', 'proposals', 'commissions', 'leave',
    'inventory', 'measurements', 'compliance', 'audit_logs', 'settings',
];

const mobileNav = primaryNav.filter((item) =>
    ['dashboard', 'employees', 'attendance', 'payroll', 'reports'].includes(item.key),
);

const isDark = ref(document.documentElement.classList.contains('dark'));
const collapsed = ref(localStorage.getItem('sidebar-collapsed') === '1');

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
}

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('sidebar-collapsed', collapsed.value ? '1' : '0');
}

function switchLocale() {
    const next = page.props.locale.primary === 'es' ? 'en' : 'es';
    router.post('/locale', { locale: next }, { preserveScroll: true });
}

const roleLabels = {
    super_admin: 'Super Admin',
    company_admin: 'Admin',
    user: 'Staff',
};
</script>

<template>
    <div class="flex min-h-screen">
        <VToastHost />

        <!-- Sidebar (desktop) — always dark, both themes -->
        <aside class="hidden shrink-0 flex-col bg-sidebar transition-[width] duration-200 md:flex"
            :class="collapsed ? 'w-14' : 'w-60'">
            <div class="flex items-center gap-2.5 px-3 py-4" :class="collapsed ? 'justify-center px-0' : 'px-4'">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-accent text-sm font-bold text-on-accent">V5</span>
                <span v-if="!collapsed" class="text-base font-semibold tracking-tight text-white">Verto5</span>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
                <template v-for="item in primaryNav" :key="item.key">
                    <component
                        v-if="!item.superAdminOnly || page.props.auth.user?.role === 'super_admin'"
                        :is="item.href ? 'a' : 'div'"
                        :href="item.href ?? undefined"
                        class="flex items-center gap-3 rounded-md px-2.5 py-2 transition-colors duration-150"
                        :class="[
                            collapsed ? 'justify-center px-0' : '',
                            item.href
                                ? 'bg-accent font-medium text-on-accent'
                                : 'cursor-default text-sidebar-ink opacity-60',
                        ]"
                        :title="item.href ? undefined : `${page.props.lang.es.common.coming_soon}`"
                    >
                        <AppIcon :name="item.icon" class="h-5 w-5 shrink-0" />
                        <Bilingual v-if="!collapsed" :k="`nav.${item.key}`" class="text-sm" />
                    </component>
                </template>
            </nav>

            <!-- Secondary modules + collapse -->
            <div class="space-y-0.5 border-t border-white/10 p-2">
                <VDropdown align="start" width="w-64">
                    <template #trigger="{ toggle }">
                        <button type="button"
                            class="flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sidebar-ink transition-colors duration-150 hover:bg-sidebar-hover hover:text-white"
                            :class="collapsed ? 'justify-center px-0' : ''"
                            @click="toggle">
                            <AppIcon name="apps" class="h-5 w-5 shrink-0" />
                            <Bilingual v-if="!collapsed" k="nav.apps" class="text-sm" />
                        </button>
                    </template>
                    <div class="grid grid-cols-2 gap-0.5">
                        <div v-for="key in secondaryNav" :key="key"
                            class="cursor-default rounded-md px-2.5 py-2 text-muted opacity-70"
                            :title="page.props.lang.es.common.coming_soon">
                            <Bilingual :k="`nav.${key}`" class="text-xs" />
                        </div>
                    </div>
                </VDropdown>

                <button type="button"
                    class="flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sidebar-ink transition-colors duration-150 hover:bg-sidebar-hover hover:text-white"
                    :class="collapsed ? 'justify-center px-0' : ''"
                    :aria-label="collapsed ? 'Expandir menú / Expand menu' : 'Contraer menú / Collapse menu'"
                    @click="toggleSidebar">
                    <AppIcon :name="collapsed ? 'chevron-right' : 'chevron-left'" class="h-5 w-5 shrink-0" />
                </button>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Header -->
            <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur md:px-6">
                <!-- Global search (visual — wired in Phase 8) -->
                <div class="relative max-w-md flex-1">
                    <AppIcon name="search" class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                    <input type="search" disabled
                        :placeholder="`${page.props.lang[page.props.locale.primary].common.search_everything}…`"
                        class="w-full rounded-md border border-line bg-surface-sunken py-1.5 ps-9 pe-3 text-sm placeholder:text-muted disabled:cursor-not-allowed"
                        :title="page.props.lang.es.common.coming_soon" />
                </div>

                <div class="ms-auto flex items-center gap-1.5">
                    <!-- Notifications (visual — wired in Phase 2) -->
                    <VDropdown width="w-80">
                        <template #trigger="{ toggle }">
                            <button type="button" class="relative rounded-md p-2 text-ink-soft hover:bg-surface-hover"
                                :aria-label="`${page.props.lang.es.common.notifications} / ${page.props.lang.en.common.notifications}`"
                                @click="toggle">
                                <AppIcon name="bell" class="h-4.5 w-4.5" />
                            </button>
                        </template>
                        <div class="px-3 py-6 text-center">
                            <Bilingual k="common.no_notifications" class="items-center text-sm text-muted" />
                        </div>
                    </VDropdown>

                    <!-- Language -->
                    <button type="button"
                        class="rounded-md border border-line px-2 py-1.5 text-xs font-semibold text-ink-soft hover:bg-surface-hover"
                        @click="switchLocale">
                        {{ page.props.locale.primary.toUpperCase() }}
                    </button>

                    <!-- Theme -->
                    <button type="button" class="rounded-md p-2 text-ink-soft hover:bg-surface-hover"
                        :aria-label="isDark ? 'Modo claro / Light mode' : 'Modo oscuro / Dark mode'"
                        @click="toggleTheme">
                        <AppIcon :name="isDark ? 'sun' : 'moon'" class="h-4.5 w-4.5" />
                    </button>

                    <!-- User menu -->
                    <VDropdown v-if="page.props.auth.user" width="w-56">
                        <template #trigger="{ toggle }">
                            <button type="button" class="flex items-center gap-2 rounded-md p-1 hover:bg-surface-hover" @click="toggle">
                                <VAvatar :name="page.props.auth.user.name" size="sm" />
                                <span class="hidden text-start sm:block">
                                    <span class="block max-w-32 truncate text-xs font-medium leading-tight">{{ page.props.auth.user.name }}</span>
                                    <span class="block text-[10px] leading-tight text-muted">{{ roleLabels[page.props.auth.user.role] }}</span>
                                </span>
                                <AppIcon name="chevron-down" class="hidden h-3 w-3 text-muted sm:block" />
                            </button>
                        </template>
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-sm font-medium">{{ page.props.auth.user.name }}</p>
                            <p class="truncate text-xs text-muted">{{ page.props.auth.user.email }}</p>
                        </div>
                        <div class="cursor-default rounded-md px-3 py-2 text-muted opacity-70"
                            :title="page.props.lang.es.common.coming_soon">
                            <span class="flex items-center gap-2">
                                <AppIcon name="logout" class="h-4 w-4" />
                                <Bilingual k="common.logout" inline class="text-sm" />
                            </span>
                        </div>
                    </VDropdown>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 px-4 py-6 pb-24 md:px-6 md:pb-8">
                <slot />
            </main>
        </div>

        <!-- Bottom navigation (mobile) -->
        <nav class="fixed inset-x-0 bottom-0 z-10 flex border-t border-line bg-surface-raised md:hidden">
            <template v-for="item in mobileNav" :key="item.key">
                <component
                    :is="item.href ? 'a' : 'div'"
                    :href="item.href ?? undefined"
                    class="flex min-h-11 flex-1 flex-col items-center gap-0.5 py-2"
                    :class="item.href ? 'text-accent-hover' : 'text-muted opacity-70'"
                >
                    <AppIcon :name="item.icon" class="h-5 w-5" />
                    <Bilingual :k="`nav.${item.key}`" class="items-center text-center text-[0.6rem]" />
                </component>
            </template>
        </nav>
    </div>
</template>
