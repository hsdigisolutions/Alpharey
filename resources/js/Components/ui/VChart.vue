<script setup>
/**
 * Chart.js wrapper (Screen 03 dashboard, Screen 14 reports).
 *
 * Chart.js renders to a canvas, which cannot read Tailwind/CSS-variable
 * classes — so we resolve the design tokens to concrete colours here via
 * getComputedStyle, and re-resolve when the theme flips (the `.dark` class on
 * <html>). That keeps every chart on the warm coral palette in both modes
 * without a single hardcoded hex.
 *
 * Chart.js is bundled through Vite, not a CDN — the CSP blocks external
 * origins, so this is the only safe way to ship it.
 */
// Register ONLY the Chart.js pieces this component uses (bar / line / doughnut)
// instead of `...registerables` (every controller, scale and element) — that
// pulled radar/polar/bubble/scatter, the time scales and unused plugins into
// the bundle for no reason. This chunk loads only on the 2 pages that chart.
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    DoughnutController,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

Chart.register(
    BarController, LineController, DoughnutController,
    BarElement, LineElement, PointElement, ArcElement,
    CategoryScale, LinearScale,
    Legend, Tooltip,
);

const props = defineProps({
    // 'bar' | 'line' | 'doughnut'
    type: { type: String, required: true },
    labels: { type: Array, required: true },
    // [{ key, label, data, role }] — role picks a token colour
    datasets: { type: Array, required: true },
    // doughnut: one dataset, one colour per slice (role list)
    sliceRoles: { type: Array, default: () => [] },
    currency: { type: Boolean, default: false },
    height: { type: Number, default: 240 },
});

const canvas = ref(null);
let chart = null;
let observer = null;

/** Resolve a CSS custom property to its computed value. */
function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/** Map a semantic role to a palette token. */
function roleColor(role) {
    return {
        accent: token('--color-accent'),
        revenue: token('--color-status-ok'),
        expense: token('--color-status-danger'),
        ok: token('--color-status-ok'),
        warn: token('--color-status-warn'),
        danger: token('--color-status-danger'),
        info: token('--color-status-info'),
        neutral: token('--color-status-neutral'),
        inprogress: token('--color-accent'),
    }[role] ?? token('--color-accent');
}

function buildData() {
    if (props.type === 'doughnut') {
        return {
            labels: props.labels,
            datasets: [{
                data: props.datasets[0]?.data ?? [],
                backgroundColor: props.sliceRoles.map(roleColor),
                borderColor: token('--color-surface-raised'),
                borderWidth: 2,
            }],
        };
    }

    return {
        labels: props.labels,
        datasets: props.datasets.map((set) => {
            const color = roleColor(set.role);

            return {
                label: set.label,
                data: set.data,
                backgroundColor: props.type === 'line' ? 'transparent' : color,
                borderColor: color,
                borderWidth: 2,
                borderRadius: props.type === 'bar' ? 4 : 0,
                tension: 0.3,
                pointRadius: props.type === 'line' ? 0 : undefined,
                fill: false,
            };
        }),
    };
}

function buildOptions() {
    const ink = token('--color-ink-soft');
    const line = token('--color-line');
    const currency = props.currency;

    const base = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: props.type !== 'doughnut' ? props.datasets.length > 1 : true,
                position: props.type === 'doughnut' ? 'right' : 'top',
                labels: { color: ink, boxWidth: 12, font: { size: 12 } },
            },
            tooltip: {
                callbacks: currency ? {
                    label: (ctx) => `${ctx.dataset.label ?? ctx.label}: ${Number(ctx.parsed.y ?? ctx.parsed).toLocaleString('es-ES', { minimumFractionDigits: 2 })} €`,
                } : {},
            },
        },
    };

    if (props.type === 'doughnut') {
        return base;
    }

    return {
        ...base,
        scales: {
            x: { ticks: { color: ink, font: { size: 11 } }, grid: { display: false } },
            y: {
                ticks: {
                    color: ink,
                    font: { size: 11 },
                    callback: (v) => currency ? `${Number(v).toLocaleString('es-ES')} €` : v,
                },
                grid: { color: line },
                beginAtZero: true,
            },
        },
    };
}

function render() {
    if (chart) {
        chart.destroy();
    }

    chart = new Chart(canvas.value, {
        type: props.type,
        data: buildData(),
        options: buildOptions(),
    });
}

onMounted(() => {
    render();

    // Re-render when the theme class on <html> changes.
    observer = new MutationObserver(() => render());
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

watch(() => [props.labels, props.datasets, props.sliceRoles], render, { deep: true });

onBeforeUnmount(() => {
    observer?.disconnect();
    chart?.destroy();
});
</script>

<template>
    <div :style="{ height: `${height}px` }">
        <canvas ref="canvas" />
    </div>
</template>
