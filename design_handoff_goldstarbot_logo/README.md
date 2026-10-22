# Handoff: GoldStarBot Logo (v1)

## Overview
Logo and mascot mark for **GoldStarBot** — a Slack bot for peer recognition. This bundle contains the locked v1 logo ("Mascot bot — star up"): a friendly robot mascot raising a gold star, in a monospace wordmark lockup, plus app-icon/avatar variants.

## About the Design Files
The files in this bundle are **design references created in HTML/SVG** — they show the intended look of the logo, not production code to drop in. The mark itself is pure SVG, so the SVG assets *can* be used directly as-is. The `.dc.html` file is a reference/preview showing the lockups and sizing; recreate any surrounding UI in your codebase's existing environment and patterns.

## Fidelity
**High-fidelity.** Final colors, geometry, and typography. Use the provided SVGs as the source of truth; the PNGs are exported rasters.

## The Mark
A rounded-square-headed robot mascot with:
- An antenna (rod + ball) on top, two side "ears"
- A dark rounded screen face with two round green eyes and a green smile
- A rounded body with a small gold star emblem on the chest
- Two arms: the left arm raised holding a large gold star aloft; the right arm resting down
- A soft drop shadow ellipse beneath

Wordmark: lowercase **goldstarbot** set in Space Mono Bold, `letter-spacing: -0.02em`; "goldstar" in ink navy, "bot" in green.

## Variants
- **Full lockup** — mascot above the wordmark (vertical). See `goldstarbot-full.svg` (520×520) / `.png` (1040px).
- **App icon** — mascot head only (antenna, ears, screen face, raised star at top-left) on a mint vertical-gradient rounded square, 115/512 corner radius. See `goldstarbot-icon.svg` (512×512) / `.png` (1024px).
- **Transparent icon** — same head-only mark, no background plate. See `goldstarbot-icon-transparent.svg` / `.png` (1024px, alpha).

Note: the app-icon/small variant shows only the **head** (not the full body). The raised gold star sits at the head's top-left.

## Design Tokens (exact)
Colors:
- Ink / outline (navy): `#0C2A5A`
- Screen (dark navy): `#08213F`
- Gold (stars): `#F6B31A`
- Green (eyes, smile, "bot" wordmark): `#22A85A`
- Green dark (small "bot" on light footers): `#1B8E4A`
- Mint fill light (head, body, hands, antenna ball): `#EAF6EF`
- Mint fill mid (ears, antenna rod, arms inner): `#CDEBD8`
- Mint highlight strokes (glossy sheen): `#5FD08C` (used at 0.5–0.6 opacity)
- Background gradient (icon plate / stage): `linear-gradient(180deg, #d8f5e3, #f2fdf6)`
- Page/base surface: `#EAF7EE`

Stroke weights (in the mascot's 260×360 viewBox): body/head/limbs outline `7`; ears `6`; hands `6`; antenna `5`; arm underlay mint `16` inside navy `28`; smile `6`; star outline `7`.

Icon plate: 512×512, corner radius `115`. App-icon in-UI radius `30px` at 132px; small sidebar radius `14px` at 64px.

Typography:
- Family: **Space Mono**, weight **700** (Google Fonts)
- Wordmark: `letter-spacing: -0.02em`, lowercase
- Body copy in the reference uses a sans-serif fallback; not part of the logo.

## Assets
All vector, authored here (no third-party imagery):
- `goldstarbot-full.svg` / `goldstarbot-full.png`
- `goldstarbot-icon.svg` / `goldstarbot-icon.png`
- `goldstarbot-icon-transparent.svg` / `goldstarbot-icon-transparent.png`

For a favicon, export the icon SVG at 16/32/48/180px. Prefer the SVGs for any new size — they scale losslessly.

## Files
- `GoldStarBot Logo v1.dc.html` — reference preview of the locked v1 mark (full lockup + icon sizes + palette).
- `exports/` — the SVG and PNG assets listed above.
