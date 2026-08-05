{{-- The member PWA's shared text input (prompt 156) — one definition in App\Support\SocioForm::FIELD, so it can
     never drift into the padding-/border-/focus-ring-less approximation the message form had. --}}
<input {{ $attributes->merge(['class' => \App\Support\SocioForm::FIELD]) }}>
