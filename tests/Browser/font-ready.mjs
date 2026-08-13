// The geometry harnesses' font assertion (prompt 233) — beside `counter-session.mjs`, for the same reason
// 226 put the login preamble there: a check every one of them needs, written once.
//
// **Why it exists.** The snapshot harnesses render `file://` pages, where the built CSS's root-relative
// `url(/build/assets/inter-*.woff2)` cannot resolve. Every Inter face failed and the browser silently fell
// back to whatever the runner's OS had. Measured on the same commit: a probe string was **322px** on the
// snapshot and **339px** on the live page — 5% narrower — so the same harness passed on a Mac (Helvetica-ish
// fallback) and failed on Linux (DejaVu-ish, ~13px taller over a form). **Neither machine ever measured the
// font the app renders.**
//
// **`await document.fonts.ready` is NOT the check.** It resolves when the loading PROCESS finishes, whether
// the faces succeeded or not — on the broken snapshot `document.fonts.status` was already `"loaded"` while
// the latin face sat in `error`. The real question is whether the browser can use the family, which is what
// `document.fonts.check()` answers.

/**
 * Fail loudly unless the page is measuring in the app's own font.
 *
 * @param {import('playwright').Page} page
 * @param {string} label      where this run is (state/size/theme), for the message
 * @param {(m: string) => void} fail   the harness's own failure recorder
 * @param {string} family     the family every geometry number depends on
 * @returns {Promise<boolean>} true when the real font is in use
 */
export async function assertRealFont(page, label, fail, family = 'Inter') {
    const state = await page.evaluate(async (family) => {
        // Kick the lazy faces: `document.fonts.check()` reports on faces the page has actually asked for, and
        // a page whose text has not been laid out yet may not have asked. Reading a metric first is what makes
        // the answer meaningful.
        document.body.getBoundingClientRect();
        await document.fonts.ready;

        const faces = [...document.fonts].filter((f) => f.family.replace(/["']/g, '') === family);

        return {
            usable: document.fonts.check(`16px ${family}`),
            errored: faces.filter((f) => f.status === 'error').length,
            loaded: faces.filter((f) => f.status === 'loaded').length,
            declared: faces.length,
        };
    }, family);

    if (! state.declared) {
        fail(`${label}: no ${family} face is declared at all — this page is not loading the app's stylesheet`);

        return false;
    }

    if (! state.usable || state.errored > 0) {
        fail([
            `${label}: MEASURING IN A FALLBACK FONT — ${family} is not usable`,
            `(${state.loaded}/${state.declared} faces loaded, ${state.errored} in error).`,
            'Every text-driven number from this run is the runner\'s OS font, not the app\'s.',
            'A file:// snapshot must carry resolvable font URLs — see Concerns\\InlinesBuiltCss.',
        ].join(' '));

        return false;
    }

    return true;
}
