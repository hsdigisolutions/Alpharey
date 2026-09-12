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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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
    { key: 'expense_review', icon: 'expenses', href: '/expense-review', superAdminOnly: true },
    { key: 'employees', icon: 'employees', href: '/employees' },
    { key: 'clients', icon: 'clients', href: '/clients' },
    { key: 'projects', icon: 'projects', href: '/projects' },
    { key: 'invoices', icon: 'invoices', href: '/invoices' },
    { key: 'attendance', icon: 'attendance', href: '/attendance' },
    { key: 'timesheet', icon: 'attendance', href: '/timesheet' },
    { key: 'payroll', icon: 'payroll', href: '/payroll' },
    { key: 'calls', icon: 'calls', href: '/calls' },
    { key: 'reports', icon: 'reports', href: '/reports' },
];

const isAdmin = computed(() => ['super_admin', 'admin'].includes(page.props.auth.user?.role));

// Multi-company Admins/Managers switch between their pivot-assigned
// companies from the header (the server validates every switch).
const assignedCompanies = computed(() => page.props.companies ?? []);

function switchCompany(id) {
    if (!id || id === page.props.company?.id) return;
    router.post(`/company/${id}/switch`);
}

function switchCompanySA(id) {
    if (!id) {
        router.get('/welcome');
    } else if (id !== page.props.company?.id) {
        router.post(`/company/${id}/switch`);
    }
}

const secondaryNav = computed(() => [
    { key: 'today', labelKey: 'nav.today', icon: 'calendar', href: '/today' },
    { key: 'vendors', labelKey: 'nav.vendors', icon: 'euro', href: '/vendors' },
    { key: 'vehicles', labelKey: 'nav.vehicles', icon: 'vehicles', href: '/vehicles' },
    { key: 'proposals', labelKey: 'nav.proposals', icon: 'file', href: '/proposals' },
    { key: 'commissions', labelKey: 'nav.commissions', icon: 'commissions', href: '/commissions' },
    { key: 'expenses', labelKey: 'nav.expenses', icon: 'expenses', href: '/expenses' },
    { key: 'leave', labelKey: 'nav.leave', icon: 'leave', href: '/leave' },
    { key: 'inventory', labelKey: 'nav.inventory', icon: 'inventory', href: '/inventory' },
    // Measurements retired from the nav (2026-09): per-meter billing was replaced
    // by task-based billing, which sources revenue from Production Tasks (Tareas).
    // The /measurements routes + table stay intact (dormant) — just not surfaced.
    { key: 'tasks', labelKey: 'nav.tasks', icon: 'columns', href: '/tasks' },
    { key: 'deployments', labelKey: 'nav.deployments', icon: 'deployments', href: '/deployments' },
    { key: 'subcontractors', labelKey: 'nav.subcontractors', icon: 'employees', href: '/subcontractors' },
    { key: 'documents', labelKey: 'nav.documents', icon: 'file', href: '/documents' },
    { key: 'permissions', labelKey: 'permissions.title', icon: 'lock', href: isAdmin.value ? '/admin/permissions' : null },
    { key: 'audit_logs', labelKey: 'nav.audit_logs', icon: 'eye', href: isAdmin.value ? '/admin/audit-logs' : null },
    { key: 'settings', labelKey: 'nav.settings', icon: 'settings', href: isAdmin.value ? '/admin/settings' : null },
]);

const anySecondaryActive = computed(() => secondaryNav.value.some(item => item.href && isActive(item.href)));
const moreOpen = ref(localStorage.getItem('sidebar-more-open') === '1');
watch(anySecondaryActive, (v) => { if (v) moreOpen.value = true; }, { immediate: true });
watch(moreOpen, (v) => localStorage.setItem('sidebar-more-open', v ? '1' : '0'));

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

// Real-time-ish bell without a WebSocket daemon (the cPanel host can't run one):
// poll the shared notifications prop every 60 s via an Inertia partial reload.
// Pauses while the tab is hidden to avoid needless traffic.
let bellTimer = null;

function refreshBell() {
    if (document.visibilityState !== 'visible') return;
    router.reload({ only: ['notifications'] });
}

onMounted(() => {
    if (page.props.auth.user) {
        bellTimer = window.setInterval(refreshBell, 60000);
    }
});

onUnmounted(() => {
    if (bellTimer) window.clearInterval(bellTimer);
});

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
// Collapsed (icons-only rail) BY DEFAULT — only an explicit '0' pins it open.
const collapsed = ref(localStorage.getItem('sidebar-collapsed') !== '0');
// Hovering the rail expands it as an overlay (labels revealed) without pushing
// the page content — so the default is a compact rail that opens on hover.
const hovered = ref(false);
const expanded = computed(() => !collapsed.value || hovered.value);

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
}

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('sidebar-collapsed', collapsed.value ? '1' : '0');
}

