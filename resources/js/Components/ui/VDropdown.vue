<script setup>
/**
 * Generic popover: trigger slot + floating panel. Closes on outside click
 * and Escape. Used by the user menu, apps menu, column pickers, and any
 * custom select.
 *
 * The panel FLIPS above the trigger when there is not enough room below it.
 * Without that, a trigger near the bottom of the viewport — the sidebar's
 * "Más módulos" button sits right above the taskbar — opened a menu that ran
 * off the bottom of the screen, showing only its first row. It is also height-
 * capped and scrollable so a long list (19 modules) can never outgrow the
 * viewport.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    align: { type: String, default: 'end' }, // start | end
    width: { type: String, default: 'w-56' },
    // auto flips when short of room below; force with 'top' / 'bottom'
    placement: { type: String, default: 'auto' }, // auto | top | bottom
});

const open = ref(false);
const root = ref(null);
const dropUp = ref(false);

/** Decide direction from the room actually available below the trigger. */
function resolveDirection() {
    if (props.placement !== 'auto') {
        dropUp.value = props.placement === 'top';

        return;
    }

    const trigger = root.value?.firstElementChild;

    if (! trigger) {
        dropUp.value = false;

        return;
    }

    const { bottom, top } = trigger.getBoundingClientRect();
    const below = window.innerHeight - bottom;

    // Flip up only when there is genuinely more room above — otherwise stay
    // down and let the max-height + scroll handle it.
    dropUp.value = below < 240 && top > below;
}

function toggle() {
    if (! open.value) {
        resolveDirection();
    }

    open.value = ! open.value;
}

function close() {
    open.value = false;
}

function onDocumentClick(event) {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        close();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative inline-block">
        <slot name="trigger" :toggle="toggle" :open="open" />
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="scale-95 opacity-0"
            enter-to-class="scale-100 opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="scale-100 opacity-100"
            leave-to-class="scale-95 opacity-0">
            <div v-if="open"
                class="absolute z-30 max-h-[70vh] overflow-y-auto rounded-lg border border-line bg-surface-raised p-1 shadow-raised"
                :class="[
                    width,
                    align === 'end' ? 'end-0' : 'start-0',
                    dropUp ? 'bottom-full mb-1.5 origin-bottom' : 'top-full mt-1.5 origin-top',
                ]">
                <slot :close="close" />
            </div>
        </transition>
    </div>
</template>
