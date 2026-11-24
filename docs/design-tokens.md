# Design tokens

The design lives as a canvas of 22 artboards:
<https://claude.ai/artifact/2NKx859ujDu9cA7n8UWLuE>

That canvas is the **reference** — screens, copy, spacing, states. It is not code to import: the
artboards are files that need the canvas runtime to render, so nothing from them drops into a Blade
view. What does cross over is this file. Every token below is transcribed from the canvas's own
Tokens and Components artboards, so the build never depends on opening a private link.

The canvas is private. Anyone else who needs to see it has to be given access from its Share menu
first.

## How to use this

Paste the `@theme` block into `resources/css/app.css` under the Tailwind import. Tailwind 4 reads it
and generates `bg-surface`, `text-muted`, `rounded-card` and the rest. Then build the Blade
components in [storefront.md](storefront.md) out of those utilities, and never write a hex value in
a template again.

```css
@import "tailwindcss";

@theme {
  /* ground and surface — dark is the default */
  --color-bg:            #0B0C10;
  --color-sunken:        #12141A;
  --color-surface:       #171A21;
  --color-raised:        #1E222B;
  --color-line:          #2A2F3A;
  --color-line-strong:   #3A404E;

  /* text, three roles only */
  --color-ink:           #F3F4F7;   /* 18.4:1 on bg */
  --color-muted:         #A8B0BF;   /*  8.9:1 */
  --color-faint:         #7D8597;   /*  5.2:1 */

  /* one accent, plus semantics */
  --color-accent:        #E7B64E;   /* 10.4:1 — the only filled button, and prices */
  --color-accent-ink:    #121318;   /* text on the accent */
  --color-stock:         #49C98A;
  --color-low:           #F09A4E;
  --color-danger:        #F0616D;
  --color-info:          #5B9BE8;

  /* rarity — borders and letters only, never a fill */
  --color-rarity-c:      #8A93A3;
  --color-rarity-u:      #49C98A;
  --color-rarity-r:      #5B9BE8;
  --color-rarity-rr:     #A77BE0;
  --color-rarity-sar:    #E7B64E;
  --color-rarity-sec:    #E87BB5;

  /* condition — green to red, and lightness falls with it, so the order
     survives colour blindness */
  --color-cond-nm:       #49C98A;
  --color-cond-lp:       #5B9BE8;
  --color-cond-mp:       #E7B64E;
  --color-cond-hp:       #F09A4E;
  --color-cond-dmg:      #F0616D;

  /* type */
  --font-display: "Anuphan", "IBM Plex Sans Thai", sans-serif;
  --font-sans:    "IBM Plex Sans Thai", "IBM Plex Sans", system-ui, sans-serif;

  --text-label:   12px;   --text-label--line-height: 16px;
  --text-small:   13px;   --text-small--line-height: 20px;
  --text-body:    15px;   --text-body--line-height: 1.6;
  --text-card:    16px;   --text-card--line-height: 1.4;
  --text-h3:      20px;   --text-h3--line-height: 1.3;
  --text-h2:      24px;   --text-h2--line-height: 1.25;
  --text-h1:      32px;   --text-h1--line-height: 1.2;
  --text-display: 48px;   --text-display--line-height: 1.1;

  /* radius */
  --radius-tag:    6px;
  --radius-control:10px;
  --radius-card:   14px;
  --radius-sheet:  20px;
}

/* light mode — only these change */
@layer theme {
  [data-theme="light"] {
    --color-bg:          #FAFAF8;
    --color-sunken:      #F4F4F0;
    --color-surface:     #FFFFFF;
    --color-raised:      #F0F0EB;
    --color-line:        #E6E6E1;
    --color-line-strong: #CFCFC8;
    --color-ink:         #16181D;   /* 16.6:1 */
    --color-muted:       #5A6170;   /*  6.1:1 */
    --color-faint:       #7A8090;
    --color-accent:      #C98B2E;   /* the fill */
    --color-accent-ink:  #1A1203;
    --color-stock:       #1F7A4D;
    --color-low:         #9A6512;
    --color-danger:      #C0392F;
    --color-info:        #1F5FA8;
  }
}
```