function isActive(href) {
    if (!href) return false;
    return page.url === href
        || page.url.startsWith(href + '/')
        || page.url.startsWith(href + '?');
}

function switchLocale() {
    const next = page.props.locale.primary === 'es' ? 'en' : 'es';
    localStorage.setItem('ar-locale', next);
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
        <aside class="fixed inset-y-0 start-0 z-30 hidden h-screen flex-col bg-sidebar shadow-overlay transition-[width] duration-200 md:flex"
            :class="expanded ? 'w-60' : 'w-14'"
            @mouseenter="hovered = true" @mouseleave="hovered = false">
            <div class="flex items-center gap-2.5 border-b border-white/5 px-3 py-4" :class="expanded ? 'px-4' : 'justify-center px-0'">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent shadow-sm">AR</span>
                <span v-if="expanded" class="whitespace-nowrap text-base font-semibold tracking-tight text-white">Alpha<span class="text-accent">Rey</span></span>
            </div>

            <nav class="sidebar-nav flex-1 space-y-0.5 overflow-y-auto px-2 pb-2">
                <template v-for="item in primaryNav" :key="item.key">
                    <component
                        v-if="!item.superAdminOnly || page.props.auth.user?.role === 'super_admin'"
                        :is="item.href ? 'a' : 'div'"
                        :href="item.href ?? undefined"
                        class="flex items-center gap-3 rounded-md px-2.5 py-2 transition-colors duration-150"
                        :class="[
                            expanded ? '' : 'justify-center px-0',
                            item.href
                                ? isActive(item.href)
                                    ? 'bg-accent font-medium text-on-accent'
                                    : 'text-sidebar-ink hover:bg-sidebar-hover hover:text-white'
                                : 'cursor-default text-sidebar-ink opacity-50',
                        ]"
                        :title="item.href ? undefined : `${$t('common.coming_soon')}`"
                    >
                        <AppIcon :name="item.icon" class="h-5 w-5 shrink-0" />
                        <Bilingual v-if="expanded" :k="`nav.${item.key}`" class="whitespace-nowrap text-sm" />
                    </component>
                </template>

                <!-- More Modules accordion -->
                <div class="mt-1 border-t border-white/10 pt-1">
                    <button type="button"
                        class="flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sidebar-ink transition-colors duration-150 hover:bg-sidebar-hover hover:text-white"
                        :class="expanded ? '' : 'justify-center px-0'"
                        @click="moreOpen = !moreOpen">
                        <AppIcon name="apps" class="h-5 w-5 shrink-0" />
                        <template v-if="expanded">
                            <Bilingual k="nav.apps" inline class="flex-1 text-sm" />
                            <AppIcon :name="moreOpen ? 'chevron-up' : 'chevron-down'" class="h-4 w-4 shrink-0 opacity-60" />
                        </template>
                    </button>

                    <div v-show="moreOpen && expanded" class="mt-0.5 space-y-0.5">
                        <component v-for="item in secondaryNav" :key="item.key"
                            :is="item.href ? 'a' : 'div'"
                            :href="item.href ?? undefined"
                            class="flex items-center gap-3 rounded-md px-2.5 py-1.5 transition-colors duration-150"
                            :class="item.href
                                ? isActive(item.href)
                                    ? 'bg-accent font-medium text-on-accent'
                                    : 'text-sidebar-ink hover:bg-sidebar-hover hover:text-white'
                                : 'cursor-default text-sidebar-ink opacity-50'"
                            :title="item.href ? undefined : $t('common.coming_soon')">
                            <AppIcon :name="item.icon" class="h-4 w-4 shrink-0" />
                            <Bilingual :k="item.labelKey" inline class="text-sm" />
                        </component>
                    </div>
                </div>
            </nav>

            <!-- Collapse toggle -->
            <div class="space-y-0.5 border-t border-white/10 p-2">
                <button type="button"
                    class="flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-sidebar-ink transition-colors duration-150 hover:bg-sidebar-hover hover:text-white"
                    :class="expanded ? '' : 'justify-center px-0'"
                    :aria-label="collapsed ? $tPair('common.expand_menu') : $tPair('common.collapse_menu')"
                    @click="toggleSidebar">
                    <AppIcon :name="collapsed ? 'chevron-right' : 'chevron-left'" class="h-5 w-5 shrink-0" />
                    <Bilingual v-if="expanded" :k="collapsed ? 'common.expand_menu' : 'common.collapse_menu'" inline class="whitespace-nowrap text-sm" />
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
                    <!-- Company context chip: from md up only — below that it
                         crowds the global search into an unreadable sliver. -->
                    <!-- SA gets all companies from the server — styled custom dropdown. -->
                    <VDropdown v-if="page.props.auth.user?.role === 'super_admin'"
                        class="hidden md:block"
                        align="end"
                        width="w-52">
                        <template #trigger="{ toggle, open }">
                            <button type="button"
                                class="flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-ink-soft transition hover:border-line-strong hover:bg-surface-hover"
                                :class="open ? 'border-line-strong bg-surface-hover' : ''"
                                @click="toggle">
                                <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0 text-muted" />
                                <span class="max-w-32 truncate">
                                    {{ page.props.company?.name ?? $t('welcome.all_companies') }}
                                </span>
                                <AppIcon name="chevron-down" class="h-3 w-3 shrink-0 text-muted transition-transform"
                                    :class="open ? 'rotate-180' : ''" />
                            </button>
                        </template>
                        <template #default="{ close }">
                            <!-- All companies option (SA only) -->
                            <button type="button"
                                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition hover:bg-surface-hover"
                                :class="!page.props.company ? 'text-accent font-medium' : 'text-ink-soft'"
                                @click="switchCompanySA(null); close()">
                                <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0" />
                                <span class="flex-1 truncate">{{ $t('welcome.all_companies') }}</span>
                                <AppIcon v-if="!page.props.company" name="check" class="h-3.5 w-3.5 text-accent" />
                            </button>
                            <div class="my-1 border-t border-line" />
                            <button v-for="c in assignedCompanies" :key="c.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition hover:bg-surface-hover"
                                :class="page.props.company?.id === c.id ? 'text-accent font-medium' : 'text-ink'"
                                @click="switchCompanySA(c.id); close()">
                                <span class="flex-1 truncate">{{ c.name }}</span>
                                <AppIcon v-if="page.props.company?.id === c.id" name="check" class="h-3.5 w-3.5 text-accent" />
                            </button>
                        </template>
                    </VDropdown>

                    <!-- Admin/Manager assigned to several companies: styled custom dropdown. -->
                    <VDropdown v-else-if="assignedCompanies.length > 1"
                        class="hidden md:block"
                        align="end"
                        width="w-52">
                        <template #trigger="{ toggle, open }">
                            <button type="button"
                                class="flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-ink-soft transition hover:border-line-strong hover:bg-surface-hover"
                                :class="open ? 'border-line-strong bg-surface-hover' : ''"
                                @click="toggle">
                                <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0 text-muted" />
                                <span class="max-w-32 truncate">{{ page.props.company?.name }}</span>
                                <AppIcon name="chevron-down" class="h-3 w-3 shrink-0 text-muted transition-transform"
                                    :class="open ? 'rotate-180' : ''" />
                            </button>
                        </template>
                        <template #default="{ close }">
                            <button v-for="c in assignedCompanies" :key="c.id"
                                type="button"
                                class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm transition hover:bg-surface-hover"
                                :class="page.props.company?.id === c.id ? 'text-accent font-medium' : 'text-ink'"
                                @click="switchCompany(c.id); close()">
                                <span class="flex-1 truncate">{{ c.name }}</span>
                                <AppIcon v-if="page.props.company?.id === c.id" name="check" class="h-3.5 w-3.5 text-accent" />
                            </button>
                        </template>
                    </VDropdown>

                    <!-- Single company — static chip, no dropdown needed. -->
                    <span v-else-if="page.props.company"
                        class="hidden items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-ink-soft md:flex">
                        <AppIcon name="companies" class="h-3.5 w-3.5 shrink-0 text-muted" />
                        <span class="max-w-32 truncate">{{ page.props.company.name }}</span>
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
                                class="flex w-full items-start gap-2.5 border-b border-line px-3 py-2.5 text-start last:border-0 hover:bg-surface-sunken"
                                :class="{ 'bg-accent-soft/40': !item.read, 'cursor-pointer': item.url }"
                                @click="markRead(item.id, item.url)">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-ink-soft">
                                    <AppIcon :name="item.icon" class="h-3.5 w-3.5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-xs font-medium leading-snug">{{ item.title }}</span>
                                    <span v-if="item.body" class="block text-[11px] leading-snug text-muted">{{ item.body }}</span>
                                    <span class="mt-0.5 block text-[10px] text-faint">{{ item.created_at }}</span>
                                </span>
                                <span v-if="!item.read" class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-accent" />
                            </button>
                        </div>
                        <Link href="/notifications"
                            class="block border-t border-line px-3 py-2 text-center text-xs font-medium text-accent-hover hover:bg-surface-sunken">
                            <Bilingual k="common.view_all_notifications" inline class="text-xs" />
                        </Link>
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
                    :class="item.href
                        ? isActive(item.href) ? 'text-accent' : 'text-muted hover:text-ink-soft'
                        : 'text-muted opacity-70'"
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

<style scoped>
.sidebar-nav::-webkit-scrollbar {
    width: 2px;
}
.sidebar-nav::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar-nav::-webkit-scrollbar-thumb {
    background: rgba(212, 149, 106, 0.35);
    border-radius: 2px;
}
.sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: rgba(212, 149, 106, 0.7);
}
</style>
