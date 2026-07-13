<script setup>
/**
 * Minimal inline icon set for the Phase 0 shell. Design phase D1/D2 delivers
 * the real iconography; only the shell references these.
 */
const props = defineProps({
    name: { type: String, required: true },
    class: { type: String, default: 'h-5 w-5' },
});

const paths = {
    dashboard: ['M3 3h7v7H3z', 'M14 3h7v7h-7z', 'M3 14h7v7H3z', 'M14 14h7v7h-7z'],
    companies: ['M3 21h18', 'M5 21V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16', 'M9 8h2M9 12h2M9 16h2', 'M15 10h3a1 1 0 0 1 1 1v10'],
    employees: ['M9 11.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z', 'M3 20a6 6 0 0 1 12 0', 'M16.5 11a3 3 0 1 0-1.5-5.6', 'M15.5 15.5A5.5 5.5 0 0 1 21 20'],
    clients: ['M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2', 'M4 7h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z', 'M3 12h18'],
    projects: ['M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z'],
    invoices: ['M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z', 'M14 3v5h5', 'M9 13h6M9 17h6'],
    attendance: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 7v5l3 3'],
    payroll: ['M2 7h20v10H2z', 'M12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z', 'M5.5 12h.01M18.5 12h.01'],
    calls: ['M4 4h4l2 5-2.5 1.5a12 12 0 0 0 6 6L15 14l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 2 6a2 2 0 0 1 2-2z'],
    reports: ['M5 20v-6M11 20V8M17 20V10', 'M3 20h18'],
    sun: ['M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10z', 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4'],
    moon: ['M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z'],
    menu: ['M4 6h16M4 12h16M4 18h16'],
};
</script>

<template>
    <svg :class="props.class" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path v-for="(d, i) in paths[name] ?? []" :key="i" :d="d" />
    </svg>
</template>
