{{-- Prompt 353 — one guide, the same on the counter, a staff phone (/docs) and the panel. `$page` is
     GuideController::page(): the guide, its rendered HTML (CommonMark, raw HTML escaped) and its contents list.
     Images open full size on tap, in an overlay inside the page (the app never opens a tab); the Back gesture closes it. --}}
@php($g = $page['guide'])
<article data-guide="{{ $g->slug }}" class="mx-auto w-full max-w-[70ch]"
         x-data="{ zoom: null,
                   show(img) { this.zoom = { src: img.currentSrc || img.src, alt: img.alt }; history.pushState({ guideZoom: true }, '') },
                   hide() { if (this.zoom) { this.zoom = null; history.back() } } }"
         x-on:popstate.window="zoom = null"
         x-on:keydown.escape.window="hide()">
    <header class="mb-6">
        @if ($heading ?? true)
            <h1 class="text-2xl font-semibold leading-tight text-ink dark:text-white">{{ $g->title }}</h1>
        @endif
        <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">
            {{ __('Actualizada el :date', ['date' => $g->updated->locale(app()->getLocale())->isoFormat('LL')]) }}
            @if ($g->forManagers()) · <span class="font-medium text-brand dark:text-slate-200">{{ __('Para responsables') }}</span>@endif
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            {{-- A download, not a navigation: the PDF is saved, the page stays where it is. --}}
            <a href="{{ route('guides.pdf', $g->slug) }}" download data-guide-pdf
               class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-sm font-semibold text-ink transition hover:border-brand hover:text-brand dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                {{ __('Descargar PDF') }}
            </a>
        </div>
    </header>

    @if (count($page['toc']) > 1)
        <nav data-guide-toc aria-label="{{ __('Contenido') }}" class="mb-8 rounded-xl border border-line bg-surface-alt p-4 dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted dark:text-slate-400">{{ __('Contenido') }}</p>
            <ol class="mt-2 space-y-1 text-sm">
                @foreach ($page['toc'] as $entry)
                    <li><a href="#{{ $entry['id'] }}" class="inline-flex min-h-9 items-center text-brand hover:underline dark:text-slate-200">{{ $entry['title'] }}</a></li>
                @endforeach
            </ol>
        </nav>
    @endif

    <div data-guide-body
         x-on:click="$event.target.matches('[data-guide-image]') && show($event.target)"
         class="text-[15px] leading-relaxed text-ink dark:text-slate-200
                [&_h2]:mt-10 [&_h2]:scroll-mt-24 [&_h2]:border-b [&_h2]:border-line [&_h2]:pb-1 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-ink dark:[&_h2]:border-slate-800 dark:[&_h2]:text-white
                [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-ink dark:[&_h3]:text-white
                [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:mt-1
                [&_strong]:font-semibold [&_a]:text-brand [&_a]:underline
                [&_blockquote]:mt-4 [&_blockquote]:rounded-xl [&_blockquote]:border-l-4 [&_blockquote]:border-brand [&_blockquote]:bg-brand-tint [&_blockquote]:px-4 [&_blockquote]:py-3 dark:[&_blockquote]:bg-slate-800/60
                [&_blockquote_p:first-child]:mt-0
                [&_table]:mt-4 [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto [&_table]:text-sm [&_table]:border-collapse
                [&_th]:border-b [&_th]:border-line [&_th]:bg-surface-alt [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_th]:font-semibold dark:[&_th]:border-slate-700 dark:[&_th]:bg-slate-900
                [&_td]:border-b [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_td]:align-top dark:[&_td]:border-slate-800
                [&_img]:mt-4 [&_img]:w-full [&_img]:cursor-zoom-in [&_img]:rounded-xl [&_img]:border [&_img]:border-line [&_img]:shadow-sm dark:[&_img]:border-slate-700
                [&_p:has(img)+p>em:only-child]:block [&_p:has(img)+p>em:only-child]:text-sm [&_p:has(img)+p>em:only-child]:text-ink-muted dark:[&_p:has(img)+p>em:only-child]:text-slate-400
                [&_code]:rounded [&_code]:bg-surface-alt [&_code]:px-1 [&_code]:text-sm dark:[&_code]:bg-slate-800">
        {!! $page['html'] !!}
    </div>

    <div x-show="zoom" x-cloak x-on:click="hide()" data-guide-zoom role="dialog" aria-modal="true" aria-label="{{ __('Imagen a tamaño completo') }}"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/90 p-3">
        <img x-bind:src="zoom?.src" x-bind:alt="zoom?.alt" class="max-h-full max-w-full rounded-lg object-contain">
        <button type="button" x-on:click.stop="hide()" class="absolute right-3 top-3 inline-flex h-11 min-w-11 items-center justify-center rounded-xl bg-white/90 px-3 text-sm font-semibold text-ink">{{ __('Cerrar') }}</button>
    </div>
</article>
