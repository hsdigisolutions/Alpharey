<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover so the worker app can pad around an iPhone notch
         and home indicator via env(safe-area-inset-*). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ config('app.name', 'AlphaRey') }}</title>

    {{-- PWA (Worker app). The manifest is scoped to /worker, so installing
         only ever installs the worker app, never the CRM. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1F1E1B">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

    {{-- iOS has no install API: it only honours these meta tags plus a manual
         "Add to Home Screen". apple-mobile-web-app-capable is what makes the
         installed app open without Safari chrome. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="AlphaRey">

    {{-- Apply the saved theme before first paint to avoid a flash. The worker
         PWA is ALWAYS light (readable in daylight on site), so it opts out of
         dark here too — otherwise a phone in dark mode would flash dark before
         the layout corrects it. --}}
    <script>
        (function () {
            if (window.location.pathname.startsWith('/worker')) return;
            const theme = localStorage.getItem('theme');
            if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full bg-surface text-ink antialiased">
    @inertia
</body>
</html>
