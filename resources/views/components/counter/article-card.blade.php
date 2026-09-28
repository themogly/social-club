{{-- THE article card — one component, both bars (prompt 230).

     The owner, with the two screens side by side: *"make the standalone bar POS the same design as the other
     one."* They were two staff surfaces selling the same articles from the same sede and disagreeing about
     density, about facts, and about whether a sold-out article exists at all. Measured on `2306824` at
     1180×820:

       standalone Bar   list row 68px · grid tile 166px with a 🛒 placeholder · exact stock · sold-out disabled
       POS Barra source list row 60px · compact tile, no placeholder    · NO stock · sold-out EXCLUDED

     So it is a component, and `ArticleCardConsumersTest` iterates its consumers — the fifth application of the
     pattern this project has now paid for five times (`OpensMemberships` 203→211, the MRZ partial 179→215,
     the application field list 210→215, the signature canvas 113→220, the login preamble 223→226). A third
     catalogue hand-rolling a fourth card fails the suite.

     **Contract** — the consumer supplies:
       · `article`  a row: id, name, price_cents|price_label, stock, low_stock, category_name, image_url
       · `action`   the Livewire method a tap calls — `addArticle` on the Bar, `addBarItem` on the POS. The
                    ACTION is the consumer's; the shape is not. Called as `$wire.…` (prompt 293): the card sits in
                    the catalogue ISLAND, where a `wire:click` would re-render only the island and leave the cart
                    beside it stale.
       · `search`   the fields the catalogue's browser-side search matches (prompt 293) — the consumer's rule.

     The SIZE is the container's (prompt 293): it carries `data-layout` = `list`, `grid` or `large` (248 — the
     standalone Bar's big tile) and the card styles itself with the `as-list:` / `as-grid:` / `as-large:` variants,
     so the list/grid/large toggle is one attribute in the browser, not a request that re-renders every card. A card
     given `data-catalogue-item` hides itself when the pane's filters or search leave it out.
       · `thumbs`   whether this sede has any article image at all (193: a large empty glyph is a
                    broken-looking gap rather than a design, so the column exists only where a picture does)

     **Stock is a COUNT, and sold-out is visible.** 185's state-word-never-number rule is the MEMBER MENU's —
     a member must not be able to race the counter for the last gram — and the POS's bar card had been citing
     it on a staff screen, which is the member rule misapplied. 216 already settled that staff screens carry
     quantities. An operator who cannot see that the coffee is out cannot know to restock it, so a sold-out
     article renders DISABLED WITH ITS COUNT and is never hidden.

     The disabled attribute is presentation. Both servers refuse a sold-out add and say why — the gate is not
     a picture (CLAUDE.md), and a test asserts the refusal rather than trusting the attribute. --}}
@props([
    'article',
    'action' => 'addArticle',
    'thumbs' => false,
    'search' => null,
])

@php
    $soldOut = (int) ($article['stock'] ?? 0) <= 0;
    $priceLabel = $article['price_label'] ?? null;
    $lowStock = (bool) ($article['low_stock'] ?? false);
@endphp

<button
    type="button"
    @if (! $soldOut) x-on:click="$wire.{{ $action }}('{{ $article['id'] }}')" @endif
    @disabled($soldOut)
    data-product
    data-article-card="{{ $article['id'] }}"
    data-category="{{ $article['category_id'] ?? '' }}"
    data-search="{{ implode("\n", $search ?? [$article['name']]) }}"
    @if ($attributes->has('data-catalogue-item')) x-show="visible($el)" @endif
    {{ $attributes->class([
        'flex w-full min-h-11 rounded-xl border px-3 py-1.5 text-left transition',
        // 225's density, on both screens now: name + meta left, price over stock right.
        'as-grid:flex-col as-grid:gap-1',
        'as-list:flex-row as-list:items-center as-list:gap-3',
        // Prompt 248 — the LARGE size (standalone Bar only): a big tile, name and price large, stock small.
        // `!` overrides the base min-h/padding.
        'as-large:flex-col as-large:justify-between as-large:gap-2 as-large:!min-h-[120px] as-large:!px-4 as-large:!py-3',
        // Prompt 272 — a DARK hover: on a tablet `:hover` sticks to the last tile tapped, and the light tint left
        // the tile's secondary text at 1.91:1 in dark.
        'border-line bg-surface hover:border-brand hover:bg-brand-tint/40 dark:border-slate-700 dark:bg-slate-950 dark:hover:border-brand dark:hover:bg-slate-800' => ! $soldOut,
        'cursor-not-allowed border-dashed border-line bg-surface-alt opacity-60 dark:border-slate-800 dark:bg-slate-900' => $soldOut,
    ]) }}
>
    {{-- A thumbnail ONLY where an image exists (prompt 193, quoted in the Bar's own list mode while its grid
         tile still rendered a 🛒 block). Missing pictures are the club's to supply; nothing here invents one. --}}
    @if ($thumbs)
        <span class="flex shrink-0 items-center justify-center overflow-hidden rounded-lg bg-surface-alt dark:bg-slate-800 as-list:h-10 as-list:w-10 as-grid:h-16 as-grid:w-full as-large:h-24 as-large:w-full">
            @if ($article['image_url'] ?? null)
                <img src="{{ $article['image_url'] }}" alt="" class="h-full w-full object-cover">
            @else
                <span class="text-sm font-semibold text-ink-muted dark:text-slate-400">{{ mb_strtoupper(mb_substr($article['name'], 0, 1)) }}</span>
            @endif
        </span>
    @endif

    <span class="min-w-0 flex-1">
        <span data-product-name class="block truncate font-semibold leading-tight as-large:!text-lg">{{ $article['name'] }}</span>
        @if ($article['category_name'] ?? null)
            <span class="mt-0.5 block truncate text-[11px] leading-tight text-ink-muted dark:text-slate-400">{{ $article['category_name'] }}</span>
        @endif
    </span>

    <span class="flex shrink-0 text-xs as-list:flex-col as-list:items-end as-list:gap-0.5 as-grid:w-full as-grid:flex-row as-grid:items-center as-grid:justify-between as-large:w-full as-large:flex-row as-large:items-center as-large:justify-between">
        <span class="text-sm font-semibold text-brand tabular-nums dark:text-slate-100 as-large:!text-xl">{{ $priceLabel ?? $this->money($article['price_cents']) }}</span>

        {{-- The count, on both screens. Sold-out and low stock are said in words AND colour; neither is
             colour alone. --}}
        <span data-article-stock class="whitespace-nowrap tabular-nums">
            @if ($soldOut)
                <span class="inline-flex items-center gap-1 text-error"><span class="h-2 w-2 rounded-full bg-error"></span>{{ __('Agotado') }} · 0</span>
            @elseif ($lowStock)
                <span class="inline-flex items-center gap-1 text-warning"><span class="h-2 w-2 rounded-full bg-warning"></span>{{ __('Quedan pocas') }} · {{ $article['stock'] }}</span>
            @else
                <span class="text-ink-muted dark:text-slate-400">{{ __('Stock') }}: {{ $article['stock'] }}</span>
            @endif
        </span>
    </span>
</button>
