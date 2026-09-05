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
 *
 * `teleport` renders the panel at <body> with fixed positioning anchored to the
 * trigger — needed when the trigger lives inside a clipping container (e.g. a
 * table's overflow-x-auto), where an absolutely-positioned panel would be cut
 * off. It closes on scroll (a fixed panel would otherwise drift from its
 * trigger). Default off, so every existing (absolute) usage is unchanged.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    align: { type: String, default: 'end' }, // start | end
    width: { type: String, default: 'w-56' },
    // auto flips when short of room below; force with 'top' / 'bottom'
    placement: { type: String, default: 'auto' }, // auto | top | bottom
    teleport: { type: Boolean, default: false },
});

const open = ref(false);
const root = ref(null);
const dropUp = ref(false);
const fixedStyle = ref({});

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

/** Fixed coordinates anchored to the trigger, for the teleported panel. */
function computeFixed() {
    const trigger = root.value?.firstElementChild;

    if (! trigger) {
        return;
    }

    const r = trigger.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    const up = props.placement === 'top'
        || (props.placement === 'auto' && below < 240 && r.top > below);
    dropUp.value = up;

    const style = { position: 'fixed' };
    // Anchor the panel's near edge to the trigger's; `right`/`bottom` avoid
    // needing the panel's own measured size.
    style[props.align === 'end' ? 'right' : 'left'] =
        (props.align === 'end' ? window.innerWidth - r.right : r.left) + 'px';
    if (up) {
        style.bottom = (window.innerHeight - r.top + 6) + 'px';
    } else {
        style.top = (r.bottom + 6) + 'px';
    }
    fixedStyle.value = style;
}

function toggle() {
    if (! open.value) {
        props.teleport ? computeFixed() : resolveDirection();
    }

    open.value = ! open.value;
}

function close() {
    open.value = false;
}

function onDocumentClick(event) {
    // The trigger lives in `root`; the teleported panel does not, so guard both.
    if (! open.value) {
        return;
    }
    const inRoot = root.value && root.value.contains(event.target);
    const inPanel = panel.value && panel.value.contains(event.target);
    if (! inRoot && ! inPanel) {
        close();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
    }
}

function onScroll() {
    // A fixed, teleported panel does not follow its trigger on scroll — close it.
    if (open.value && props.teleport) {
        close();
    }
}

const panel = ref(null);

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('scroll', onScroll, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('scroll', onScroll, true);
});

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative inline-block">
        <slot name="trigger" :toggle="toggle" :open="open" />

        <!-- Default: absolutely-positioned panel (unchanged). -->
        <transition v-if="! teleport"
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

        <!-- Teleported: fixed panel at <body>, escapes clipping containers. -->
        <Teleport v-else to="body">
            <transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100"
                leave-active-class="transition duration-100 ease-in"
                leave-from-class="scale-100 opacity-100"
                leave-to-class="scale-95 opacity-0">
                <div v-if="open" ref="panel" :style="fixedStyle"
                    class="z-50 max-h-[70vh] overflow-y-auto rounded-lg border border-line bg-surface-raised p-1 shadow-raised"
                    :class="[width, dropUp ? 'origin-bottom' : 'origin-top']">
                    <slot :close="close" />
                </div>
            </transition>
        </Teleport>
    </div>
</template>
