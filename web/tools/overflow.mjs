import { chromium } from '@playwright/test';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
await page.goto('http://localhost:3010/sign-in', { waitUntil: 'domcontentloaded' });
await page.fill('#email', 'project.admin@sasa.test');
await page.fill('#password', 'password');
await page.click('button[type=submit]');
await page.waitForTimeout(3000);
await page.goto('http://localhost:3010' + (process.argv[2] || '/grievances'), { waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
const offenders = await page.evaluate(() => {
  const docWidth = document.documentElement.clientWidth;
  const out = [];
  document.querySelectorAll('*').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.right > docWidth + 1 && r.width > 20) {
      out.push({
        tag: el.tagName,
        cls: (el.className && String(el.className)).slice(0, 110),
        right: Math.round(r.right), width: Math.round(r.width),
        text: (el.textContent||'').trim().slice(0,40),
      });
    }
  });
  return out.slice(0, 12);
});
console.log('docWidth', await page.evaluate(()=>document.documentElement.clientWidth));
console.log(JSON.stringify(offenders, null, 1));
await browser.close();
