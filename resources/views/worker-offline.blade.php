<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sin conexión — AlphaRey</title>
    <meta name="theme-color" content="#1F1E1B">

    {{--
        Deliberately self-contained: no Vite bundle, no Inertia, no webfont.
        The service worker serves this page precisely when the network is gone,
        so anything it had to fetch would leave the worker staring at a blank
        screen. Tokens are inlined to match the design system.
    --}}
    <style>
        :root { --surface: #FAF9F7; --raised: #F5F4F0; --ink: #1A1A17; --ink-soft: #5C5C56; --line: #E2DED8; --accent: #D4956A; }
        @media (prefers-color-scheme: dark) {
            :root { --surface: #1A1916; --raised: #242320; --ink: #F0EDE8; --ink-soft: #B8B4AC; --line: #2E2C28; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem; background: var(--surface); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        .card {
            width: 100%; max-width: 24rem; text-align: center; padding: 2rem 1.5rem;
            background: var(--raised); border: 1px solid var(--line); border-radius: 12px;
        }
        .mark {
            width: 3rem; height: 3rem; margin: 0 auto 1rem; border-radius: 10px;
            background: var(--accent); color: #fff; font-weight: 700; font-size: 1.05rem;
            display: flex; align-items: center; justify-content: center;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.125rem; }
        p { margin: 0 0 1.25rem; font-size: .875rem; color: var(--ink-soft); line-height: 1.5; }
        .en { display: block; font-size: .8em; opacity: .6; }
        button {
            width: 100%; padding: .75rem 1rem; min-height: 44px; font: inherit; font-weight: 600;
            color: #fff; background: var(--accent); border: 0; border-radius: 8px; cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="mark">AR</div>
        <h1>Sin conexión <span class="en">No connection</span></h1>
        <p>
            No se ha podido conectar. El fichaje necesita conexión a internet.
            <span class="en">Could not connect. Checking in requires an internet connection.</span>
        </p>
        <button type="button" onclick="location.replace('/worker')">
            Reintentar <span class="en">Retry</span>
        </button>
    </div>
</body>
</html>
