<script setup>
/**
 * Screen — Notifications. The full list behind the header bell.
 *
 * Rows carry a normalised shape from NotificationPresenter (icon / title_es /
 * title_en / url / read). Unread rows get a white background + a coral left
 * border; read rows are muted. Clicking a row marks it read and navigates to
 * its target. Filter tabs narrow by category (server-side); pagination is 25.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Bilingual from '@/Components/Bilingual.vue';
import VButton from '@/Components/ui/VButton.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    filter: { type: String, default: 'all' },
    filters: { type: Array, default: () => [] },
    unread: { type: Number, default: 0 },
    pagination: { type: Object, default: null },
});

function selectFilter(f) {
    router.get('/notifications', { filter: f }, { preserveScroll: true, preserveState: true });
}

function openRow(item) {
    router.post(`/notifications/${item.id}/read`, {}, {
        preserveScroll: !item.url,
        preserveState: !item.url,
        onSuccess: () => { if (item.url) router.visit(item.url); },
    });
}

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: false });
}

function deleteRead() {
    router.post('/notifications/delete-read', {}, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="$tPair('notifications_page.title')" />

    <AppLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-title font-semibold text-ink">
                    <Bilingual k="notifications_page.title" />
                </h1>
                <p class="mt-0.5 text-sm text-muted">
                    <Bilingual k="notifications_page.subtitle" inline />
                    <span v-if="unread > 0" class="ms-1 font-medium text-accent">
                        · {{ unread }} <Bilingual k="notifications_page.unread_count" inline />
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <VButton v-if="unread > 0" variant="secondary" icon="check" @click="markAllRead">
                    <Bilingual k="common.mark_all_read" inline />
                </VButton>
                <VButton variant="ghost" icon="trash" @click="deleteRead">
                    <Bilingual k="notifications_page.delete_read" inline />
                </VButton>
            </div>
        </div>

        <!-- Filter tabs -->
        <div class="mb-4 flex flex-wrap gap-1.5 overflow-x-auto">
            <button v-for="f in filters" :key="f" type="button"
                class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors"
                :class="filter === f
                    ? 'bg-accent text-on-accent'
                    : 'bg-surface-raised text-ink-soft hover:bg-surface-hover'"
                @click="selectFilter(f)">
                {{ $t(`notifications_page.filter_${f}`) }}
            </button>
        </div>

        <!-- Empty -->
        <div v-if="!items.length"
            class="rounded-lg border border-line bg-surface-raised py-16 text-center shadow-card">
            <p class="text-3xl">🔔</p>
            <p class="mt-2 text-sm font-medium text-ink"><Bilingual k="notifications_page.empty" inline /></p>
            <p class="mt-1 text-xs text-muted"><Bilingual k="notifications_page.empty_hint" inline /></p>
        </div>

        <!-- List -->
        <ul v-else class="flex flex-col gap-1.5">
            <li v-for="item in items" :key="item.id">
                <button type="button"
                    class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-start shadow-card transition-colors"
                    :class="item.read
                        ? 'border-line bg-surface-sunken hover:bg-surface-hover'
                        : 'border-s-2 border-s-accent border-line bg-surface-raised hover:bg-surface-hover'"
                    @click="openRow(item)">
                    <span class="mt-0.5 shrink-0 text-lg leading-none">{{ item.icon }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium leading-snug text-ink">{{ item.title_es }}</span>
                        <span class="block text-xs leading-snug text-muted">{{ item.title_en }}</span>
                        <span v-if="item.body_es" class="mt-1 block text-xs leading-snug text-ink-soft">{{ item.body_es }}</span>
                        <span class="mt-1 block text-[11px] text-faint">{{ item.created_at }}</span>
                    </span>
                    <span v-if="item.url" class="mt-0.5 shrink-0 text-xs font-medium text-accent-hover">
                        <Bilingual k="notifications_page.view" inline class="text-xs" /> →
                    </span>
                </button>
            </li>
        </ul>

        <!-- Pagination -->
        <div v-if="pagination && pagination.last_page > 1"
            class="mt-5 flex items-center justify-between text-sm text-ink-soft">
            <span class="text-xs text-muted">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
            <div class="flex gap-2">
                <Link v-if="pagination.prev" :href="pagination.prev" preserve-scroll
                    class="rounded-md border border-line px-3 py-1.5 hover:bg-surface-hover">←</Link>
                <Link v-if="pagination.next" :href="pagination.next" preserve-scroll
                    class="rounded-md border border-line px-3 py-1.5 hover:bg-surface-hover">→</Link>
            </div>
        </div>
    </AppLayout>
</template>
