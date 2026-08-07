<script setup>
/**
 * Worker PWA — vehicle list.
 *
 * Workers with can_use_vehicles see company vehicles, their availability,
 * and any active session they hold. The list refreshes every 30 seconds via
 * Inertia partial reloads (polling — no WebSockets needed).
 *
 * Take/Return flow:
 *   1. Worker taps an available vehicle → "Take" bottom sheet opens.
 *   2. Worker enters starting mileage (required) and fuel level (optional).
 *   3. POST /worker/vehicles/{vehicle}/take → session created.
 *   4. That vehicle now shows "Return" for the worker holding it.
 *   5. Return sheet: ending mileage + optional notes → POST /worker/vehicles/{session}/return.
 *   6. Fuel log: open session → "Log fuel" sheet → POST /worker/vehicles/{session}/fuel.
 */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    worker: { type: Object, required: true },
    vehicles: { type: Array, required: true },
    fines: { type: Array, default: () => [] },
    // The currently active session for this worker (null if none).
    // eslint-disable-next-line vue/prop-name-casing
    my_session: { type: Object, default: null },
});

// ── Polling ──────────────────────────────────────────────────────────────────
let pollTimer = null;

function poll() {
    router.reload({ only: ['vehicles', 'my_session', 'fines'] });
}

onMounted(() => {
    pollTimer = setInterval(poll, 30_000);
    if (props.my_session) startStopwatch();
});

onUnmounted(() => {
    clearInterval(pollTimer);
    clearInterval(swTimer);
});

// ── Stopwatch ─────────────────────────────────────────────────────────────────
const elapsed = ref('00:00:00');
let swTimer = null;

function toUtc(dt) {
    if (!dt) return new Date(0);
    return new Date(dt);
}

const takenAtFormatted = computed(() => {
    const dt = props.my_session?.taken_at;
    if (!dt) return '—';
    return toUtc(dt).toLocaleTimeString('es-ES', {
        hour: '2-digit', minute: '2-digit', hour12: false,
        timeZone: 'Europe/Madrid',
    });
});

function startStopwatch() {
    clearInterval(swTimer);
    const start = toUtc(props.my_session.taken_at);
    function tick() {
        const diff = Math.max(0, Math.floor((Date.now() - start.getTime()) / 1000));
        const h = Math.floor(diff / 3600).toString().padStart(2, '0');
        const m = Math.floor((diff % 3600) / 60).toString().padStart(2, '0');
        const s = (diff % 60).toString().padStart(2, '0');
        elapsed.value = `${h}:${m}:${s}`;
    }
    tick();
    swTimer = setInterval(tick, 1000);
}

watch(() => props.my_session, (session) => {
    clearInterval(swTimer);
    if (session) startStopwatch();
});

// ── Take sheet ───────────────────────────────────────────────────────────────
const takeTarget = ref(null);  // { id, plate }
const takeForm = ref({ starting_mileage: '' });
const takeBusy = ref(false);

function openTake(vehicle) {
    takeTarget.value = vehicle;
    takeForm.value = { starting_mileage: vehicle.current_mileage ?? '' };
}

function submitTake() {
    takeBusy.value = true;
    router.post(`/worker/vehicles/${takeTarget.value.id}/take`, takeForm.value, {
        onFinish: () => { takeBusy.value = false; takeTarget.value = null; },
    });
}

// ── Return sheet ─────────────────────────────────────────────────────────────
const returnOpen = ref(false);
const returnForm = ref({ ending_mileage: '', return_notes: '' });
const returnBusy = ref(false);

function submitReturn() {
    returnBusy.value = true;
    router.post(`/worker/vehicle-sessions/${props.my_session?.id}/return`, returnForm.value, {
        onFinish: () => { returnBusy.value = false; returnOpen.value = false; },
    });
}

// ── Fuel expense sheet ───────────────────────────────────────────────────────
const fuelOpen = ref(false);
const fuelForm = ref({ amount: '', description: '', receipt: null });
const fuelBusy = ref(false);
const fuelReceiptLabel = ref('');

function onFuelReceipt(e) {
    const file = e.target.files?.[0];
    fuelForm.value.receipt = file ?? null;
    fuelReceiptLabel.value = file?.name ?? '';
}

