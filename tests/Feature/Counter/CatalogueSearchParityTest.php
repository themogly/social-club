<?php

namespace Tests\Feature\Counter;

use Tests\Concerns\FiltersTheCatalogueLikeTheBrowser;
use Tests\TestCase;

/**
 * Prompt 293 — the catalogue search moved into the browser, and it finds what the server's search found.
 *
 * The server's rule, as it stood (`DispensaryPos::filterGenetics` / `filterArticles`, `BarPos::filterArticles`):
 * `str_contains(mb_strtolower($field), mb_strtolower(trim($term)))` over the name (and, for the dispensary's bar
 * source, the category name). It is kept below as the reference, and the SAME table of queries is typed into both.
 *
 * One deliberate difference, asked for by the owner: accents fold, so "maria" finds "María". The server's
 * mb_strtolower did not fold them; for every query without an accent mismatch the two agree exactly.
 */
class CatalogueSearchParityTest extends TestCase
{
    use FiltersTheCatalogueLikeTheBrowser;

    private const NAMES = ['María Kush', 'Amnesia Haze', 'Critical +', 'Northern Lights', 'Piña Colada', 'Café solo', 'OG KUSH'];

    /** The server rule the browser replaces, verbatim. */
    private static function serverSearch(string $term, string $name): bool
    {
        $needle = mb_strtolower(trim($term));

        return $needle === '' || str_contains(mb_strtolower($name), $needle);
    }

    /** @return list<string> */
    private function browserSearch(string $term): array
    {
        return $this->runCatalogueJs(
            'const [names, term] = process.argv.slice(1).map((a) => JSON.parse(a));'
            .'console.log(JSON.stringify(names.filter((n) => m.catalogueMatches(term, [n]))));',
            [self::NAMES, $term],
        );
    }

    public function test_the_browser_finds_what_the_server_found(): void
    {
        // An empty query, whitespace, a partial word, a different case, a word in the middle, a symbol, no match.
        foreach (['', '   ', 'kush', 'KUSH', 'amn', ' haze ', 'lights', '+', 'colada', 'zzz', 'María'] as $term) {
            $server = array_values(array_filter(self::NAMES, fn (string $name): bool => self::serverSearch($term, $name)));

            $this->assertSame($server, $this->browserSearch($term), "the search for \"{$term}\" disagrees");
        }
    }

    public function test_an_accented_name_is_found_without_the_accent(): void
    {
        $this->assertSame(['María Kush'], $this->browserSearch('maria'));
        $this->assertSame(['Piña Colada'], $this->browserSearch('pina'));
        $this->assertSame(['Café solo'], $this->browserSearch('cafe'));

        // …which the server's rule did not do: the one intended difference.
        $this->assertFalse(self::serverSearch('maria', 'María Kush'));
    }

    public function test_a_card_is_searched_on_every_field_it_lists(): void
    {
        // The dispensary's bar source searched the category too; the card lists both, and either one finds it.
        $this->assertSame(['Agua'], $this->runCatalogueJs(
            'const [term] = process.argv.slice(1).map((a) => JSON.parse(a));'
            .'console.log(JSON.stringify([["Agua", "Bebidas"], ["Camiseta", "Merchandising"]].filter((f) => m.catalogueMatches(term, f)).map((f) => f[0])));',
            ['bebi'],
        ));
    }
}
