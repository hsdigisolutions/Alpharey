<script setup>
/**
 * The application shell (D3 / REQUIREMENTS.md §6, alpharey-design skill):
 *  - dark sidebar (#1F1E1B both modes), 10 primary items, no sub-menus,
 *    collapsible 240px ⇄ 56px
 *  - secondary modules behind the "apps" menu
 *  - header: global search, notification bell, ES/EN, theme, user menu
 *  - bottom navigation bar on mobile
 * Non-dashboard destinations activate in their build phases — until then
 * they render as muted, disabled rows.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';
import VAvatar from '@/Components/ui/VAvatar.vue';
import VDropdown from '@/Components/ui/VDropdown.vue';
import VGlobalSearch from '@/Components/ui/VGlobalSearch.vue';
import VToastHost from '@/Components/ui/VToastHost.vue';

const page = usePage();

const primaryNav = [
    { key: 'dashboard', icon: 'dashboard', href: '/dashboard' },
    { key: 'companies', icon: 'companies', href: '/companies', superAdminOnly: true },
    { key: 'employees', icon: 'employees', href: '/employees' },
    { key: 'clients', icon: 'clients', href: '/clients' },
    { key: 'projects', icon: 'projects', href: '/projects' },
    { key: 'invoices', icon: 'invoices', href: '/invoices' },
    { key: 'attendance', icon: 'attendance', href: '/attendance' },
    { key: 'payroll', icon: 'payroll', href: '/payroll' },
    { key: 'calls', icon: 'calls', href: '/calls' },
    { key: 'reports', icon: 'reports', href: '/reports' },
];

const isAdmin = computed(() => ['super_admin', 'admin'].includes(page.props.auth.user?.role));

// Multi-company Admins/Managers switch between their pivot-assigned
// companies from the header (the server validates every switch).
const assignedCompanies = computed(() => page.props.companies ?? []);

function switchCompany(event) {
    const id = Number(event.target.value);
    if (!id || id === page.props.company?.id) return;
    router.post(`/company/${id}/switch`);
}

const secondaryNav = computed(() => [
    { key: 'today', labelKey: 'nav.today', href: '/today' },
    { key: 'vendors', labelKey: 'nav.vendors', href: '/vendors' },
    { key: 'vehicles', labelKey: 'nav.vehicles', href: '/vehicles' },
    { key: 'proposals', labelKey: 'nav.proposals', href: '/proposals' },
    { key: 'commissions', labelKey: 'nav.commissions', href: '/commissions' },
    { key: 'expenses', labelKey: 'nav.expenses', href: '/expenses' },
    { key: 'leave', labelKey: 'nav.leave', href: '/leave' },
    { key: 'inventory', labelKey: 'nav.inventory', href: '/inventory' },
    { key: 'measurements', labelKey: 'nav.measurements', href: '/measurements' },
    { key: 'deployments', labelKey: 'nav.deployments', href: '/deployments' },
    { key: 'compliance', labelKey: 'nav.compliance', href: '/compliance' },
    { key: 'permissions', labelKey: 'permissions.title', href: isAdmin.value ? '/admin/permissions' : null },
    { key: 'audit_logs', labelKey: 'nav.audit_logs', href: isAdmin.value ? '/admin/audit-logs' : null },
    { key: 'settings', labelKey: 'nav.settings', href: isAdmin.value ? '/admin/settings' : null },
]);

function logout() {
    router.post('/logout');
}

const unreadCount = computed(() => page.props.notifications?.unread ?? 0);
const notificationItems = computed(() => page.props.notifications?.items ?? []);

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: false });
}

function markRead(id, url) {
    router.post(`/notifications/${id}/read`, {}, {
        preserveScroll: !url,
        preserveState: !url,
        onSuccess: () => { if (url) router.visit(url); },
    });
}

// Four most-used destinations; the fifth slot is the "Más" button below, which
// opens EVERY module. Without it the bottom nav stranded ~18 destinations —
// including Invoices, Clients, Projects and all the admin screens — with no way
// to reach them on a phone.
const mobileNav = primaryNav.filter((item) =>
    ['dashboard', 'employees', 'attendance', 'payroll'].includes(item.key),
);

const mobileSheetOpen = ref(false);

/**
 * Everything reachable, for the mobile "Más" sheet: the primary items that did
 * not fit the bottom bar, plus every secondary module. Items the user may not
 * open (href null) are filtered out rather than shown dead.
 */
const mobileAllModules = computed(() => [
    ...primaryNav
        .filter((item) => !mobileNav.some((m) => m.key === item.key))
        .filter((item) => !item.superAdminOnly || page.props.auth.user?.role === 'super_admin')
        .map((item) => ({ key: item.key, labelKey: `nav.${item.key}`, href: item.href })),
    ...secondaryNav.value.filter((item) => item.href !== null),
]);

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

