# Real-device checks — the club's own tablet

What a laptop and a throttled Chrome cannot prove: how the counter FEELS on the tablet the club actually uses, in the
club, on its Wi-Fi. Each entry is one short visit, reported back in a sentence or two — a screen recording is ideal.
Add the answer under the entry (date, device, who), and turn anything that looks wrong into a numbered prompt.

---

## 289 — a registered tablet opens on the PIN pad, even the next day

**Who:** Ben or Shane (a manager or the owner registers it), on each club tablet.

**Do:** on the counter, tap **Este dispositivo** in the top bar and register the tablet for its sede (your PIN once
more). Close Chrome and leave the tablet overnight. Next day, open the counter again.

**Report:**

- Did it open straight on the **PIN pad**, with no email and password, and did a staff PIN get in?
- After the idle lock, did the basket survive, and did the PIN pad come back rather than a login?
- Since post-296 fix 3: the first time a PIN session opens the **panel** (Administración) it asks for that person's
  password (and MFA code) — once a shift. Was that clear, and did *Volver al mostrador* take you back?
- (Optional, once) Revoke the tablet in the panel (*Sistema → Mostradores registrados*): did the tablet's next tap
  land on the login?

**Answer:** _(pending)_

---

## 290 — the counter as an installed app

**Who:** Shane, on each counter tablet, in Chrome.

**Do:** open the counter and use the **install** button (or Chrome's menu → *Instalar aplicación*), then open the
counter from the new icon from then on.

**Report:**

- Was **install offered** at all? (There is deliberately no service worker; say if Chrome refused.)
- Opened from the icon, is there **no address bar**, in portrait and in landscape?
- Do the login, the lockdown screen and the panel **stay inside the app** (no jump out to a Chrome tab)?
- Does a registered tablet still reopen on the **PIN pad** from the icon?
- Does the **member app** (the socio area) still install and open as its own, separate app?

**Answer:** _(pending)_

---

## 293 — the counter stops re-sending the whole screen on every tap

**Who:** Shane, on the counter tablet, on the club Wi-Fi.

**Serve one visit** on the Dispensario: identify a socio, choose a genetic, type a weight on the keypad, add it, switch
to **Barra** and add a drink, tap **€20**, take the money. Along the way, tap the **Filtros** chips, the list / grid
toggle and the search box once each.

**Report:**

- Does each tap feel **instant**? The filters, the Dispensario / Barra tab, list / grid and the search should answer
  with no wait at all (they no longer talk to the server); adding to the basket, the bar line and the cash buttons
  should each answer in well under half a second.
- Did **anything look wrong** — a card in the wrong place, a toggle that looks pressed when it is not, a stock figure
  that did not change after the sale, a filter that did not narrow?
- Is identifying the **first socio** of a visit noticeably slower than before? It now brings both catalogues at once
  (so that the tab and the filters need nothing more from the server) — it is the one tap that got heavier.

**Answer:** _(pending)_

---

## 295 — *Hacer foto* or *Elegir archivo* on every photo

**Who:** Shane, on the club's Android tablet; and the owner, on a desktop.

**On the tablet**, open each photo field once. Check that *Hacer foto* opens the camera and *Elegir archivo* opens
the gallery or files:

- Panel:
  - Genéticas → *Añadir variedad* → Foto;
  - Lotes → *Añadir stock* (photos and lab report);
  - Productos → Nuevo;
  - a socio's record (Foto should open the **front** camera; Documento and Certificado médico the back one);
  - Gastos → receipt; Compras → invoice.
- Counter: *Nuevo socio/a* → staff alta (the photo on the **front** camera, the document on the back one).
- Member area: the applicant form's photo (front) and ID (back).

**On a desktop with no camera:** only *Elegir archivo* shows.

**Answer:** _(pending)_
