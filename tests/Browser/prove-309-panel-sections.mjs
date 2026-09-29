// Prompt 309 — the owner gives STAFF the panel on *Roles y permisos*; staff then see only the home, the counter link and
// the help. The owner switches on *Ver historial de cajas*: *Cajas* appears for staff, and nothing else. Owner and manager
// keep their whole panel. At the counter, staff still open the till and look a member up at the door. Throwaway database.
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/309';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const browser = await chromium.launch();
const settle = async (page) => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(600); };
const nav = async (page) => (await page.locator('.fi-sidebar-item-label').allTextContents()).map((t) => t.trim()).filter(Boolean);

const owner = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('owner signs in', await signIn(owner, { account: 'owner' }));
const toggle = async (permission) => {
    await owner.goto(`${BASE}/roles-y-permisos`, { waitUntil: 'networkidle' });
    await owner.locator(`[data-toggle="STAFF:${permission}"]`).click(); await settle(owner);
};
const ownerNav = await nav(owner);
check('owner still sees every section', ['Socios', 'Solicitudes', 'Cajas', 'Documentos generados', 'Seguridad'].every((l) => ownerNav.includes(l)), `${ownerNav.length} items`);

await toggle('panel.access');
await owner.goto(`${BASE}/roles-y-permisos`, { waitUntil: 'networkidle' });
const group = await owner.getByText('Panel de administración').first().isVisible();
check('the roles page groups the sections under Panel de administración', group && await owner.getByText('Ver historial de cajas').first().isVisible());
await owner.screenshot({ path: `${OUT}/roles.png`, fullPage: true });

const staff = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('staff sign in', await signIn(staff, { account: 'staff' }));
await staff.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const bare = await nav(staff);
check('staff with only the panel see the home, the counter link and the help', JSON.stringify(bare) === JSON.stringify(['Panel', 'Mostrador', 'Manual', 'Glosario']), JSON.stringify(bare));
await staff.screenshot({ path: `${OUT}/staff-panel-only.png` });
check('staff are refused Cajas by URL', (await staff.goto(`${BASE}/till-sessions`)).status() === 403);

await toggle('panel.tills');
await staff.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const withTills = await nav(staff);
check('switching on Ver historial de cajas shows Cajas, and nothing else', JSON.stringify(withTills) === JSON.stringify(['Panel', 'Mostrador', 'Manual', 'Glosario', 'Cajas']), JSON.stringify(withTills));
await staff.screenshot({ path: `${OUT}/staff-with-tills.png` });

const manager = await browser.newPage({ viewport: { width: 1440, height: 900 } });
check('manager signs in', await signIn(manager, { account: 'manager' }));
await manager.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const managerNav = await nav(manager);
check('manager still sees the five sections', ['Socios', 'Solicitudes', 'Cajas', 'Documentos generados', 'Seguridad'].every((l) => managerNav.includes(l)), `${managerNav.length} items`);

// The counter, as staff, with no panel section switched on for them except the till history.
const counter = await browser.newPage({ viewport: { width: 1180, height: 820 } });
await signInToCounter(counter, '/counter/checkin', { account: 'staff' });
await counter.goto(`${BASE}/counter/checkin`, { waitUntil: 'networkidle' });
if (counter.url().includes('/counter/till')) {
    await counter.fill('input[wire\\:model="floatInput"]', '50');
    await counter.click('form[wire\\:submit="open"] button[type="submit"]'); await settle(counter);
    check('staff open the till at the counter', ! counter.url().includes('/counter/till') || await counter.locator('[wire\\:submit="open"]').count() === 0);
    await counter.goto(`${BASE}/counter/checkin`, { waitUntil: 'networkidle' });
}
await counter.fill('#member-lookup', 'M-00027'); await counter.press('#member-lookup', 'Enter'); await settle(counter);
check('staff look a member up at the door', (await counter.locator('[data-member-lookup-result]').count()) > 0 || /M-00027/.test(await counter.content()));
await counter.screenshot({ path: `${OUT}/counter-door.png` });

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
