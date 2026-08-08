{{--
    The member/applicant language switcher (prompts 96, 167).

    Extracted from the socio layout header so it can render on screens that have no member chrome.
    It used to live inside `@if ($authed && $nav)`, which meant the one audience who most needs it —
    a prospective member reading the application form on their phone, unauthenticated and with the
    bottom nav switched off — was the only audience that never saw it.

    Behaviour is unchanged: POSTs to `socio.locale`, which persists to the member row when there IS
    one and always drops a session override so the change applies on the very next request.
--}}
@php($localeOptions = array_values(array_intersect(['es', 'en'], (array) \App\Support\Settings::get('enabled_locales', ['es', 'en']))))

@if (count($localeOptions) > 1)
    <form method="POST" action="{{ route('socio.locale') }}" data-locale-switcher
          class="flex items-center rounded-lg border border-line p-0.5 dark:border-slate-800">
        @csrf
        {{-- Come back to the page they were reading. `back()` alone is unreliable for a form POST on
             a page reached from an emailed link, where there may be no referer at all. --}}
        <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
        @foreach ($localeOptions as $loc)
            <button type="submit" name="locale" value="{{ $loc }}" data-locale="{{ $loc }}"
                    lang="{{ $loc }}"
                    aria-label="{{ $loc === 'es' ? __('Cambiar a español') : __('Cambiar a inglés') }}"
                    @class([
                        // 44×44 (prompt 217). It was 31×24 — WCAG 2.2's 24px minimum, which prompt 98 set
                        // deliberately — and that was defensible until the page it sits on was measured as a
                        // whole: this is the product's one phone-first surface, and every other control on it
                        // now clears 44. The GLYPH is unchanged; the padding grew. It stays visually discreet
                        // because it is chrome, not content.
                        'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-3 text-xs font-semibold uppercase transition',
                        'bg-brand text-white' => app()->getLocale() === $loc,
                        'text-ink-muted hover:text-brand dark:text-slate-400' => app()->getLocale() !== $loc,
                    ])>{{ $loc }}</button>
        @endforeach
    </form>
@endif