function submitFuel() {
    fuelBusy.value = true;
    const data = new FormData();
    data.append('amount', fuelForm.value.amount);
    data.append('description', fuelForm.value.description);
    if (fuelForm.value.receipt) data.append('receipt', fuelForm.value.receipt);
    router.post(`/worker/vehicle-sessions/${props.my_session?.id}/fuel`, data, {
        forceFormData: true,
        onFinish: () => {
            fuelBusy.value = false;
            fuelOpen.value = false;
            fuelForm.value = { amount: '', description: '', receipt: null };
            fuelReceiptLabel.value = '';
        },
    });
}
</script>

<template>
    <Head :title="$t('worker_vehicles.title')" />

    <WorkerLayout :worker="worker">
        <div class="mb-4 flex items-center gap-2">
            <a href="/worker" class="text-sm text-ink-soft hover:text-ink">‹ {{ $t('worker.title') }}</a>
            <span class="text-ink-soft">/</span>
            <span class="text-sm font-medium text-ink">{{ $t('worker_vehicles.title') }}</span>
        </div>

        <!-- ── Active session view: shown exclusively when the worker has a vehicle ── -->
        <div v-if="my_session" class="space-y-4">
            <!-- Vehicle identity card -->
            <div class="rounded-lg border border-accent bg-accent-soft p-4 shadow-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-lg font-bold text-ink">{{ my_session.vehicle?.plate_number }}</p>
                        <p class="text-sm text-ink-soft">{{ my_session.vehicle?.brand }} {{ my_session.vehicle?.model }}</p>
                    </div>
                    <span class="inline-block rounded-full bg-accent px-2.5 py-0.5 text-xs font-semibold text-on-accent">
                        {{ $t('worker_vehicles.mine') }}
                    </span>
                </div>
            </div>

            <!-- Session details: no labels, just the key numbers -->
            <div class="rounded-lg border border-line bg-surface-raised p-4 shadow-card text-center">
                <p class="mb-2 text-4xl font-bold tabular-nums text-accent">{{ elapsed }}</p>
                <div class="flex items-center justify-center gap-3 text-sm text-ink-soft tabular-nums">
                    <span>{{ takenAtFormatted }}</span>
                    <span class="opacity-40">·</span>
                    <span>{{ my_session.starting_mileage != null ? Number(my_session.starting_mileage).toLocaleString() + ' km' : '—' }}</span>
                </div>
                <p v-if="my_session.fuel_expenses_count" class="mt-2 text-xs text-ink-soft">
                    {{ my_session.fuel_expenses_count }} {{ $t('worker_vehicles.fuel_expense_submitted') }}
                </p>
            </div>

            <!-- Actions -->
            <div class="flex gap-3">
                <VButton variant="secondary" class="flex-1" @click="fuelOpen = true">
                    {{ $t('worker_vehicles.log_fuel') }}
                </VButton>
                <VButton class="flex-1" @click="returnOpen = true">
                    {{ $t('worker_vehicles.return') }}
                </VButton>
            </div>
        </div>

        <!-- ── Vehicle list: shown only when no active session ── -->
        <div v-else class="space-y-3">
            <div v-if="!vehicles.length" class="rounded-lg border border-line bg-surface-raised p-5 text-center text-sm text-ink-soft shadow-card">
                {{ $t('worker_vehicles.no_vehicles') }}
            </div>

            <div v-for="v in vehicles" :key="v.id"
                class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-medium text-ink">{{ v.plate_number }}</p>
                        <p class="text-sm text-ink-soft">{{ v.brand }} {{ v.model }}</p>
                    </div>
                    <span v-if="v.is_available"
                        class="inline-block rounded-full bg-status-ok-soft px-2 py-0.5 text-xs text-status-ok">
                        {{ $t('worker_vehicles.available') }}
                    </span>
                    <span v-else
                        class="inline-block rounded-full bg-status-danger-soft px-2 py-0.5 text-xs text-status-danger">
                        {{ $t('worker_vehicles.unavailable') }}
                    </span>
                </div>

                <VButton v-if="v.is_available" variant="secondary" size="sm" class="mt-3 w-full"
                    @click="openTake(v)">
                    {{ $t('worker_vehicles.take') }}
                </VButton>
            </div>
        </div>

        <!-- Take sheet -->
        <div v-if="takeTarget" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="takeTarget = null">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-4 text-base font-semibold">
                    {{ $t('worker_vehicles.take') }}: {{ takeTarget.plate_number }}
                </h2>
                <form @submit.prevent="submitTake" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.starting_mileage') }} *</label>
                        <VInput v-model="takeForm.starting_mileage" type="number"
                            :min="takeTarget.current_mileage ?? 0" required />
                        <p v-if="takeTarget.current_mileage" class="mt-0.5 text-xs text-muted">
                            {{ $t('worker_vehicles.current_mileage') }}: {{ takeTarget.current_mileage }} km
                        </p>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <VButton variant="ghost" class="flex-1" type="button" @click="takeTarget = null">
                            {{ $t('common.cancel') }}
                        </VButton>
                        <VButton class="flex-1" type="submit" :loading="takeBusy">
                            {{ $t('worker_vehicles.confirm_take') }}
                        </VButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- Return sheet -->
        <div v-if="returnOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="returnOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-4 text-base font-semibold">{{ $t('worker_vehicles.return') }}</h2>
                <form @submit.prevent="submitReturn" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.ending_mileage') }} *</label>
                        <VInput v-model="returnForm.ending_mileage" type="number"
                            :min="my_session?.starting_mileage ?? 0" required />
                        <p v-if="my_session?.starting_mileage" class="mt-0.5 text-xs text-muted">
                            {{ $t('worker_vehicles.starting_mileage') }}: {{ my_session.starting_mileage }} km
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.return_notes') }}</label>
                        <VTextarea v-model="returnForm.return_notes" :rows="2"
                            :placeholder="$t('worker_vehicles.optional')" />
                    </div>
                    <div class="flex gap-2 pt-1">
                        <VButton variant="ghost" class="flex-1" type="button" @click="returnOpen = false">
                            {{ $t('common.cancel') }}
                        </VButton>
                        <VButton class="flex-1" type="submit" :loading="returnBusy">
                            {{ $t('worker_vehicles.confirm_return') }}
                        </VButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- Fuel expense sheet -->
        <div v-if="fuelOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="fuelOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-1 text-base font-semibold">{{ $t('worker_vehicles.log_fuel') }}</h2>
                <p class="mb-4 text-xs text-muted">{{ $t('worker_vehicles.fuel_expense_hint') }}</p>
                <form @submit.prevent="submitFuel" class="space-y-3">
                    <!-- Price -->
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.fuel_amount') }} (€) *</label>
                        <VInput v-model="fuelForm.amount" type="number" step="0.01" min="0.01" required />
                    </div>
                    <!-- Notes -->
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.fuel_notes') }}</label>
                        <VTextarea v-model="fuelForm.description" :rows="2"
                            :placeholder="$t('worker_vehicles.optional')" />
                    </div>
                    <!-- Receipt upload -->
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.fuel_receipt') }}</label>
                        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-line-strong bg-surface-sunken px-3 py-2.5 text-sm text-ink-soft hover:bg-surface-hover">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6"
                                viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="18" height="18" rx="2" /><path d="M3 9h18M9 21V9" />
                            </svg>
                            <span class="truncate">{{ fuelReceiptLabel || $t('worker_vehicles.upload_receipt') }}</span>
                            <input type="file" class="sr-only" accept="image/*,application/pdf"
                                @change="onFuelReceipt" />
                        </label>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <VButton variant="ghost" class="flex-1" type="button" @click="fuelOpen = false">
                            {{ $t('common.cancel') }}
                        </VButton>
                        <VButton class="flex-1" type="submit" :loading="fuelBusy">
                            {{ $t('worker_vehicles.confirm_fuel') }}
                        </VButton>
                    </div>
                </form>
            </div>
        </div>
        <!-- Fines section -->
        <div v-if="fines.length" class="mt-6">
            <p class="mb-3 text-sm font-semibold text-ink">{{ $t('worker_vehicles.fines_title') }}</p>
            <div class="space-y-2">
                <div v-for="fine in fines" :key="fine.id"
                    class="rounded-lg border border-line bg-surface-raised p-4 shadow-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ fine.description }}</p>
                            <p class="text-xs text-ink-soft">{{ fine.fine_date }}<template v-if="fine.authority"> · {{ fine.authority }}</template></p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tabular-nums font-semibold text-ink">{{ Number(fine.amount).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} €</p>
                            <span class="text-xs"
                                :class="fine.paid ? 'text-status-ok' : 'text-status-danger'">
                                {{ $t(fine.paid ? 'worker_vehicles.fine_paid' : 'worker_vehicles.fine_unpaid') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </WorkerLayout>
</template>
