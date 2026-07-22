<?php

/**
 * Guards the bilingual system against the regression the client reported:
 * strings that never followed the ES/EN toggle because they were hardcoded in
 * a component instead of going through the dictionary.
 *
 * These scan the built Vue source, so they fail on the PATTERN, not on one
 * instance of it — a new page that hardcodes a title or pins a lookup to
 * `lang.es` breaks the suite rather than shipping a half-translated screen.
 */
function vueFiles(): array
{
    $root = __DIR__.'/../../resources/js';
    $files = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'vue') {
            // The styleguide is a dev-only reference page, not a user screen.
            if (str_contains($file->getFilename(), 'Styleguide')) {
                continue;
            }
            $files[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never pins a translation lookup to one language', function (): void {
    // `lang.es.foo.bar` ignores the active locale — the exact reason the EN
    // toggle looked broken. Bilingual.vue resolves the locale itself.
    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        if (str_contains($path, 'Bilingual.vue')) {
            continue;
        }

        if (preg_match('/lang\.(es|en)\.|lang\[.(es|en).\]/', $source)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'Use $t() / $tPair() — a lang.es lookup never follows the ES/EN toggle');
});

it('never hardcodes a browser tab title', function (): void {
    // <Head title="Nóminas"> left the tab in Spanish forever.
    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        if (preg_match('/<Head\s+title="/', $source)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'Use <Head :title="$t(\'…\')" /> so the tab follows the locale');
});

it('never freezes a "Spanish / English" pair into the markup', function (): void {
    // 'Buscar / Search' always rendered Spanish-first, whatever the locale.
    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        // a quoted literal containing " / " between two capitalised words
        if (preg_match("/'[A-ZÁÉÍÓÚÑ][^']{2,} \/ [A-Z][^']{2,}'/u", $source)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'Use $tPair(\'key\') so the pair flips with the locale');
});

it('never renders the same key twice as a fake pair', function (): void {
    // The sweep that fixed the above briefly produced "Status / Status" by
    // collapsing a deliberate es+en pair into one locale. $tPair is the fix.
    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        if (preg_match('/\$t\(\'([a-zA-Z_.]+)\'\)\s*\}\}\s*\/\s*\{\{\s*\$t\(\'\1\'\)/', $source)) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([], 'Duplicated key both sides of "/" — use $tPair(\'key\')');
});

it('keeps the two dictionaries structurally in step', function (): void {
    $es = require __DIR__.'/../../lang/es/ui.php';
    $en = require __DIR__.'/../../lang/en/ui.php';

    $flatten = function (array $a, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            $out = is_array($v) ? array_merge($out, $flatten($v, $key)) : array_merge($out, [$key]);
        }

        return $out;
    };

    $esKeys = $flatten($es);
    $enKeys = $flatten($en);

    // A key present in one language only = a blank or a fallback on screen.
    expect(array_values(array_diff($esKeys, $enKeys)))->toBe([])
        ->and(array_values(array_diff($enKeys, $esKeys)))->toBe([]);
});

it('never renders a sentence through the nowrap inline variant', function (): void {
    // <Bilingual inline> is whitespace-nowrap so a short label cannot break
    // between its two languages. Point it at a sentence and the element
    // refuses to wrap at all: the New Deployment modal grew wider than the
    // viewport and scrolled sideways, with the fields pushed off-screen.
    $es = require __DIR__.'/../../lang/es/ui.php';

    $lookup = function (string $key) use ($es): ?string {
        $node = $es;
        foreach (explode('.', $key) as $part) {
            $node = is_array($node) ? ($node[$part] ?? null) : null;
        }

        return is_string($node) ? $node : null;
    };

    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        preg_match_all('/<Bilingual\s+k="([a-zA-Z_.]+)"\s+inline\s*\/?>/', $source, $matches);

        foreach ($matches[1] as $key) {
            $text = $lookup($key);

            if ($text !== null && mb_strlen($text) > 60) {
                $offenders[] = basename($path).": {$key}";
            }
        }
    }

    expect($offenders)->toBe([], 'Long string in <Bilingual inline> — drop "inline" so it can wrap');
});

it('always hands FormField and VModal their real translation-key prop', function (): void {
    // FormField takes `k`, VModal takes `title-key`. Passing anything else
    // (label-key=…) silently drops the label — found live on both 2FA screens,
    // where the code field rendered without its name.
    $offenders = [];

    foreach (vueFiles() as $path => $source) {
        if (preg_match('/<FormField[^>]*\blabel-key=/', $source)) {
            $offenders[] = basename($path).': FormField label-key= (use k=)';
        }

        if (preg_match('/<VModal[^>]*\s:?k=/', $source)) {
            $offenders[] = basename($path).': VModal k= (use title-key=)';
        }
    }

    expect($offenders)->toBe([]);
});
