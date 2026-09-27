import { chromium } from '@playwright/test';

const [,, path, out, width='1440', height='960', full='true'] = process.argv;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: +width, height: +height }, deviceScaleFactor: 2 });
page.on('pageerror', e => console.log('PAGE ERROR:', String(e).slice(0,300)));
page.on('console', m => { if (m.type()==='error') console.log('CONSOLE:', m.text().slice(0,200)); });

await page.goto('http://localhost:3010/sign-in', { waitUntil: 'domcontentloaded' });
await page.fill('#email', process.env.SASA_USER || 'grievance@sasa.test');
await page.fill('#password', 'password');
await Promise.all([
  page.waitForURL('**/', { timeout: 30000 }).catch(()=>{}),
  page.click('button[type=submit]'),
]);
await page.waitForTimeout(2500);
if (path !== '/') { await page.goto('http://localhost:3010'+path, { waitUntil: 'networkidle', timeout: 45000 }).catch(e=>console.log('nav', e.message)); }
await page.waitForTimeout(2200);
await page.screenshot({ path: out, fullPage: full === 'true' });
console.log('saved', out, '| url:', page.url());
await browser.close();
