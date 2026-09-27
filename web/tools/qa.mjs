import { chromium } from '@playwright/test';
import fs from 'node:fs';

const pages = [
  ['/', 'dashboard'],
  ['/stakeholders', 'stakeholders'],
  ['/stakeholders/1', 'stakeholder-detail'],
  ['/stakeholders/new', 'stakeholder-new'],
  ['/engagements', 'engagements'],
  ['/engagements/plans', 'engagement-plans'],
  ['/engagements/calendar', 'engagement-calendar'],
  ['/engagements/log', 'engagement-log'],
  ['/engagements/1', 'engagement-detail'],
  ['/concerns', 'concerns'],
  ['/commitments', 'commitments'],
  ['/commitments/1', 'commitment-detail'],
  ['/grievances', 'grievances'],
  ['/grievances/new', 'grievance-new'],
  ['/analytics', 'analytics'],
  ['/reports', 'reports'],
  ['/sync', 'sync'],
  ['/configuration', 'configuration'],
  ['/configuration/import', 'import'],
  ['/audit', 'audit'],
  ['/ai', 'ai'],
  ['/people', 'people'],
  ['/notifications', 'notifications'],
  ['/account', 'account'],
  ['/projects', 'projects'],
];

const [,, user = 'project.admin@sasa.test', mode = 'desktop', theme = 'light'] = process.argv;
const viewport = mode === 'mobile' ? { width: 390, height: 844 } : { width: 1440, height: 1000 };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport, deviceScaleFactor: 2, isMobile: mode === 'mobile', hasTouch: mode === 'mobile' });

const problems = [];
page.on('pageerror', e => problems.push(`PAGE ERROR ${page.url()}: ${String(e).slice(0,200)}`));
page.on('console', m => { if (m.type() === 'error' && !m.text().includes('403')) problems.push(`CONSOLE ${page.url()}: ${m.text().slice(0,200)}`); });

// Pin the theme before anything paints, the same way the app's own init
// script does, so the whole sweep runs in the theme being inspected.
await page.addInitScript((t) => {
  try { localStorage.setItem('sasa-theme', t); } catch {}
  // `next start` injects Next's own dev-tools overlay, which floats over the
  // bottom-left of every page. It is not part of SASA and must not appear in
  // a visual review of it.
  const hide = document.createElement('style');
  hide.textContent = 'nextjs-portal{display:none!important}';
  document.addEventListener('DOMContentLoaded', () => document.head.appendChild(hide));
}, theme);

await page.goto('http://localhost:3010/sign-in', { waitUntil: 'domcontentloaded' });
await page.fill('#email', user);
await page.fill('#password', 'password');
await page.click('button[type=submit]');
await page.waitForTimeout(3000);

const shots = `/tmp/sasa-shots/${theme}-${mode}`;
fs.mkdirSync(shots, { recursive: true });

const applied = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
if (applied !== theme) problems.push(`THEME not applied: wanted ${theme}, got ${applied}`);

for (const [path, name] of pages) {
  try {
    await page.goto('http://localhost:3010' + path, { waitUntil: 'domcontentloaded', timeout: 40000 });
    await page.waitForTimeout(2200);
    await page.screenshot({ path: `${shots}/${name}.png`, fullPage: false });
    // Check for horizontal overflow, which is the classic mobile failure.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (overflow > 2) problems.push(`OVERFLOW ${path}: ${overflow}px`);

    // Measure what actually rendered, rather than trusting the palette: walk
    // the visible text and compare each run against the background it is
    // painted on. This is what catches a token that flipped in one place but
    // not in the element sitting on top of it.
    const lowContrast = await page.evaluate(() => {
      const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
      // Any CSS colour — rgb(), color(srgb …), oklab(), a keyword — comes back
      // as four 0-255 bytes, because the canvas does the conversion the same
      // way the compositor does.
      const probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
      const bytes = (value) => {
        try {
          probe.clearRect(0, 0, 1, 1);
          probe.fillStyle = '#000';
          probe.fillStyle = value;
          probe.fillRect(0, 0, 1, 1);
          return probe.getImageData(0, 0, 1, 1).data;
        } catch { return null; }
      };
      const parse = (s) => { const b = bytes(s); return b ? [b[0], b[1], b[2]] : null; };
      const alpha = (s) => { const b = bytes(s); return b ? b[3] / 255 : 1; };
      const lum = ([r, g, b]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
      const bgOf = (el) => {
        for (let n = el; n; n = n.parentElement) {
          const s = getComputedStyle(n);
          if (alpha(s.backgroundColor) > 0.85) return parse(s.backgroundColor);
        }
        return parse(getComputedStyle(document.body).backgroundColor);
      };
      const out = [];
      for (const el of document.querySelectorAll('body *')) {
        if (el.children.length > 0) continue;
        const text = (el.textContent || '').trim();
        if (text.length < 2) continue;
        const s = getComputedStyle(el);
        if (s.visibility === 'hidden' || s.display === 'none' || Number(s.opacity) < 0.6) continue;
        const r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) continue;
        const fg = parse(s.color); const bg = bgOf(el);
        if (!fg || !bg) continue;
        const [hi, lo] = [lum(fg), lum(bg)].sort((a, b) => b - a);
        const ratio = (hi + 0.05) / (lo + 0.05);
        const px = parseFloat(s.fontSize);
        const large = px >= 24 || (px >= 18.66 && Number(s.fontWeight) >= 700);
        const min = large ? 3 : 4.5;
        if (ratio < min - 0.05) out.push(`${ratio.toFixed(2)}<${min} "${text.slice(0, 40)}" ${s.color} on rgb(${bg.join(',')}) ${px}px`);
      }
      return [...new Set(out)];
    });
    for (const issue of lowContrast.slice(0, 6)) problems.push(`CONTRAST ${path}: ${issue}`);
    process.stdout.write(`ok ${path}\n`);
  } catch (e) {
    problems.push(`NAV ${path}: ${e.message.slice(0,150)}`);
  }
}

console.log('\n--- problems ---');
console.log(problems.length ? [...new Set(problems)].join('\n') : 'none');
await browser.close();
