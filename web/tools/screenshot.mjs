import { chromium } from '@playwright/test';

const [,, url, out, width = '1440', height = '960', full = 'true'] = process.argv;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: +width, height: +height }, deviceScaleFactor: 2 });
page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE ERROR:', m.text().slice(0,300)); });
page.on('pageerror', e => console.log('PAGE ERROR:', String(e).slice(0,300)));
await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 }).catch(e => console.log('nav:', e.message));
await page.waitForTimeout(1200);
await page.screenshot({ path: out, fullPage: full === 'true' });
console.log('saved', out);
await browser.close();
