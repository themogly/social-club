// Prompt 375 — proof on the running app (a throwaway csc:seed-staging DB in English):
//   D. Reports → Losses' date line for Today and This week (the sede's days), and Discounts and give-aways split by kind;
//   P. the dashboard's per-person line;
//   E. the alert email (/dev/mail/alert-summary) with both losses alerts;
//   U. a receipt with one unit.
//   BASE_URL=http://127.0.0.1:8360 SEDE=<central id> node tests/Browser/prove-375-after-testing.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { BASE, signIn } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/375';
mkdirSync(OUT, { recursive: true });
const SEDE = process.env.SEDE;
const results = [];
const check = (label, pass, detail = '') => results.push([label, !!pass, detail]);
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
await signIn(page, { account: 'owner' });

// D. The date line, and the discounts by kind.
const madrid = (d) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
for (const [period, tag] of [['today', 'today'], ['week', 'week']]) {
  await page.goto(`${BASE}/informes/perdidas?period=${period}&scope=${SEDE}`, { waitUntil: 'networkidle' });
  const context = (await page.locator('.csc-rep-context').innerText()).trim();
  if (period === 'today') check('D: Today reads today in Madrid', context.endsWith(madrid(new Date())), context);
  else check('D: This week runs Monday to Sunday', /\d{2}\/\d{2}\/\d{4} – \d{2}\/\d{2}\/\d{4}$/.test(context), context);
  await page.screenshot({ path: `${OUT}/losses-header-${tag}.png`, clip: { x: 300, y: 60, width: 1140, height: 260 } });
}
const counter = page.locator('section:has(h2:text-is("Discounts and give-aways at the counter"))');
await counter.scrollIntoViewIfNeeded();
const counterText = await counter.innerText();
check('D: member discounts split by kind', /Local discount \(apart\)/.test(counterText) && !/Concession discount/.test(counterText), counterText.split('\n').filter((l) => /discount/i.test(l)).join(' | '));
await counter.screenshot({ path: `${OUT}/discounts-by-kind.png` });

// P. The dashboard's per-person line.
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
const people = page.locator('[data-alert="losses_people_above_threshold"]');
check('P: the per-person line', (await people.count()) === 1, (await people.innerText().catch(() => '')).trim());
await people.scrollIntoViewIfNeeded();
await page.screenshot({ path: `${OUT}/dashboard-people.png` });

// E. The alert email.
await page.goto(`${BASE}/dev/mail/alert-summary`, { waitUntil: 'networkidle' });
const mail = await page.locator('body').innerText();
check('E: the email carries both losses alerts, no name', /Losses yesterday: €46\.20 \(8\.2 %\)/.test(mail) && /1 person above the threshold \(16 % of what they took\)/.test(mail), mail.split('\n').filter((l) => /Loss|person/i.test(l)).join(' | '));
await page.screenshot({ path: `${OUT}/alert-email.png`, fullPage: true });

// U. A receipt with one unit.
await ctx.close();
const counterCtx = await browser.newContext({ viewport: { width: 600, height: 900 } });
const cpage = await counterCtx.newPage();
await signIn(cpage, { account: 'owner' });
await cpage.goto(`${BASE}${process.env.RECEIPT}`, { waitUntil: 'networkidle' });
const ticket = await cpage.locator('body').innerText();
check('U: one unit reads «1 unit»', /1 unit\b/.test(ticket) && !/1 units/.test(ticket), (ticket.match(/\d+ units?[^\n]*/) ?? [''])[0]);
await cpage.screenshot({ path: `${OUT}/receipt-one-unit.png`, fullPage: true });

await browser.close();
for (const [label, pass, detail] of results) console.log(`${pass ? 'PASS' : 'FAIL'}  ${label}${detail ? `  — ${detail}` : ''}`);
const failed = results.filter(([, pass]) => !pass).length;
console.log(`${results.length - failed}/${results.length} passed`);
process.exit(failed === 0 ? 0 : 1);
