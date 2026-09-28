<?php

namespace Tests\Browser\Concerns;

/**
 * Prompt 293 — a counter response SKIPS the catalogue islands whose content has not changed, and the browser keeps
 * what it already has. A harness frame written from a later response would therefore be missing the catalogue the
 * operator is looking at; this puts each skipped island back from the last response that rendered it, as the page does.
 */
trait KeepsIslands
{
    private const ISLAND = '/<!--\[if FRAGMENT:type=island\|name=(?<name>[^|]+)\|token=(?<token>[^|]+)\|mode=(?<mode>\w+)\]><!\[endif\]-->(?<body>.*?)<!--\[if ENDFRAGMENT:type=island\|name=\k<name>\|token=\k<token>\|mode=\w+\]><!\[endif\]-->/s';

    protected function withKeptIslands(string $html, string ...$earlier): string
    {
        $rendered = [];
        foreach ($earlier as $page) {
            preg_match_all(self::ISLAND, $page, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if ($m['mode'] === 'morph') {
                    $rendered[$m['name']] = $m[0];
                }
            }
        }

        return (string) preg_replace_callback(self::ISLAND, fn (array $m): string => $m['mode'] === 'skip' && isset($rendered[$m['name']]) ? $rendered[$m['name']] : $m[0], $html);
    }
}
