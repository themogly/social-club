// Prompt 293 — the counter catalogue's search, run in the browser over the cards already on the page.
//
// It is the server's rule, copied: the typed term trimmed and lower-cased, matched as a substring of each searchable
// field the card lists (a genetic's name; a bar article's name, and on the dispensary its category too). One thing
// is added on purpose: accents fold, so "maria" finds "María" — the owner's example; the server's mb_strtolower did
// not fold them. Search only changes what is VISIBLE: what is sold, and at what price, is still decided on the server.
export const fold = (value) =>
    String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();

export function catalogueMatches(term, fields) {
    const needle = fold(String(term ?? '').trim());

    return needle === '' || fields.some((field) => fold(field).includes(needle));
}

// Whether one card shows under the pane's current filters. `item` is what the card says about itself (its data
// attributes): { source, category, type, strain, search: [fields] }. `state` is the pane's: { search: {source: term},
// category: {source: id|null}, productType, strainType }. Type and variety are facts about cannabis, so they filter
// the genetics source only (212).
export function catalogueShows(item, state) {
    const category = state.category?.[item.source] ?? null;
    if (category !== null && (item.category ?? '') !== category) return false;
    if (item.source === 'genetics') {
        if ((state.productType ?? null) !== null && (item.type ?? '') !== state.productType) return false;
        if ((state.strainType ?? null) !== null && (item.strain ?? '') !== state.strainType) return false;
        if (state.reserveOnly && !(Number(item.reserve ?? 0) > 0)) return false; // prompt 359 — «Con reserva»
    }
    return catalogueMatches(state.search?.[item.source] ?? '', item.search ?? []);
}

// Prompt 351 — the dispensary's strain order (€↓ / €↑ / A–Z), remembered on this tablet for the business day at this sede.
// `stored` is what the tablet saved ({ sede, date, sort }); `scope` is where and when it is now ({ sede, date }). Another
// sede, another business day, nothing saved or something unrecognised: the sede's default.
export const SORTS = ['price_desc', 'price_asc', 'alpha'];

export function rememberedSort(stored, scope, fallback) {
    if (!stored || stored.sede !== scope?.sede || stored.date !== scope?.date || !SORTS.includes(stored.sort)) return fallback;

    return stored.sort;
}
