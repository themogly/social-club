<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Process;

/**
 * Prompt 293 moved the counter catalogue's filters and search into the browser. A test of "the Variedad filter narrows
 * to Sativa" can no longer call a server method — so it runs the REAL browser rule (`resources/js/catalogue-search.js`,
 * the module app.js calls) in node, over the cards the server actually rendered, exactly as the page would.
 */
trait FiltersTheCatalogueLikeTheBrowser
{
    /**
     * The names of the `$source` cards the browser would show for this filter state.
     *
     * @param  array{search?: array<string, string>, category?: array<string, ?string>, productType?: ?string, strainType?: ?string}  $state
     * @return list<string>
     */
    protected function visibleInBrowser(string $html, string $source, array $state = []): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        $items = [];
        foreach ((new DOMXPath($dom))->query('//*[@data-catalogue-item="'.$source.'"]') ?: [] as $card) {
            /** @var DOMElement $card */
            $items[] = [
                'source' => $source,
                'category' => $card->getAttribute('data-category'),
                'type' => $card->getAttribute('data-type'),
                'strain' => $card->getAttribute('data-strain'),
                'search' => explode("\n", $card->getAttribute('data-search')),
            ];
        }

        $state = array_replace_recursive([
            'search' => ['genetics' => '', 'bar' => ''],
            'category' => ['genetics' => null, 'bar' => null],
            'productType' => null,
            'strainType' => null,
        ], $state);

        return array_map(fn (array $item): string => $item['search'][0], $this->runCatalogueJs(
            'const [items, state] = process.argv.slice(1).map((a) => JSON.parse(a));'
            .'console.log(JSON.stringify(items.filter((i) => m.catalogueShows(i, state))));',
            [$items, $state],
        ));
    }

    /**
     * Run a snippet against the catalogue module (imported as `m`) and decode what it prints.
     *
     * @param  list<mixed>  $args
     * @return list<mixed>
     */
    protected function runCatalogueJs(string $body, array $args): array
    {
        $module = 'file://'.resource_path('js/catalogue-search.js');
        $script = "import * as m from '{$module}';".$body;

        $result = Process::run(['node', '--input-type=module', '-e', $script, ...array_map(fn ($a): string => (string) json_encode($a), $args)]);
        $this->assertTrue($result->successful(), 'node could not run the catalogue module: '.$result->errorOutput());

        return json_decode($result->output(), true);
    }
}
