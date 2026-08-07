{{-- The counter's chrome (prompt 209). Rendered by the layout, but decided HERE, because a Livewire response
     re-renders a component and never the Blade layout around it.

     Skip link (a11y audit): the top bar is ~7 controls to tab past on EVERY counter screen, and the panel
     already has one. Visually hidden until focused, then it is the first thing on the page. Inside the
     handover guard, same rule as the rest of the chrome.

     One shared header for every counter terminal (the club + this screen's title, the sede, who is working,
     Lock, and — behind a divider, because they LEAVE the counter — a permission-filtered Administración link
     + Log out). See x-counter.top-bar.

     Handed over (prompt 173): the chrome is ABSENT from the DOM, not hidden by CSS. The Administración link,
     Log out, the sede switcher and the panic button are all inside the bar — while an applicant holds the
     tablet there is no element to find, no link to follow and nothing for a keyboard to reach. --}}
<div>
    @unless ($handedOver)
        <a href="#counter-main"
           class="sr-only rounded-xl bg-brand text-sm font-semibold text-white focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[60] focus:px-4 focus:py-2">{{ __('Saltar al contenido') }}</a>
        <x-counter.top-bar :title="$title" />
    @endunless
</div>
