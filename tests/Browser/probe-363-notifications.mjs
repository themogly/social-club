// Prompt 363 — try to reproduce `Collection::fromLivewire(): Argument #1 ($notification) must be of type array, int given`
// in a real browser on the panel. Records every Livewire update response that is not 200, with its body's first line.
//   BASE_URL=http://127.0.0.1:8360 node tests/Browser/probe-363-notifications.mjs
import { chromium } from 'playwright';
import { BASE, signIn, signInToCounter } from './counter-session.mjs';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 820 } });
const page = await ctx.newPage();
const bad = [];
page.on('response', async (r) => {
  if (r.url().includes('/livewire') && r.url().includes('/update') && r.status() !== 200) {
    bad.push(`${r.status()} ${(await r.text().catch(() => '')).slice(0, 160).replace(/\s+/g, ' ')}`);
  }
});
await signIn(page, { account: 'owner' });
const settle = (ms = 400) => page.waitForTimeout(ms);
let toasts = 0;

async function adjustToast() {
  await page.goto(`${BASE}/batches`, { waitUntil: 'networkidle' });
  await page.locator('.fi-ta-row .fi-ta-actions .fi-dropdown-trigger button').first().click();
  await page.locator('.fi-dropdown-panel .fi-dropdown-list-item:has(.fi-dropdown-list-item-label:text-is("Adjustment"))').first().click();
  await settle();
  await page.locator('input[id$="quantity"], input[wire\\:model$="quantity"]').first().fill('0.01').catch(() => {});
  await page.locator('textarea').first().fill('probe 363');
  await page.locator('.fi-modal-footer button[type="submit"]').first().click();
}

// 1 — a notification, then a second Livewire action on the same page before the toast closes.
for (let i = 0; i < 3; i++) {
  await adjustToast();
  if (await page.waitForSelector('.fi-no-notification', { timeout: 8000 }).catch(() => null)) toasts++;
  await page.locator('.fi-ta-row .fi-ta-actions .fi-dropdown-trigger button').first().click().catch(() => {});
  await page.locator('input[type="search"]').first().fill('Amn').catch(() => {});
  await settle(1500);
}
// 2 — close the toast while another request is in flight.
for (let i = 0; i < 3; i++) {
  await adjustToast();
  if (await page.waitForSelector('.fi-no-notification', { timeout: 8000 }).catch(() => null)) toasts++;
  const close = page.locator('.fi-no-notification button[aria-label], .fi-no-notification .fi-icon-btn').first();
  await Promise.all([page.locator('input[type="search"]').first().fill(`x${i}`).catch(() => {}), close.click().catch(() => {})]);
  await settle(1500);
}
console.log(`after 1–2: ${toasts} toasts seen`);
// 4 — from the counter to Administración right after a notice (already signed in: the PIN pad, then the panel).
await page.goto(`${BASE}/counter`, { waitUntil: 'networkidle' });
for (const d of '1234') await page.click(`[data-counter-surface] button:has-text("${d}")`).catch(() => {});
await page.click('[data-counter-surface-unlock]').catch(() => {});
await settle(1200);
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await settle(1000);
console.log(bad.length ? `NON-200 Livewire responses:\n${bad.join('\n')}` : 'no non-200 Livewire responses');
await browser.close();
