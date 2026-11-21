/*
 * Renders the SASA mark into every image the app config points at:
 * the iOS icon, the Android adaptive layers and the splash mark.
 *
 *   npm run icons
 */
import sharp from "sharp";
import { mkdirSync } from "node:fs";

const TOP =
  "M670 215H1080L960 362H665C632 362 604 378 572 405C541 432 512 462 490 492C474 462 468 436 468 410C468 302 560 215 670 215Z";
const FOLD =
  "M654 390V483L912 572V636C912 643 906 648 898 648H840L620 597C571 585 535 561 507 517C530 489 560 458 588 434C612 413 632 398 654 390Z";
const BOTTOM =
  "M680 388H713L930 432C1018 450 1080 528 1080 612C1080 718 994 806 895 806H455L566 662H905C925 662 936 651 936 636V553L680 464Z";

const mark = (ink, fold) =>
  `<path d="${TOP}" fill="${ink}"/><path d="${FOLD}" fill="${fold}"/><path d="${BOTTOM}" fill="${ink}"/>`;

/** The mark centred on a square canvas, at `scale` of its width. */
function canvas(size, { background, ink, fold, scale }) {
  const box = 640 / scale;
  const offset = (box - 640) / 2;
  const bg = background ? `<rect x="${447 - offset}" y="${190 - offset}" width="${box}" height="${box}" fill="${background}"/>` : "";
  return Buffer.from(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="${447 - offset} ${190 - offset} ${box} ${box}">${bg}${mark(ink, fold)}</svg>`,
  );
}

const out = "assets/images";
mkdirSync(out, { recursive: true });

const jobs = [
  // iOS: opaque, no transparency allowed.
  ["icon.png", 1024, { background: "#ffffff", ink: "#15243a", fold: "#47709b", scale: 0.6 }],
  // Android adaptive icon: the launcher crops to a circle or squircle, so the
  // mark sits well inside the 66% safe zone.
  ["android-icon-foreground.png", 1024, { ink: "#15243a", fold: "#47709b", scale: 0.42 }],
  ["android-icon-background.png", 1024, { background: "#ffffff", ink: "#ffffff", fold: "#ffffff", scale: 0.01 }],
  ["android-icon-monochrome.png", 1024, { ink: "#000000", fold: "#000000", scale: 0.42 }],
  // Splash: the reversed mark, shown on the brand navy.
  ["splash-icon.png", 1024, { ink: "#ffffff", fold: "#7aa5d2", scale: 1 }],
  ["favicon.png", 96, { background: "#ffffff", ink: "#15243a", fold: "#47709b", scale: 0.7 }],
];

for (const [name, size, options] of jobs) {
  await sharp(canvas(size, options)).png().toFile(`${out}/${name}`);
  console.log(`${out}/${name}`);
}
