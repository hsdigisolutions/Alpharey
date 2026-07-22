---
name: alpharey-design
description: AlphaRey CRM design system — warm Claude.ai-inspired palette, tokens, typography, component rules, dark mode, mobile rules. Read BEFORE touching any UI file (Vue components, Tailwind classes, CSS variables, any visual element). No exceptions.
---

# SKILL: AlphaRey CRM — Design System

## What this skill is for
Every time you touch any UI file in this project — Vue components,
Tailwind classes, CSS variables, or any visual element — read this
skill first. No exceptions.

> Installed 2026-07-13, adapted to the implemented codebase: token names below
> are the real CSS variables in `resources/css/app.css` (Tailwind 4 `@theme`),
> component references are the real files in `resources/js/Components/`.

---

## Design Direction
Warm, calm, minimal, professional.
Inspired by Claude.ai. Not a generic blue SaaS dashboard.
Think: Notion warmth + Linear precision + Claude.ai palette.

---

## Color Tokens — Use These. Nothing Else.

Defined once in `resources/css/app.css`. Components use the Tailwind
utilities they generate (`bg-surface`, `text-ink`, `border-line`…) —
**never raw hex in a Vue file.**

### Light Mode
```
--color-surface:          #FAF9F7   /* page background (warm cream)      */
--color-surface-raised:   #F5F4F0   /* card background (warm off-white)  */
--color-surface-sunken:   #ECEAE5   /* input background, table header    */
--color-surface-hover:    #F0EEE9   /* row/button hover                  */

--color-accent:           #D4956A   /* warm coral — primary action, active */
--color-accent-hover:     #C4845A
--color-accent-soft:      #F5E6D8   /* accent background tint            */
--color-on-accent:        #FFFFFF   /* text on coral                     */

--color-ink:              #1A1A17   /* headings, main text (text-primary)   */
--color-ink-soft:         #5C5C56   /* labels, supporting (text-secondary)  */
--color-muted:            #9C9A92   /* placeholders, hints (text-muted)     */
--color-faint:            #B8B5AC   /* disabled, decorative                 */

--color-line:             #E2DED8   /* card borders, dividers (border)        */
--color-line-strong:      #C8C4BC   /* input borders, tables (border-strong)  */

--color-status-ok:        #2D6A4F   + --color-status-ok-soft:      #D8F0E4
--color-status-warn:      #92620A   + --color-status-warn-soft:    #FEF3CD
--color-status-danger:    #8B2020   + --color-status-danger-soft:  #FDEAEA
--color-status-info:      #1A4E6B   + --color-status-info-soft:    #D8EAF5
--color-status-neutral:   #6B6963   + --color-status-neutral-soft: #ECEAE5  /* = "missing" */
```

### Dark Mode (`.dark` on <html>)
```
--color-surface:          #1A1916
--color-surface-raised:   #242320
--color-surface-sunken:   #141412
--color-accent:           #D4956A
--color-accent-hover:     #E4A57A
--color-accent-soft:      #3A2A1E
--color-ink:              #F0EDE8
--color-ink-soft:         #B8B4AC
--color-muted:            #6B6963
--color-line:             #2E2C28
--color-line-strong:      #3E3C38
```

### Sidebar (same in both modes — always dark, per spec)
```
--color-sidebar:          #1F1E1B   /* dark, not pure black */
--color-sidebar-ink:      #B8B4AC   /* inactive item text   */
--color-sidebar-hover:    rgba(255,255,255,0.08)
Active item: bg-accent text-on-accent
```

---

## Typography

### Typeface
Inter — **self-hosted via npm (`@fontsource-variable/inter`)**, imported in
`app.css`. Never a Google Fonts/CDN link — CSP blocks external origins.
Fallback: system-ui, sans-serif.

### Scale
```
display:    24px / 700 / -0.02em   /* company name on Welcome screen */
title:      18px / 600 / -0.01em   /* page titles, modal titles */
section:    15px / 600 / 0         /* section headings, tab labels */
body:       14px / 400 / 0         /* default UI text */
small:      13px / 400 / 0         /* secondary text, form hints */
caption:    12px / 500 / 0.04em    /* table headers — UPPERCASE */
numeric:    14px / tabular-nums    /* all EUR amounts, times, counts */
```

### Bilingual Label Pattern
Every label shows Spanish primary + English secondary. The real component
takes a **translation key** (both dictionaries ship on every Inertia page):

```vue
<!-- Stacked (default — sidebar, page titles, form labels) -->
<Bilingual k="nav.employees" />

<!-- Inline (tight spaces — table headers, badges, buttons) -->
<Bilingual k="nav.employees" inline />
```

Spanish: body size; English: ~0.72em, rendered at reduced opacity of the
current color (so it works on any background, including coral buttons).

Never hard-code Spanish or English text directly in a component.
Always add keys to `lang/es/ui.php` + `lang/en/ui.php` and use `<Bilingual k>`.

---

## Spacing

Base unit: 4px (Tailwind grid)
```
xs 4 · sm 8 · md 12 · lg 16 (card padding) · xl 24 (between sections)
2xl 32 (page-level) · 3xl 48 (large breaks)
```

---

