# The SASA design system

One palette, one spacing rhythm, one motion language, two themes.

Everything visual is defined in [`web/src/app/globals.css`](../web/src/app/globals.css).
No component picks a colour, a radius or a duration of its own. If you find
yourself writing a hex value in a component, the answer is a token.

---

## The two themes, and how they work

The theme is an attribute on `<html>`:

```html
<html data-theme="light">   <!-- or "dark" -->
```

Tailwind v4 generates its colour utilities from `var(--color-*)`. So switching
theme means **redefining those same variables** under
`:root[data-theme="dark"]`. That is why `text-ink-500` and `bg-brand-50`, which
were written long before dark mode existed, flip correctly without being
touched: the utility did not change, the variable underneath it did.

`:root[data-theme]` has higher specificity than the `:root` that Tailwind's
`@theme` emits, so the override always wins.

A `dark:` variant exists for the rare rule that cannot be expressed as a token:

```css
@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));
```

Reach for a token first. `dark:` is an escape hatch, and a component full of
`dark:` classes is a component that should have been using semantic tokens.

### Ramps that flip, and families that do not

Three families deliberately **do not** flip, because their role is fixed rather
than relative to the background:

| Family | Why it is fixed |
|---|---|
| `chrome-*` | The sidebar, the mobile bar, drawers, the sign-in panel. Navy in both themes, so SASA is recognisable either way. It sits a little darker than the dark canvas so the sidebar still separates from the page. |
| `primary`, `accent-solid`, `{status}-solid` | Solid fills that carry white text. They must stay dark enough for that text in **both** themes, so they are tuned per theme rather than taken from a ramp. |
| `chrome-accent` | A light teal for teal marks on navy. `brand-300` flips too dark for that job. |

Everything else — `ink-*`, `brand-*`, the four status ramps, the surfaces —
flips. In the dark theme the ramps are **inverted**: `ink-900` becomes the
brightest text and `ink-50` the darkest fill. That inversion is the whole
reason the existing pages needed almost no edits.

### Choosing between a ramp and a solid token

This is the decision that goes wrong most often.

- Filling a shape that will carry **white text**? Use `bg-primary`,
  `bg-accent-solid`, `bg-danger-solid`. Never `bg-brand-700`.
- Tinting a **chip** with coloured text on it? Use `bg-danger-50` +
  `text-danger-700`. The pair flips together and stays legible.
- Drawing a **navigation surface**? Use `bg-chrome` / `text-chrome-fg`.
- Drawing an **indicator** — a 2px active bar, a dot? Use the `-500` step,
  which stays visible on both surfaces.

`bg-brand-900` does not exist as chrome any more. If you need a dark navy
panel, that is `chrome`.

---

## Colour is verified, not eyeballed

```bash
cd web && node tools/contrast.mjs
```

It reads `globals.css`, resolves both themes, and checks every text/background
pairing the app actually uses against WCAG AA. It exits non-zero on a failure,
so it belongs in CI beside the unit tests. **Run it after touching any colour.**

`ink-400` is worth knowing about: it was originally specified as decorative and
kept being used for dates and pager labels, so it is now a full AA text tone —
the quietest one, not a different class of thing.

The rendered pages are checked too, which catches what a palette check cannot —
a token that flipped in one place but not in the element sitting on top of it:

```bash
node tools/qa.mjs <account> <desktop|mobile> <light|dark>
```

It walks every visible text run, compares it against the background it is
actually painted on, and also reports horizontal overflow and console errors.

---

## Charts

Chart colour is the **one** thing that cannot be a CSS variable: Recharts writes
`stroke` and `fill` as SVG presentation attributes, and those do not resolve
`var()`. So charts read their colours from
[`web/src/lib/chartTheme.ts`](../web/src/lib/chartTheme.ts) through
`useChartTheme()`, which returns real hex per theme.

Chart *furniture* — grid lines, axis labels, tooltips, legend text — is done the
normal way, in `globals.css`. Recharts paints legend labels in the series colour
by default; SASA overrides that, because **text wears a text token, never a
series colour**. The swatch beside the label already carries identity.

The series palette is not the brand ramp. SASA's petrol teal is deliberately
desaturated so it can carry large areas of interface, and a colour that quiet
reads as grey when it is two pixels wide — so the chart palette opens with a
chromatic teal from the same family.

Both columns are *selected*, not derived from one another. Slot order is
load-bearing: it is what keeps neighbouring series apart for colour-blind
readers. **Assign slots in order; never cycle them.** A ninth series folds into
"Other" or becomes small multiples.

