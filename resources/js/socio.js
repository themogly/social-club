// The member/applicant app's Alpine runtime (prompt 232).
//
// **Why this file exists at all.** The signature pad is an Alpine component, and until now Alpine only ever
// arrived on a page as part of LIVEWIRE's bundle. The counter routes load Livewire, so the pad worked there.
// The applicant's own form is plain Blade under `layouts/socio`, which loads a stylesheet and nothing else —
// so on the emailed invite route every `x-data`, `@mousedown` and `x-ref` in that component was dead markup.
//
// Measured live on `9478612`: `window.Alpine` undefined, the canvas at its 300×150 DEFAULT attributes stretched
// by CSS to 668×335, a drawn stroke leaving **0 ink pixels**, `data-drawn` never set, and *Guardar firma*
// leaving the hidden field empty. With `signature_on_application` at its default, `SubmitApplication` then
// refuses the submit — so **the emailed route could not be completed at all.**
//
// This is prompt 196's class one level up. 196 caught directives sitting OUTSIDE an `x-data` scope; these sit
// correctly inside one, so its guard passes. Nobody had checked that the RUNTIME ships. The failure is silent
// either way: Alpine does not warn about a page it was never loaded on.
//
// **Loaded per PAGE, not from the layout.** The application form is the only socio view with Alpine
// directives, and the rest of this layout is the member PWA — a phone-first app whose menu and wallet would
// otherwise carry ~15KB gzipped for a component they never render. What makes a page-level load safe rather
// than a thing to remember is `AlpineShipsWhereItIsUsedTest`, which fails naming any socio view that
// introduces a directive without this entry.
//
// NOT loaded on counter routes: Livewire starts its own bundled Alpine there, and two Alpines on one page is
// a documented breakage.
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();