## Borders and Radius
```
rounded-sm:   6px    /* badges, chips, small buttons */
rounded-md:   8px    /* inputs, dropdowns, buttons */
rounded-lg:   12px   /* cards, modals, slide panels */
rounded-xl:   16px   /* company cards on Welcome */
rounded-full         /* avatars, pills */

border-width: 1px always
border-color: border-line (default) or border-line-strong (inputs)
```

---

## Elevation (Shadows)
Minimal. Never dramatic.
```
shadow-card:    0 1px 2px rgba(0,0,0,0.06)    /* cards */
shadow-raised:  0 2px 8px rgba(0,0,0,0.08)    /* dropdowns, popovers */
shadow-overlay: 0 4px 24px rgba(0,0,0,0.10)   /* modals, slide panels */
```

---

## Component Rules

The library lives in `resources/js/Components/ui/`. Never build a new
component inside a page — add it to the library first.

### Buttons — `VButton.vue`
```
primary:   bg-accent text-on-accent hover:bg-accent-hover
secondary: bg-surface-raised border border-line-strong text-ink
ghost:     text-ink-soft hover:text-ink hover:bg-surface-sunken
danger:    status-danger treatment

Loading: spinner, click disabled, same size — never change width on loading
```

### Inputs — `VInput/VSelect/VTextarea/VDateInput/VCurrencyInput/VSearchInput`
```
border border-line-strong rounded-md bg-surface-sunken
text-ink placeholder:text-muted
focus ring: accent
```

### Cards — `VCard.vue`
```
bg-surface-raised rounded-lg border border-line shadow-card p-4/p-6
No heavy shadows. Clean separation from the cream page background.
```

### Tables — `VTable + VTableToolbar + VPagination + VBulkBar`
```
Header:    bg-surface-sunken caption-style uppercase text-muted
Row:       bg-surface-raised border-b border-line
Row hover: bg-surface-hover
Selected:  bg-accent-soft border-s-2 border-accent
```

### Modals and Slide Panels — `VModal.vue / VSlideOver.vue`
```
Overlay: bg-black/40 backdrop-blur-sm · Panel: bg-surface-raised shadow-overlay
Mobile: full screen, no radius
Create/edit ALWAYS goes here — never a separate page
```

### Status Badges / Traffic Light — `VBadge.vue / VStatusDot.vue`
```
ok      Valid/Active/Paid/Present      • Green
warn    Expiring/Pending/Late          • Amber
danger  Expired/Overdue/Absent/Error   • Red
info    Informational/Leave/Deployed   • Blue
neutral Missing/Not uploaded/Unknown   • Grey
```

### Sidebar — in `Layouts/AppLayout.vue`
```
Background:   sidebar token (#1F1E1B — dark, not pure black, both modes)
Active item:  bg-accent text-on-accent rounded-md
Inactive:     text-sidebar-ink, hover: white text on white/8
Brand logo:   top, 16px padding · User area: header (top-right)
Width:        240px expanded, 56px collapsed (icons only)
Position:     FIXED — the sidebar never scrolls with content; only the
              right content area scrolls (content column gets ps-60/ps-14)
Mobile:       bottom nav bar, 5 primary items — no sidebar
```

---

## VAT Dropdown Display — `VVatSelect.vue`

Every VAT field uses `App\Enums\VatRate` options (`VatRate::options()`
passed as a page prop). Blank ("No aplica / Not applicable") is the default.
```
IVA General 21% · IVA Reducido 10% · IVA Superreducido 4% · Exento 0% · No aplica —
```
When blank/null: show "—" muted — never "0%" or "null".
When a rate is selected: VAT amount shows separately below the subtotal.

---

## Dark / Light Mode

Toggle in header (`.dark` class + localStorage; per-account persistence
lands with Phase 1 auth). Both modes must be designed and tested for every
component. Never use colors that only work in one mode. Always CSS
variables — never hardcode hex in components.

---

## Mobile Rules

All 26 screens must work on a 375px viewport.
```
Sidebar     → bottom navigation bar (5 primary items)
Tables      → horizontal scroll, sticky first column
Modals      → full screen, no border radius
Slide panel → full screen
Calendar    → day view (not month grid)
Charts      → key numbers only on very small screens
Buttons     → minimum 44px touch target
```

---

## What NOT to Do

```
❌ No blue accent (#3B82F6, indigo, or similar) — coral #D4956A only
❌ No pure white page backgrounds (#FFFFFF) — warm cream surfaces
❌ No pure black text (#000000) — use ink #1A1A17
❌ No harsh drop shadows · No gradient backgrounds
❌ No rounded corners > 16px on cards
❌ No more than 3 font weights on one screen
❌ No hardcoded color hex in Vue components — always tokens
❌ No new components without adding to Components/ui/ first
❌ No lorem ipsum — always real Spanish/English copy
❌ No separate page for create/edit — always modal or slide panel
❌ No external font/CDN links — CSP blocks them; self-host via npm
```

---

## Checklist Before Committing Any UI Change

- [ ] Colors use tokens, not hardcoded hex
- [ ] All text goes through the translation layer (`lang/*/ui.php`)
- [ ] `<Bilingual k>` used for every label
- [ ] Both light and dark mode tested
- [ ] Mobile 375px viewport tested
- [ ] Status badges use the correct semantic color
- [ ] VAT fields follow the VatRate display rules
- [ ] New components added to `Components/ui/` and the `/styleguide` page
