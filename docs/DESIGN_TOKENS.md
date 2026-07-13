# Verto5 Design Tokens — D1 (warm Claude.ai palette)

Single source of rules: **`.claude/skills/verto5-design/SKILL.md`** (the design skill —
read it before touching any UI file). Values live in `resources/css/app.css`
(`@theme` block + `.dark` overrides). Components reference **semantic names only** —
never raw hex. Retheming is a token edit, not a refactor.

## Direction (client, 2026-07-13)

Warm, calm, minimal, professional — Claude.ai inspired. Warm cream surfaces, warm
coral accent, warm near-black typography. **No blue accent. No pure white backgrounds.
No harsh colors.** Not a generic SaaS blue dashboard.

## Color

| Group | Light | Dark |
|---|---|---|
| Page / card / well | `#FAF9F7` / `#F5F4F0` / `#ECEAE5` | `#1A1916` / `#242320` / `#141412` |
| Accent (coral) | `#D4956A`, hover `#C4845A`, soft `#F5E6D8` | `#D4956A`, hover `#E4A57A`, soft `#3A2A1E` |
| Ink / soft / muted | `#1A1A17` / `#5C5C56` / `#9C9A92` | `#F0EDE8` / `#B8B4AC` / `#6B6963` |
| Lines | `#E2DED8`, strong `#C8C4BC` | `#2E2C28`, strong `#3E3C38` |
| Sidebar (both modes) | `#1F1E1B`, ink `#B8B4AC`, active = accent | same |
| Status ok / warn / danger / info / neutral | `#2D6A4F` / `#92620A` / `#8B2020` / `#1A4E6B` / `#6B6963` + warm soft backgrounds | lightened warm variants |

Charts: 6 warm categorical colors (`--color-chart-1…6`), coral first.

## Typography

**Inter Variable**, self-hosted via npm (`@fontsource-variable/inter`) — external font
CDNs are blocked by CSP. Scale: display 24/700 · title 18/600 · section 15/600 ·
body 14 · small 13 · caption 12/500 uppercase · money/hours always `tabular-nums`.

**Bilingual pairing:** Spanish at role size; English at ~0.72em rendered at reduced
opacity of the current color — legible on cream, coral, and the dark sidebar alike.

## Radii, elevation, motion

- Radii: 6 (badges) · 8 (inputs/buttons) · 12 (cards/modals) · 16 (company cards)
- Shadows: `0 1px 2px /.06` cards · `0 2px 8px /.08` dropdowns · `0 4px 24px /.10` modals
- Motion: 120/200/320ms on a soft ease-out; no decorative animation

## History

- 2026-07-13: initial placeholder palette (indigo accent, Instrument Sans) replaced by
  the client-approved warm coral direction + Inter; design/development skills installed
  under `.claude/skills/`.
