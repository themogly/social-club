// Prompt 290 — what a headless browser CAN prove about the installable counter: the manifest loads (with credentials),
// the head carries it, no service worker registers, and "Instalar como app" stays hidden when the browser never offers
// installation. The install itself, the missing address bar and the scope are real-device checks (Shane).
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/290';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1180, height: 820 } });
check('sign in', await signInToCounter(page, '/counter/till'));
const manifest = await page.evaluate(async () => (await fetch('/counter.webmanifest', { credentials: 'include' })).json());
check('the manifest loads with credentials', manifest.id === '/counter' && manifest.scope === '/' && manifest.display === 'standalone', `${manifest.name} (${manifest.lang})`);
check('the page links it', await page.locator('link[rel="manifest"][href="/counter.webmanifest"]').count() === 1);
const sw = await page.evaluate(async () => ('serviceWorker' in navigator) ? (await navigator.serviceWorker.getRegistrations()).length : 0);
check('no service worker registered', sw === 0, `(${sw})`);
check('no install button without an install offer', ! await page.isVisible('[data-counter-install]'));
await page.screenshot({ path: `${OUT}/1180x820-topbar.png`, clip: { x: 0, y: 0, width: 1180, height: 90 } });
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