/* Role names come from the dictionary (ui.roles.*) so they follow the ES/EN
   toggle like everything else — they used to be hardcoded English. */
</script>

<template>
    <div class="flex min-h-screen">
        <VToastHost />

        <!-- Sidebar (desktop) — always dark, both themes. FIXED: never scrolls
             with the content; only the right side scrolls (design skill). -->
        <aside class="fixed inset-y-0 start-0 z-30 hidden h-screen flex-col bg-sidebar transition-[width] duration-200 md:flex"
            :class="collapsed ? 'w-14' : 'w-60'">
            <div class="flex items-center gap-2.5 border-b border-white/5 px-3 py-4" :class="collapsed ? 'justify-center px-0' : 'px-4'">
                <!-- Text wordmark until the real AlphaRey logo asset arrives:
                     an "AR" monogram badge plus Alpha|Rey with the accent on
                     the second half, mirroring the old treatment. -->
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent shadow-sm">AR</span>
                <span v-if="!collapsed" class="text-base font-semibold tracking-tight text-white">Alpha<span class="text-accent">Rey</span></span>
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
                        :title="item.href ? undefined : `${$t('common.coming_soon')}`"
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
                        <component v-for="item in secondaryNav" :key="item.key"
                            :is="item.href ? 'a' : 'div'"
                            :href="item.href ?? undefined"
                            class="rounded-md px-2.5 py-2"
                            :class="item.href ? 'text-ink hover:bg-surface-sunken' : 'cursor-default text-muted opacity-70'"
                            :title="item.href ? undefined : $t('common.coming_soon')">
                            <Bilingual :k="item.labelKey" class="text-xs" />
                        </component>
                    </div>
                </VDropdown>

                <button type="button"
                    class="flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sidebar-ink transition-colors duration-150 hover:bg-sidebar-hover hover:text-white"
                    :class="collapsed ? 'justify-center px-0' : ''"
                    :aria-label="collapsed ? $tPair('common.expand_menu') : $tPair('common.collapse_menu')"
                    @click="toggleSidebar">
                    <AppIcon :name="collapsed ? 'chevron-right' : 'chevron-left'" class="h-5 w-5 shrink-0" />
                </button>
            </div>
        </aside>

        <!-- Content column — offset by the fixed sidebar width on desktop -->
        <div class="flex min-w-0 flex-1 flex-col transition-[padding] duration-200"
            :class="collapsed ? 'md:ps-14' : 'md:ps-60'">
            <!-- Header -->
            <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-surface/95 px-4 py-2.5 backdrop-blur md:px-6">
                <!-- Global search — permission- and company-scoped (Phase 8) -->
                <VGlobalSearch />

                <div class="ms-auto flex items-center gap-1.5">
                    <!-- Active company context: SA gets a switch link to Welcome -->
                    <!-- Company context chip: from md up only — below that it
                         crowds the global search into an unreadable sliver. -->
                    <a v-if="page.props.auth.user?.role === 'super_admin'" href="/welcome"
                        class="hidden items-center gap-1.5 rounded-md border border-line px-2 py-1.5 text-xs font-medium text-ink-soft hover:bg-surface-hover md:flex"
                        :title="$t('welcome.switch_company')">
                        <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0" />
                        <span v-if="page.props.company" class="max-w-36 truncate">{{ page.props.company.name }}</span>
                        <Bilingual v-else k="welcome.browsing_all" inline class="max-w-44 truncate text-xs" />
                    </a>
                    <!-- Admin/Manager assigned to several companies: a real
                         switcher in place of the static chip. -->
                    <label v-else-if="assignedCompanies.length > 1"
                        class="hidden items-center gap-1.5 rounded-md border border-line px-2 py-1 text-xs font-medium text-ink-soft hover:bg-surface-hover md:flex"
                        :title="$t('welcome.switch_company')">
                        <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0" />
                        <select :value="page.props.company?.id"
                            class="max-w-36 cursor-pointer truncate border-0 bg-transparent py-0.5 pe-6 text-xs font-medium text-ink-soft focus:ring-accent"
                            :aria-label="$tPair('welcome.switch_company')"
                            @change="switchCompany">
                            <option v-for="c in assignedCompanies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>
                    <span v-else-if="page.props.company"
                        class="hidden items-center gap-1.5 rounded-md border border-line px-2 py-1.5 text-xs font-medium text-ink-soft md:flex">
                        <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0" />
                        <span class="max-w-36 truncate">{{ page.props.company.name }}</span>
                    </span>

                    <!-- Notifications (visual — wired in Phase 2) -->
                    <VDropdown width="w-80">
                        <template #trigger="{ toggle }">
                            <button type="button" class="relative rounded-md p-2 text-ink-soft hover:bg-surface-hover"
                                :aria-label="$tPair('common.notifications')"
                                @click="toggle">
                                <AppIcon name="bell" class="h-4.5 w-4.5" />
                                <span v-if="unreadCount > 0"
                                    class="tabular-nums absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-status-danger px-1 text-[10px] font-semibold text-white">
                                    {{ unreadCount > 9 ? '9+' : unreadCount }}
                                </span>
                            </button>
                        </template>
                        <div class="flex items-center justify-between border-b border-line px-3 py-2">
                            <Bilingual k="common.notifications" class="text-sm font-semibold" />
                            <button v-if="unreadCount > 0" type="button" class="text-xs text-accent-hover hover:underline"
                                @click="markAllRead">
                                <Bilingual k="common.mark_all_read" inline class="text-xs" />
                            </button>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <div v-if="!notificationItems.length" class="px-3 py-6 text-center">
                                <Bilingual k="common.no_notifications" class="items-center text-sm text-muted" />
                            </div>
                            <button v-for="item in notificationItems" :key="item.id" type="button"
                                class="flex w-full items-start gap-2 border-b border-line px-3 py-2.5 text-start last:border-0 hover:bg-surface-sunken"
                                :class="{ 'bg-accent-soft/40': !item.read, 'cursor-pointer': item.data.url }"
                                @click="markRead(item.id, item.data.url)">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                                    :class="item.read ? 'bg-transparent' : 'bg-accent'" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-medium leading-snug">{{ item.data.title_es }}</span>
                                    <span class="block text-[11px] leading-snug text-muted">{{ item.data.title_en }}</span>
                                    <span class="mt-0.5 block text-[10px] text-faint">{{ item.created_at }}</span>
                                </span>
                            </button>
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
                        :aria-label="isDark ? $tPair('common.light_mode') : $tPair('common.dark_mode')"
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
                                    <span class="block text-[10px] leading-tight text-muted">{{ $t(`roles.${page.props.auth.user.role}`) }}</span>
                                </span>
                                <AppIcon name="chevron-down" class="hidden h-3 w-3 text-muted sm:block" />
                            </button>
                        </template>
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-sm font-medium">{{ page.props.auth.user.name }}</p>
                            <p class="truncate text-xs text-muted">{{ page.props.auth.user.email }}</p>
                        </div>
                        <Link href="/account"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-start text-ink hover:bg-surface-sunken">
                            <AppIcon name="user" class="h-4 w-4" />
                            <Bilingual k="account.title" inline class="text-sm" />
                        </Link>
                        <button type="button"
                            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-start text-ink hover:bg-surface-sunken"
                            @click="logout">
                            <AppIcon name="logout" class="h-4 w-4" />
                            <Bilingual k="common.logout" inline class="text-sm" />
                        </button>
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
                    class="flex min-h-11 min-w-0 flex-1 flex-col items-center gap-0.5 py-2"
                    :class="item.href ? 'text-accent-hover' : 'text-muted opacity-70'"
                >
                    <AppIcon :name="item.icon" class="h-5 w-5 shrink-0" />
                    <Bilingual :k="`nav.${item.key}`" class="w-full items-center px-0.5 text-center text-[0.6rem] leading-tight [&>span]:block [&>span]:truncate" />
                </component>
            </template>

            <!-- Fifth slot: reaches every remaining module. -->
            <button type="button"
                class="flex min-h-11 min-w-0 flex-1 flex-col items-center gap-0.5 py-2 text-accent-hover"
                :aria-label="$tPair('nav.apps')"
                @click="mobileSheetOpen = true">
                <AppIcon name="apps" class="h-5 w-5 shrink-0" />
                <Bilingual k="nav.apps" class="w-full items-center px-0.5 text-center text-[0.6rem] leading-tight [&>span]:block [&>span]:truncate" />
            </button>
        </nav>

        <!-- Mobile module sheet: full screen, per the mobile design rules -->
        <div v-if="mobileSheetOpen" class="fixed inset-0 z-30 md:hidden" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="mobileSheetOpen = false" />
            <div class="absolute inset-x-0 bottom-0 top-0 flex flex-col bg-surface-raised">
                <div class="flex items-center justify-between border-b border-line px-4 py-3">
                    <Bilingual k="nav.apps" class="text-sm font-semibold text-ink" />
                    <button type="button" class="min-h-11 px-3 text-sm text-ink-soft"
                        :aria-label="$tPair('common.close')" @click="mobileSheetOpen = false">
                        ✕
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-1 overflow-y-auto p-3">
                    <a v-for="item in mobileAllModules" :key="item.key" :href="item.href"
                        class="min-h-11 rounded-md px-3 py-2.5 text-ink hover:bg-surface-sunken">
                        <Bilingual :k="item.labelKey" class="text-sm" />
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>