Dark is the default because card art sits on it better and the interface disappears behind the
product. Light is a real second theme, not an afterthought: on light, shadows are replaced by 1 px
borders, and the accent used **as text** darkens to `#8A6A1E` so it still passes 4.5:1.

## Type rules that are not in the token list

- **Thai body text is 15 px**, one step above the English equivalent, because Thai needs room for
  the head loops and the tone marks. Line height 1.6 to 1.7 for Thai, 1.45 for English.
- Weights are **400, 500, and 600 only**, and 600 only for the largest headings. Hierarchy comes
  from size and colour. There is no 700 anywhere.
- Sentence case everywhere. No uppercase tracked labels.
- Negative tracking only from 32 px up, at about −0.02em.
- Every price and every quantity uses `font-variant-numeric: tabular-nums`, so numbers in a column
  do not shuffle as they change.
- Nothing is smaller than 11 px, and 11 px only inside a drawn object such as a grading label.

## Spacing and layout

- Spacing scale: **4, 8, 12, 16, 20, 24, 32, 40, 48, 64**. Every margin, gap and padding is one of
  these.
- Phone edge padding 16. Card grid gap 12. Between blocks 24. Between sections 32 to 48.
- **Every control a finger touches is at least 44 px tall.** Badges are 24, chips and buttons 44,
  hero buttons 48.
- Card art is locked to **63 × 88** at every size, which is the real card ratio. It is in
  `<x-card-art>` and nowhere else.
- Grid: 2 columns at 390, 5 to 6 at 1440.

## The foil sheen

The one piece of continuous motion in the whole interface, and it earns its place: it tells a foil
card from a normal one across a grid without a badge, which is information, not decoration.

```css
.foil::after {
  content: "";
  position: absolute; inset: -20%;
  background: linear-gradient(115deg,
    rgba(255,255,255,0) 32%, rgba(255,255,255,.16) 45%,
    rgba(140,220,255,.13) 52%, rgba(255,180,220,.13) 58%,
    rgba(255,255,255,0) 71%);
  mix-blend-mode: screen;
  animation: sheen 5s linear infinite;
  pointer-events: none;
}
@keyframes sheen { 0% { transform: translateX(-38%) } 100% { transform: translateX(38%) } }
@media (prefers-reduced-motion: reduce) { .foil::after { animation: none } }
```

It goes on foil printings only. Never on a normal card, never on a button, never on a panel.

## Everything else that moves

One easing curve, `cubic-bezier(.22, 1, .36, 1)`, and three durations: 160 ms for hover and press,
220 ms for a sheet, 400 ms for an entrance. Hover changes colour or a surface step; press scales to
0.98. Nothing loops except the sheen, nothing moves on scroll, and every animation stops under
`prefers-reduced-motion`.

## Accessibility, as drawn

The canvas was built to these and the Blade components have to keep them:

- Text contrast 4.5:1 or better; the figures above each colour are measured on `--color-bg`.
- Colour never carries meaning alone — every condition and rarity has its letters, every stock state
  has words.
- Real `<button>`, `<a href>`, `<input>` with a visible `<label>`. Never a `div` with a click
  handler.
- A visible focus ring, 2 px in the accent, never animated.
- Icon-only buttons carry an `aria-label` in Thai.

## What the canvas has that this file does not

Copy, layout and the states. When you build a screen, open the matching artboard rather than
guessing:

| Page | Artboards |
|---|---|
| 01 · Foundations | the token sheet and the component library these values come from |
| 02 · IA & Flows | the sitemap and the three user flows |
| 03 · Wireframes | the structural pass, before colour |
| 04 · Mobile 390 | nine screens, two of them interactive |
| 05 · Desktop 1440 | five screens |
| 06 · States | empty, loading, out of stock, error, and light mode side by side |
