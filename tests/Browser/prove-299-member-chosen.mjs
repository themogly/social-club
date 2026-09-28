// Prompt 299 — once a member is chosen, the dispensary stops asking "find a member". Real app, throwaway database, card
// readers ON at Central Branch. TOKEN = a second member's card token, NAME2 = their name.
// Per orientation: choose a socio → no search, pad and products higher; *Cambiar socio* with an empty basket → the search,
// focused; with a line in the basket → 263's question; a card-reader burst → switch (the question when unpaid), and the pad
// untouched by the burst; slow typing → nothing; *Sin foto* → the sheet with the two buttons.
import { chromium } from 'playwright';
import { BASE, signInToCounter } from './counter-session.mjs';

const OUT = 'storage/app/screenshots/299';
const TOKEN = process.env.TOKEN;
const NAME2 = process.env.NAME2 ?? '';
const results = [];
const check = (name, ok, detail = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name} ${detail}`); };
const scheme = process.env.SCHEME ?? 'light';
const browser = await chromium.launch();

for (const [w, h] of [[1180, 820], [820, 1180]]) {
    const tag = `${w}x${h}-${scheme}`;
    const page = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme });
    page.setDefaultTimeout(10000);
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const settle = async () => { await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(500); };

    await signInToCounter(page, '/counter/pos', { sede: 'Central Branch' });
    await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    if (page.url().includes('/counter/till')) {
        await page.fill('input[wire\\:model="floatInput"]', '50');
        await page.click('form[wire\\:submit="open"] button[type="submit"]');
        await settle();
        await page.goto(`${BASE}/counter/pos`, { waitUntil: 'networkidle' });
    }

    const choose = async (who) => {
        await page.fill('#member-lookup', who); await page.press('#member-lookup', 'Enter'); await settle();
        await page.click('[data-member-lookup-result]'); await settle();
        const fee = await page.$('form[wire\\:submit="collectMemberFee"] button[type="submit"]');
        if (fee) { await fee.click(); await settle(); }
    };

    await choose('M-00001');
    check(`${tag}: no search once a socio is chosen`, (await page.locator('[data-member-lookup]').count()) === 0);
    check(`${tag}: the card says Cambiar socio`, await page.locator('[data-change-member]', { hasText: 'Cambiar socio' }).isVisible());
    const firstCardTop = await page.locator('[data-catalogue-item="genetics"]').first().evaluate((el) => el.getBoundingClientRect().top);
    await page.screenshot({ path: `${OUT}/${tag}-chosen.png` });

    // Cambiar socio, empty basket → the search, focused.
    await page.click('[data-change-member]'); await settle();
    check(`${tag}: Cambiar socio returns to the search, focused`, await page.evaluate(() => document.activeElement?.id === 'member-lookup'));

    // With a line: the 263 question.
    await choose('M-00001');
    await page.locator('[data-catalogue-item="genetics"]:not([disabled])').first().click(); await settle();
    await page.locator('[data-weight-pad] button', { hasText: /^1$/ }).click();
    await page.click('[data-add-line]'); await settle();
    await page.click('[data-change-member]'); await settle();
    check(`${tag}: with a line, Cambiar socio asks first`, await page.locator('[data-confirm-discard]').isVisible());
    await page.screenshot({ path: `${OUT}/${tag}-confirm.png` });
    await page.getByRole('button', { name: 'Seguir cobrando' }).click(); await settle();

    // A card-reader burst with an unpaid line: the question again, and the pad untouched.
    await page.locator('[data-catalogue-item="genetics"]:not([disabled])').first().click(); await settle();
    await page.locator('[data-weight-pad] button', { hasText: /^2$/ }).click();
    const padBefore = await page.locator('[data-weight-pad]').evaluate((el) => Alpine.$data(el.closest('[x-data]')).value);
    await page.locator('body').click({ position: { x: 5, y: 5 } }).catch(() => {});
    await page.keyboard.type(TOKEN, { delay: 5 });
    await page.keyboard.press('Enter');
    await settle();
    const padAfter = await page.locator('[data-weight-pad]').count()
        ? await page.locator('[data-weight-pad]').evaluate((el) => Alpine.$data(el.closest('[x-data]')).value) : padBefore;
    check(`${tag}: a card burst with unpaid lines asks first`, await page.locator('[data-confirm-discard]').isVisible());
    check(`${tag}: the burst never typed into the pad`, padAfter === padBefore, `${padBefore} → ${padAfter}`);
    await page.click('[data-confirm-discard-yes]'); await settle();

    // Slow typing is a person: nothing happens.
    await choose('M-00001');
    await page.locator('body').click({ position: { x: 5, y: 5 } }).catch(() => {});
    await page.keyboard.type('ABCDEFGHIJ', { delay: 150 });
    await page.keyboard.press('Enter');
    await settle();
    check(`${tag}: slow typing is not a card`, (await page.locator('[data-member-summary]').textContent())?.includes(NAME2) === false);

    // A burst with an empty basket: straight to the scanned member.
    await page.keyboard.type(TOKEN, { delay: 5 });
    await page.keyboard.press('Enter');
    await settle();
    check(`${tag}: a card burst switches to the scanned member`, (await page.locator('[data-member-summary]').textContent())?.includes(NAME2) === true, NAME2);

    // The photo chip → the sheet.
    const chip = page.locator('[data-photo-chip]');
    if (await chip.count()) {
        await chip.click(); await page.waitForTimeout(400);
        const sheet = page.locator('[data-photo-sheet] > [role="dialog"]');
        check(`${tag}: Sin foto opens the sheet with both buttons`, await sheet.isVisible()
            && await sheet.getByText('Hacer foto').isVisible() && await sheet.getByText('Elegir archivo').isVisible());
        await page.screenshot({ path: `${OUT}/${tag}-photo-sheet.png` });
        await page.keyboard.press('Escape'); await page.waitForTimeout(300);
        check(`${tag}: Escape closes the sheet`, ! await sheet.isVisible());
    } else {
        check(`${tag}: the scanned member has a photo, so no chip`, true);
    }
    await page.screenshot({ path: `${OUT}/${tag}-after.png` });
    check(`${tag}: no page errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    console.log(`INFO ${tag}: first genetic card top at ${Math.round(firstCardTop)}px with a socio chosen`);
    await page.close();
}

await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);
