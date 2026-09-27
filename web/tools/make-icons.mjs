/* Renders the SASA mark to the PNG sizes a PWA manifest needs. */
import { chromium } from "@playwright/test";
import fs from "node:fs";

const svg = fs.readFileSync("public/icon.svg", "utf8");

const browser = await chromium.launch();

for (const [size, name, padded] of [
  [192, "icon-192.png", false],
  [512, "icon-512.png", false],
  [512, "icon-maskable-512.png", true],
  [180, "apple-icon.png", false],
]) {
  const page = await browser.newPage({ viewport: { width: size, height: size } });

  // A maskable icon needs a safe zone: platforms crop it to their own shape.
  const scale = padded ? 0.72 : 1;

  await page.setContent(`
    <style>
      html,body{margin:0;padding:0;width:${size}px;height:${size}px;background:#0b2028;
        display:flex;align-items:center;justify-content:center}
      svg{width:${Math.round(size * scale)}px;height:${Math.round(size * scale)}px}
    </style>
    ${svg}
  `);

  await page.screenshot({ path: `public/${name}`, omitBackground: false });
  await page.close();
  console.log("wrote", name, size);
}

await browser.close();
