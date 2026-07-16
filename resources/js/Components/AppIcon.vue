<script setup>
/**
 * Verto5 line-icon set (D1): 1.7px stroke on a 24px grid. Icons never appear
 * without a text label except inside the icon-button pattern, which must
 * provide an aria-label.
 */
const props = defineProps({
    name: { type: String, required: true },
    class: { type: String, default: 'h-5 w-5' },
});

const paths = {
    /* navigation */
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
    apps: ['M4 4h4v4H4z', 'M10 4h4v4h-4z', 'M16 4h4v4h-4z', 'M4 10h4v4H4z', 'M10 10h4v4h-4z', 'M16 10h4v4h-4z', 'M4 16h4v4H4z', 'M10 16h4v4h-4z', 'M16 16h4v4h-4z'],
    deployments: ['M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z', 'M3 20a5 5 0 0 1 10 0', 'M16 5l4 4-4 4', 'M20 9h-6'],

    /* actions */
    plus: ['M12 5v14M5 12h14'],
    search: ['M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16z', 'M21 21l-4.3-4.3'],
    edit: ['M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z'],
    trash: ['M4 7h16', 'M9 7V4h6v3', 'M6 7l1 13h10l1-13', 'M10 11v6M14 11v6'],
    upload: ['M12 16V4', 'M6 10l6-6 6 6', 'M4 20h16'],
    download: ['M12 4v12', 'M6 10l6 6 6-6', 'M4 20h16'],
    export: ['M14 4h6v6', 'M20 4 10 14', 'M9 6H5a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4'],
    filter: ['M4 5h16', 'M7 12h10', 'M10 19h4'],
    columns: ['M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5z', 'M9 4v16', 'M15 4v16'],
    settings: ['M4 8h9', 'M17 8h3', 'M14 5v6', 'M4 16h3', 'M11 16h9', 'M8 13v6'],
    logout: ['M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4', 'M16 8l4 4-4 4', 'M20 12H9'],
    camera: ['M4 8a1 1 0 0 1 1-1h2.5l1.5-2h6l1.5 2H19a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8z', 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z'],
    eye: ['M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z', 'M12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],

    /* chevrons & marks */
    'chevron-down': ['M6 9l6 6 6-6'],
    'chevron-up': ['M18 15l-6-6-6 6'],
    'chevron-left': ['M15 18l-6-6 6-6'],
    'chevron-right': ['M9 6l6 6-6 6'],
    check: ['M4 12.5l5 5L20 6.5'],
    x: ['M6 6l12 12M18 6 6 18'],
    dots: ['M5 12h.01M12 12h.01M19 12h.01'],

    /* status & misc */
    bell: ['M6 9a6 6 0 1 1 12 0c0 5 2 6 2 6H4s2-1 2-6', 'M10.5 20a2 2 0 0 0 3 0'],
    alert: ['M12 3l10 18H2L12 3z', 'M12 10v4M12 17.5h.01'],
    info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'M12 11v5M12 8h.01'],
    calendar: ['M4 6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6z', 'M8 3v4M16 3v4', 'M4 10h16'],
    euro: ['M17 6.5a6.5 6.5 0 1 0 0 11', 'M4.5 10.5h8M4.5 13.5h8'],
    file: ['M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z', 'M14 3v5h5'],
    user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M4 20a8 8 0 0 1 16 0'],
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
