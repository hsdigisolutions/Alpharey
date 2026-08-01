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
import { onMounted, onUnmounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';
import VButton from '@/Components/ui/VButton.vue';
import VInput from '@/Components/ui/VInput.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    worker: { type: Object, required: true },
    vehicles: { type: Array, required: true },
    // The currently active session for this worker (null if none).
    // eslint-disable-next-line vue/prop-name-casing
    my_session: { type: Object, default: null },
});

// ── Polling ──────────────────────────────────────────────────────────────────
let pollTimer = null;

function poll() {
    router.reload({ only: ['vehicles', 'my_session'] });
}

onMounted(() => {
    pollTimer = setInterval(poll, 30_000);
});

onUnmounted(() => {
    clearInterval(pollTimer);
});

// ── Take sheet ───────────────────────────────────────────────────────────────
const takeTarget = ref(null);  // { id, plate }
const takeForm = ref({ starting_mileage: '', starting_fuel_level: '' });
const takeBusy = ref(false);

function openTake(vehicle) {
    takeTarget.value = vehicle;
    takeForm.value = { starting_mileage: '', starting_fuel_level: '' };
}

function submitTake() {
    takeBusy.value = true;
    router.post(`/worker/vehicles/${takeTarget.value.id}/take`, takeForm.value, {
        onFinish: () => { takeBusy.value = false; takeTarget.value = null; },
    });
}

// ── Return sheet ─────────────────────────────────────────────────────────────
const returnOpen = ref(false);
const returnForm = ref({ ending_mileage: '', ending_fuel_level: '', return_notes: '' });
const returnBusy = ref(false);

function submitReturn() {
    returnBusy.value = true;
    router.post(`/worker/vehicle-sessions/${props.my_session?.id}/return`, returnForm.value, {
        onFinish: () => { returnBusy.value = false; returnOpen.value = false; },
    });
}

// ── Fuel log sheet ───────────────────────────────────────────────────────────
const fuelOpen = ref(false);
const fuelLitres = ref('');
const fuelBusy = ref(false);

function submitFuel() {
    fuelBusy.value = true;
    router.post(`/worker/vehicle-sessions/${props.my_session?.id}/fuel`, { litres: fuelLitres.value }, {
        onFinish: () => { fuelBusy.value = false; fuelOpen.value = false; fuelLitres.value = ''; },
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

        <!-- Active session banner -->
        <div v-if="my_session" class="mb-4 rounded-lg border border-accent bg-accent-soft p-4 shadow-card">
            <p class="text-sm font-semibold text-accent">{{ $t('worker_vehicles.active_session') }}</p>
            <p class="mt-1 text-sm text-ink">
                {{ my_session.vehicle?.plate_number }} &mdash; {{ $t('worker_vehicles.taken_at') }}: {{ my_session.taken_at }}
            </p>
            <div class="mt-3 flex gap-2">
                <VButton variant="secondary" class="flex-1" size="sm" @click="fuelOpen = true">
                    {{ $t('worker_vehicles.log_fuel') }}
                </VButton>
                <VButton class="flex-1" size="sm" @click="returnOpen = true">
                    {{ $t('worker_vehicles.return') }}
                </VButton>
            </div>
        </div>

        <!-- Vehicle list -->
        <div class="space-y-3">
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

                    <!-- Availability indicator -->
                    <div class="shrink-0 text-right">
                        <span v-if="my_session?.vehicle?.id === v.id" class="inline-block rounded-full bg-accent-soft px-2 py-0.5 text-xs text-accent">
                            {{ $t('worker_vehicles.mine') }}
                        </span>
                        <span v-else-if="v.is_available"
                            class="inline-block rounded-full bg-status-ok-soft px-2 py-0.5 text-xs text-status-ok">
                            {{ $t('worker_vehicles.available') }}
                        </span>
                        <span v-else
                            class="inline-block rounded-full bg-status-danger-soft px-2 py-0.5 text-xs text-status-danger">
                            {{ $t('worker_vehicles.unavailable') }}
                        </span>
                    </div>
                </div>

                <!-- Take button (only if available and worker has no open session) -->
                <VButton v-if="v.is_available && !my_session" variant="secondary" size="sm" class="mt-3 w-full"
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
                        <VInput v-model="takeForm.starting_mileage" type="number" min="0" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.starting_fuel') }} (%)</label>
                        <VInput v-model="takeForm.starting_fuel_level" type="number" min="0" max="100" placeholder="{{ $t('worker_vehicles.optional') }}" />
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
                        <VInput v-model="returnForm.ending_mileage" type="number" min="0" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.ending_fuel') }} (%)</label>
                        <VInput v-model="returnForm.ending_fuel_level" type="number" min="0" max="100" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.return_notes') }}</label>
                        <VTextarea v-model="returnForm.return_notes" :rows="2" />
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

        <!-- Fuel log sheet -->
        <div v-if="fuelOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="fuelOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-4 text-base font-semibold">{{ $t('worker_vehicles.log_fuel') }}</h2>
                <form @submit.prevent="submitFuel" class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-ink-soft">{{ $t('worker_vehicles.fuel_litres') }} *</label>
                        <VInput v-model="fuelLitres" type="number" step="0.01" min="0.1" required />
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
    </WorkerLayout>
</template>