After changing a chart colour, re-run the validator:

```bash
node scripts/validate_palette.js "<hex,…>" --mode dark --surface "#19293e"
```

Three of the light steps (green, yellow, magenta) sit below 3:1 on white. That
is permitted only with relief, which SASA provides: **a legend is always present
for two or more series**, and the donut labels its slices. If you remove a
legend, you have broken that contract.

`tests/chart-theme.test.tsx` guards the parts a stylesheet cannot.

---

## The scanning ring

SASA has one signature loading mark: a ring with two halves sweeping round it,
so the motion reads as *looking through something* rather than *waiting*.

```tsx
<Scanner label="Searching" size={40} on="surface" />
```

Use it where the user is waiting on **work**: a search running, a file being
read, a report being built, the app opening.

Do **not** use it where the wait has a shape already. A list or a card gets a
`TableSkeleton` or `CardSkeleton`, because a skeleton says what is coming and a
spinner does not. Inline waits inside a button keep the small `Loader2` spinner —
a ring in a button is wrong.

`on` tells the ring what is behind it, because the groove is drawn in the
background colour: `surface` (default), `canvas`, or `chrome`.

Under `prefers-reduced-motion` the ring does not stop — a frozen ring reads as
broken — it turns very slowly instead.

`.sasa-scanline` is the companion: a sweep travelling down a panel that is being
searched or processed.

---

## Layout patterns worth reusing

**`.sasa-scroll-x`** — a horizontally scrollable region that says so. Two
gradients pinned with `local` scroll away with the content; two pinned with
`scroll` stay put, so a shadow appears on an edge only while there is more table
past it. No JavaScript, no resize observer, correct on first paint. Every
scrollable table uses it.

**`min-w-0` on grid and flex children** — a grid child defaults to
`min-width: auto`, so one long unbroken word widens the whole column past the
phone it is being read on. `.sasa-card` sets it; a `div` wrapping cards inside a
grid needs it explicitly. This is the single most common cause of mobile
horizontal overflow, and `tools/qa.mjs` catches it.

**KPI cards bottom-align their number.** The grid stretches cards to the tallest
in the row, so `mt-auto` on the figure puts every number in a row on one line
instead of each floating at the top of its own card above a pool of empty space.

---

## Theme switching

[`web/src/lib/theme.ts`](../web/src/lib/theme.ts) holds it. The rules:

1. **The attribute on `<html>` is the only source of truth.** Nothing keeps a
   copy in React state — a second copy is a second thing that can be wrong.
   Components subscribe to the attribute via `useTheme()`.
2. **The choice is saved, not the resolved theme.** `"light" | "dark" | "system"`.
   Saving `"dark"` when someone picked "system" would freeze them into whichever
   theme they happened to be in at that moment.
3. **An inline script in `<head>` applies it before the first paint.** This is
   the pattern Next documents in
   `node_modules/next/dist/docs/01-app/02-guides/preventing-flash-before-hydration.md`.
   Deferring to `useEffect` would paint light and then snap to dark.
   `suppressHydrationWarning` on `<html>` covers the attribute the server cannot
   know.
4. **The top-bar button flips what is on screen, and nothing more.** A three-way
   cycle there reads as broken: someone following a light device presses it
   expecting dark and gets explicit light — the same screen. "Follow my device"
   lives in `ThemeChoiceGroup` on the account page, where there is room to say
   what it means.

The theme also drives `color-scheme` (so native date pickers and scrollbars
follow) and the `theme-color` meta (so a phone's address bar matches).

Covered by `tests/theme.test.tsx` and `e2e/09-theme.spec.ts`, including a test
that asserts the theme is set while `document.readyState` is still `loading` —
which is what proves there is no flash.

---

## Things that are not negotiable

- **Status is never colour alone.** Every badge carries a word, and usually an
  icon. It has to survive a colour-blind reader and a black-and-white printout.
- **No tiny text.** 15px body, 16px inputs on mobile (below that, iOS zooms in —
  a real field problem). The eyebrow at 11px is the floor, and it is uppercase
  and letterspaced to stay readable.
- **44px minimum touch targets** on anything a field officer taps with a thumb.
- **Print is a first-class output.** A report goes out on paper in one form
  regardless of the theme the person printing it was using, so `@media print`
  forces the light tokens.
- **Never tell a user their work is saved when it is only on the device.** The
  sync engine returns `saved: "server" | "device"` and the UI must say which.
  This is a design rule as much as a data one.
