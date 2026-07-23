<script setup>
/**
 * Worker PWA — home / check-in screen.
 *
 * The whole flow lives here because it is one decision tree for the worker:
 * "what can I do right now?" — check in (with a selfie + a GPS fix), check
 * out, or report an absence. The screen shows exactly one primary action at a
 * time based on today's state from the server.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { t } from '@/translate';
import { getLocation } from '@/composables/useGeolocation';
import WorkerLayout from '@/Layouts/WorkerLayout.vue';
import SelfieCapture from '@/Components/Worker/SelfieCapture.vue';
import VButton from '@/Components/ui/VButton.vue';
import VTextarea from '@/Components/ui/VTextarea.vue';

const props = defineProps({
    worker: { type: Object, required: true },
    today: { type: Object, required: true },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error);

// --- Check IN: selfie, then a GPS fix, then submit ---
const camera = ref(null);
const cameraOpen = ref(false);
const cameraFailed = ref(false);
const photoBlob = ref(null);
const busy = ref(false);
const statusLine = ref('');

async function beginCheckIn() {
    cameraFailed.value = false;
    photoBlob.value = null;
    cameraOpen.value = true;
    await camera.value?.start();
}

function onCaptured(blob) {
    photoBlob.value = blob;
}

function onCameraError() {
    // Camera refused: allow the punch to proceed without a selfie rather than
    // trap the worker. The location flag will still record what happened.
    cameraFailed.value = true;
}

async function submitCheckIn() {
    busy.value = true;
    statusLine.value = t('worker.getting_location');

    // GPS never rejects — a refusal comes back as denied:true (see composable).
    const loc = await getLocation();

    const data = new FormData();
    data.append('lat', loc.lat ?? '');
    data.append('lng', loc.lng ?? '');
    data.append('accuracy', loc.accuracy ?? '');
    data.append('denied', loc.denied ? '1' : '0');
    if (photoBlob.value) data.append('photo', photoBlob.value, 'selfie.jpg');

    router.post('/worker/check-in', data, {
        forceFormData: true,
        onFinish: () => {
            busy.value = false;
            statusLine.value = '';
            cameraOpen.value = false;
        },
    });
}

async function checkOut() {
    busy.value = true;
    statusLine.value = t('worker.getting_location');

    const loc = await getLocation();

    router.post('/worker/check-out', {
        lat: loc.lat,
        lng: loc.lng,
        accuracy: loc.accuracy,
        denied: loc.denied,
    }, {
        onFinish: () => {
            busy.value = false;
            statusLine.value = '';
        },
    });
}

// --- Absence ---
const absenceOpen = ref(false);
const absenceForm = useForm({ note: '' });

function submitAbsence() {
    absenceForm.post('/worker/absence', {
        preserveScroll: true,
        onSuccess: () => {
            absenceForm.reset();
            absenceOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="$t('worker.title')" />

    <WorkerLayout :worker="worker">
        <!-- Today's status banner -->
        <div class="mb-4 rounded-lg border border-line bg-surface-raised p-4 text-center shadow-card">
            <p v-if="today.state === 'none'" class="text-sm text-ink-soft">
                <Bilingual k="worker.status_none" class="items-center" />
            </p>
            <p v-else-if="today.state === 'checked_in'" class="text-sm font-medium text-status-ok">
                {{ $t('worker.status_checked_in').replace(':time', today.check_in) }}
            </p>
            <p v-else-if="today.state === 'checked_out'" class="text-sm font-medium text-ink">
                {{ $t('worker.status_checked_out').replace(':hours', today.hours ?? 0) }}
            </p>
            <p v-else-if="today.state === 'absent'" class="text-sm font-medium text-status-warn">
                <Bilingual k="worker.status_absent" class="items-center" />
            </p>
        </div>

        <p v-if="flashError" class="mb-4 rounded-md bg-status-danger-soft px-3 py-2 text-center text-sm text-status-danger">
            {{ flashError }}
        </p>

        <!-- STATE: nothing yet today → check in (or report absence) -->
        <template v-if="today.state === 'none'">
            <div v-if="!cameraOpen" class="space-y-3">
                <VButton class="w-full" size="lg" @click="beginCheckIn">
                    <Bilingual k="worker.check_in" inline />
                </VButton>
                <button type="button" class="w-full py-2 text-sm text-ink-soft underline-offset-2 hover:underline"
                    @click="absenceOpen = true">
                    <Bilingual k="worker.report_absence" inline />
                </button>
            </div>

            <!-- Selfie step -->
            <div v-else class="space-y-3">
                <p class="text-center text-sm text-ink-soft">
                    <Bilingual v-if="!cameraFailed" k="worker.camera_prompt" class="items-center" />
                    <Bilingual v-else k="worker.camera_denied" class="items-center text-status-warn" />
                </p>

                <SelfieCapture v-if="!cameraFailed" ref="camera" @captured="onCaptured" @error="onCameraError" />

                <VButton v-if="!photoBlob && !cameraFailed" class="w-full" size="lg" @click="camera.capture()">
                    <Bilingual k="worker.take_photo" inline />
                </VButton>

                <template v-else>
                    <p v-if="statusLine" class="text-center text-xs text-muted">{{ statusLine }}</p>
                    <VButton class="w-full" size="lg" :loading="busy" @click="submitCheckIn">
                        <Bilingual k="worker.check_in" inline />
                    </VButton>
                    <button v-if="!cameraFailed" type="button" class="w-full py-2 text-sm text-ink-soft"
                        @click="beginCheckIn">
                        <Bilingual k="worker.retake" inline />
                    </button>
                </template>
            </div>
        </template>

        <!-- STATE: checked in → check out -->
        <template v-else-if="today.state === 'checked_in'">
            <p v-if="statusLine" class="mb-2 text-center text-xs text-muted">{{ statusLine }}</p>
            <VButton variant="secondary" class="w-full" size="lg" :loading="busy" @click="checkOut">
                <Bilingual k="worker.check_out" inline />
            </VButton>
        </template>

        <!-- STATE: done or absent → nothing more to do today -->
        <div v-else class="rounded-lg border border-line bg-surface-raised p-5 text-center text-sm text-ink-soft shadow-card">
            <Bilingual k="worker.done_for_today" class="items-center" />
        </div>

        <!-- Absence sheet -->
        <div v-if="absenceOpen" class="fixed inset-0 z-40 flex items-end bg-black/40" @click.self="absenceOpen = false">
            <div class="w-full rounded-t-xl bg-surface-raised p-5 shadow-overlay"
                style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                <h2 class="mb-3 text-base font-semibold"><Bilingual k="worker.absence_reason" /></h2>
                <form @submit.prevent="submitAbsence">
                    <VTextarea v-model="absenceForm.note" :rows="3" :placeholder="$t('worker.absence_placeholder')" />
                    <p v-if="absenceForm.errors.note" class="mt-1 text-xs text-status-danger">{{ absenceForm.errors.note }}</p>
                    <div class="mt-4 flex gap-2">
                        <VButton variant="ghost" class="flex-1" type="button" @click="absenceOpen = false">
                            <Bilingual k="common.cancel" inline />
                        </VButton>
                        <VButton class="flex-1" type="submit" :loading="absenceForm.processing">
                            <Bilingual k="worker.absence_submit" inline />
                        </VButton>
                    </div>
                </form>
            </div>
        </div>
    </WorkerLayout>
</template>
