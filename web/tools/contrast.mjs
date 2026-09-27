/**
 * Checks every text/background pairing the SASA palette actually uses, in both
 * themes, against WCAG AA. Run it after touching a colour in globals.css:
 *
 *   node tools/contrast.mjs
 *
 * It exits non-zero on a failure, so it can go in CI beside the unit tests.
 */
import { readFileSync } from "node:fs";
const css = readFileSync(new URL("../src/app/globals.css", import.meta.url), "utf8");

function block(re){ const m = css.match(re); return m ? m[1] : ""; }
const themeBlock = block(/@theme\s*\{([\s\S]*?)\n\}/);
const darkBlock  = block(/:root\[data-theme="dark"\]\s*\{([\s\S]*?)\n\}/);
function vars(text){ const o={}; for (const m of text.matchAll(/(--[a-z0-9-]+):\s*(#[0-9a-fA-F]{6})/g)) o[m[1]]=m[2]; return o; }
const light = vars(themeBlock);
const dark  = { ...light, ...vars(darkBlock) };

const lin = c => { c/=255; return c<=0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055,2.4); };
const lum = h => { const n=parseInt(h.slice(1),16); return 0.2126*lin(n>>16&255)+0.7152*lin(n>>8&255)+0.0722*lin(n&255); };
const ratio = (a,b) => { const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p); return (x+0.05)/(y+0.05); };

// fg, bg, min, label
const CHECKS = [
  ["--color-ink-900","--color-surface",7,"heading on card"],
  ["--color-ink-800","--color-surface",4.5,"body on card"],
  ["--color-ink-700","--color-surface",4.5,"body-strong on card"],
  ["--color-ink-600","--color-surface",4.5,"secondary on card"],
  ["--color-ink-500","--color-surface",4.5,"muted on card"],
  ["--color-ink-500","--color-surface-sunken",4.5,"muted on sunken"],
  ["--color-ink-500","--color-canvas",4.5,"muted on canvas"],
  ["--color-ink-400","--color-surface",4.5,"quietest text on card"],
  ["--color-ink-400","--color-canvas",4.5,"quietest text on canvas"],
  ["--color-ink-400","--color-surface-sunken",4.5,"quietest text on sunken"],
  ["--color-ink-900","--color-canvas",7,"heading on canvas"],
  ["--color-brand-700","--color-brand-50",4.5,"brand chip text"],
  ["--color-brand-800","--color-brand-50",4.5,"brand chip text strong"],
  ["--color-brand-900","--color-brand-100",4.5,"brand chip text on 100"],
  ["--color-brand-600","--color-surface",4.5,"brand link on card"],
  ["--color-success-700","--color-success-50",4.5,"success chip"],
  ["--color-warning-700","--color-warning-50",4.5,"warning chip"],
  ["--color-danger-700","--color-danger-50",4.5,"danger chip"],
  ["--color-info-700","--color-info-50",4.5,"info chip"],
  ["--color-success-600","--color-success-100",4.5,"success chip alt"],
  ["--color-danger-600","--color-danger-100",4.5,"danger chip alt"],
  ["--color-warning-600","--color-warning-100",4.5,"warning chip alt"],
  ["--color-on-primary","--color-primary",4.5,"white on primary"],
  ["--color-on-primary","--color-primary-hover",4.5,"white on primary hover"],
  ["--color-on-primary","--color-accent-solid",4.5,"white on accent"],
  ["--color-on-primary","--color-accent-solid-hover",4.5,"white on accent hover"],
  ["--color-on-primary","--color-primary-active",4.4,"white on primary active"],
  ["--color-ink-500","--color-surface-raised",4.5,"muted on raised"],
  ["--color-ink-900","--color-surface-raised",7,"heading on raised"],
  ["--color-on-primary","--color-danger-solid",4.5,"white on danger"],
  ["--color-on-primary","--color-danger-solid-hover",4.5,"white on danger hover"],
  ["--color-on-primary","--color-success-solid",4.5,"white on success"],
  ["--color-on-primary","--color-warning-solid",4.5,"white on warning"],
  ["--color-on-primary","--color-info-solid",4.5,"white on info"],
  ["--color-chrome-fg","--color-chrome",7,"sidebar label"],
  ["--color-chrome-muted","--color-chrome",4.5,"sidebar muted"],
  ["--color-chrome-subtle","--color-chrome",3,"sidebar subtle (non-text)"],
  ["--color-chrome-fg","--color-chrome-deep",7,"sidebar label on deep"],
  ["--color-chrome-muted","--color-chrome-raised",4.5,"sidebar muted on raised"],
  ["--color-chrome-accent","--color-chrome",4.5,"teal text on chrome"],
  ["--color-chrome-accent","--color-chrome-deep",4.5,"teal text on deep chrome"],
  ["--color-accent-300","--color-chrome",4.5,"accent text on chrome"],
  ["--color-hairline","--color-surface",1.1,"hairline visible on card"],
  ["--color-surface","--color-canvas",1.05,"card lifts off canvas"],
  ["--color-chrome","--color-canvas",1.15,"chrome separates from canvas"],
  ["--color-brand-500","--color-surface",3,"focus ring on card"],
];

let fail = 0;
for (const [name, theme] of [["LIGHT", light], ["DARK", dark]]) {
  console.log(`\n===== ${name} =====`);
  for (const [f, b, min, label] of CHECKS) {
    if (!theme[f] || !theme[b]) { console.log(`  ?? missing ${f} or ${b}`); fail++; continue; }
    const r = ratio(theme[f], theme[b]);
    const ok = r >= min;
    if (!ok) fail++;
    console.log(`  ${ok ? "ok  " : "FAIL"} ${r.toFixed(2).padStart(6)} (min ${min})  ${label}  ${theme[f]} on ${theme[b]}`);
  }
}
console.log(`\n${fail === 0 ? "ALL PASS" : fail + " FAILURES"}`);
process.exit(fail === 0 ? 0 : 1);
