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
                        // ≥ 24×24 CSS px target (WCAG 2.2 Target Size, prompt 98) — a control
                        // non-native speakers depend on, so it keeps its floor here too.
                        'inline-flex min-h-[1.5rem] min-w-[1.75rem] items-center justify-center rounded-md px-2 py-1 text-xs font-semibold uppercase transition',
                        'bg-brand text-white' => app()->getLocale() === $loc,
                        'text-ink-muted hover:text-brand dark:text-slate-400' => app()->getLocale() !== $loc,
                    ])>{{ $loc }}</button>
        @endforeach
    </form>
@endif
