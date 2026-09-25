// Prompt 242 — post-bump visual check of the Filament panel (5.7→5.8): the pages a minor is most likely to
// move, light and dark. Not a matrix sweep — the suite (1888) covers markup; this is the human's eye on it.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';
const OUT = 'storage/app/screenshots/242';
mkdirSync(OUT, { recursive: true });
const PAGES = [
  ['login', '/login', false],
  ['dashboard', '/', true],
  ['seguridad', '/seguridad', true],
  ['users', '/users', true],
  ['locations', '/locations', true],
  ['batch-create', '/batches/create', true],
];
const browser = await chromium.launch();
let ok = true;
for (const theme of ['light', 'dark']) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, colorScheme: theme });
  const page = await ctx.newPage();
  let signedIn = false;
  for (const [name, path, needsAuth] of PAGES) {
    if (needsAuth && ! signedIn) { signedIn = await signIn(page); if (! signedIn) { console.error(`[${theme}] sign-in failed`); ok = false; break; } }
    const resp = await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch(() => null);
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/${name}-${theme}.png` });
    const status = resp?.status() ?? 'ERR';
    const bad = status >= 500;
    if (bad) ok = false;
    console.log(`${bad ? 'FAIL' : 'ok  '} ${theme} ${name} → HTTP ${status}`);
  }
  await ctx.close();
}
await browser.close();
process.exitCode = ok ? 0 : 1;
