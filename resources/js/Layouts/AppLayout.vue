<script setup>
/**
 * The application shell (REQUIREMENTS.md §6): single sidebar with the 10
 * primary items and no sub-menus, header with theme + language toggles,
 * bottom navigation bar on mobile. Non-dashboard destinations activate in
 * their build phases — until then they render as disabled rows.
 */
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppIcon from '@/Components/AppIcon.vue';

defineProps({
    titleKey: { type: String, default: 'nav.dashboard' },
});

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

const mobileNav = primaryNav.filter((item) =>
    ['dashboard', 'employees', 'attendance', 'payroll', 'reports'].includes(item.key),
);

const isDark = ref(document.documentElement.classList.contains('dark'));

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
}

function switchLocale() {
    const next = page.props.locale.primary === 'es' ? 'en' : 'es';
    router.post('/locale', { locale: next }, { preserveScroll: true });
}
</script>

<template>
    <div class="flex min-h-screen">
        <!-- Sidebar (desktop) -->
        <aside class="hidden w-60 shrink-0 flex-col border-r border-line bg-surface-raised md:flex">
            <div class="flex items-center gap-2.5 px-5 py-5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-sm font-bold text-on-accent">V5</span>
                <span class="text-base font-semibold tracking-tight">Verto5</span>
            </div>
            <nav class="flex-1 space-y-0.5 px-3 pb-4">
                <template v-for="item in primaryNav" :key="item.key">
                    <component
                        v-if="!item.superAdminOnly || page.props.auth.user?.role === 'super_admin'"
                        :is="item.href ? 'a' : 'div'"
                        :href="item.href ?? undefined"
                        class="flex items-center gap-3 rounded-lg px-3 py-2"
                        :class="item.href
                            ? 'text-ink hover:bg-surface-sunken'
                            : 'cursor-default text-muted opacity-70'"
                        :title="item.href ? undefined : `${page.props.lang.es.common.coming_soon}`"
                    >
                        <AppIcon :name="item.icon" class="h-5 w-5 shrink-0" />
                        <Bilingual :k="`nav.${item.key}`" class="text-sm" />
                    </component>
                </template>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Header -->
            <header class="flex items-center justify-between border-b border-line bg-surface-raised px-4 py-3 md:px-6">
                <h1 class="text-base font-semibold">
                    <Bilingual :k="titleKey" />
                </h1>
                <div class="flex items-center gap-2">
                    <button type="button"
                        class="rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-ink-soft hover:bg-surface-sunken"
                        @click="switchLocale">
                        {{ page.props.locale.primary.toUpperCase() }}
                    </button>
                    <button type="button"
                        class="rounded-lg border border-line p-1.5 text-ink-soft hover:bg-surface-sunken"
                        :aria-label="isDark ? 'Modo claro / Light mode' : 'Modo oscuro / Dark mode'"
                        @click="toggleTheme">
                        <AppIcon :name="isDark ? 'sun' : 'moon'" class="h-4.5 w-4.5" />
                    </button>
                    <div v-if="page.props.auth.user" class="ms-2 hidden items-center gap-2 sm:flex">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
                            {{ page.props.auth.user.name.slice(0, 1).toUpperCase() }}
                        </span>
                        <span class="text-sm text-ink-soft">{{ page.props.auth.user.name }}</span>
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 px-4 py-6 pb-20 md:px-6 md:pb-6">
                <slot />
            </main>
        </div>

        <!-- Bottom navigation (mobile) -->
        <nav class="fixed inset-x-0 bottom-0 z-10 flex border-t border-line bg-surface-raised md:hidden">
            <template v-for="item in mobileNav" :key="item.key">
                <component
                    :is="item.href ? 'a' : 'div'"
                    :href="item.href ?? undefined"
                    class="flex flex-1 flex-col items-center gap-0.5 py-2"
                    :class="item.href ? 'text-ink' : 'text-muted opacity-70'"
                >
                    <AppIcon :name="item.icon" class="h-5 w-5" />
                    <Bilingual :k="`nav.${item.key}`" class="items-center text-center text-[0.6rem]" />
                </component>
            </template>
        </nav>
    </div>
</template>
