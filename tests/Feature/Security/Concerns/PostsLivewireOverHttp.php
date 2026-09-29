<?php

namespace Tests\Feature\Security\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Drive a Livewire component the way the BROWSER does (prompt 254): take its snapshot from a real `GET`, then
 * `POST` it to Livewire's real update endpoint with the `X-Livewire` header — through the HTTP kernel, so every
 * route and global middleware runs.
 *
 * `Livewire::test()` never passes through HTTP middleware. That is exactly how prompt 249 shipped a handover the
 * PIN could not end: its tests drove the component in-process, while every real PIN post was redirected by
 * `EnforceCounterHandover` before it reached `unlockOperator()`. Where the defect is a REQUEST, test the request.
 */
trait PostsLivewireOverHttp
{
    /** The component's `wire:snapshot` as rendered by a real GET of `$uri`, or a failure naming what was there. */
    protected function snapshotFrom(string $uri, string $componentName): string
    {
        $html = (string) $this->get($uri)->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $decoded = json_decode($snapshot, true);

            if (($decoded['memo']['name'] ?? null) === $componentName) {
                return $snapshot;
            }
        }

        $this->fail("No `{$componentName}` snapshot on {$uri}.");
    }

    /**
     * POST one component's updates + calls to the real update endpoint — Livewire's own resolved URI, never a
     * hard-coded `livewire/update` (that path does not exist in Livewire 4; the prefix is hashed from APP_KEY).
     *
     * @param  array<string, mixed>  $updates
     * @param  list<array{0: string, 1?: list<mixed>}>  $calls  [method, params]
     * @param  array<string, string>  $headers  e.g. the `Referer` a browser sends (the page the component is on)
     */
    protected function livewirePost(string $snapshot, array $updates = [], array $calls = [], array $headers = []): TestResponse
    {
        // Each browser request is a fresh PHP process; a test's requests share one. Livewire remembers the routes it has
        // run persistent middleware for until `flush-state`, so without this a SECOND post from the same page skipped
        // them all (prompt 310 found it: a refused press passed in the test). Flush, as a new request would.
        app('livewire')->flushState();

        return $this->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) $updates,
                'calls' => array_map(fn (array $call): array => [
                    'method' => $call[0],
                    'params' => $call[1] ?? [],
                    'metadata' => (object) [],
                ], $calls),
            ]],
        ], ['X-Livewire' => '1', ...$headers]);
    }
}
