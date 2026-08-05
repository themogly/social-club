{{-- The member PWA's shared textarea (prompt 156) — shares App\Support\SocioForm::FIELD with x-socio.input. --}}
<textarea {{ $attributes->merge(['class' => \App\Support\SocioForm::FIELD]) }}>{{ $slot }}</textarea>
