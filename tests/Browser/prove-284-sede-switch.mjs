// Prompt 284 — the panel's sede switcher keeps you on the page you were on (search included); a batch the new sede
// can't see lands on the Lotes list; a custom page stays put. Drives the REAL app as the owner.
import { chromium } from 'playwright';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/284';
const [w, h] = (process.env.VIEWPORT ?? '1440x900').split('x').map(Number);
const tag = `${w}x${h}`;
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: w, height: h } });
check('sign in', await signIn(page));

const switcher = 'select[aria-label="Sede activa"], select[aria-label="Active location"]';
async function switchTo(label) {
    const value = await page.$eval(switcher, (s, l) => [...s.options].find((o) => o.text.trim() === l)?.value ?? null, label);
    if (await page.$eval(switcher, (s) => s.value) === value) {
        return; // already active: choosing it again fires no change
    }
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.selectOption(switcher, value)]);
    await page.waitForTimeout(500);
}
const path = () => { const u = new URL(page.url()); return u.pathname + u.search; };
const optionLabels = await page.$eval(switcher, (s) => [...s.options].map((o) => o.text.trim()));
const all = optionLabels[0];                         // "Todas las sedes" / "All locations"
const centro = optionLabels.find((l) => l.startsWith('Central'));
const norte = optionLabels.find((l) => l.startsWith('North'));

// 1. Lotes list with a search typed in.
await switchTo(centro);
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const search = page.locator('.fi-ta-search-field input, input[type="search"]').first();
await search.fill('B-');
await page.waitForTimeout(1500);
const searched = path();
check('search reflected in the URL', searched.includes('search'), searched);
await switchTo(all);
check('rollup: still on Lotes with the search', path() === searched, path());
check('rollup: search box keeps its text', (await search.inputValue()) === 'B-');
await page.screenshot({ path: `${OUT}/${tag}-lotes-rollup.png`, fullPage: true });
await switchTo(norte);
check('Sede Norte: still on Lotes with the search', path() === searched, path());
await page.screenshot({ path: `${OUT}/${tag}-lotes-norte.png`, fullPage: true });

// 2. A Centro batch open for editing.
await switchTo(centro);
await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
const editHref = await page.$eval('a[href*="/batches/"][href$="/edit"]', (a) => a.getAttribute('href'));
await page.goto(editHref, { waitUntil: 'networkidle' });
const editPath = path();
await switchTo(all);
check('rollup: stays on the batch', path() === editPath, path());
await switchTo(norte);
check('Norte: lands on the Lotes list, not a 404', path() === '/batches', path());
await page.screenshot({ path: `${OUT}/${tag}-batch-to-norte.png` });

// 3. Custom pages.
for (const p of ['/documentos/registro-dispensacion', '/rat']) {
    await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle' });
    const before = path();
    await switchTo(centro);
    check(`${p}: stays put`, path() === before, path());
}
await page.screenshot({ path: `${OUT}/${tag}-rat.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
