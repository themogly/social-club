{{--
    The dispensary's one host-specific note beside the shared lookup (prompt 194): when the sede restricts the
    POS to socios who have registered their entrada, say so where the operator is looking for somebody.

    It sits OUTSIDE partials/member-lookup on purpose. The lookup is the same on all five counter screens and
    stays that way; anything true of one screen only is the host's chrome, added after it.
--}}
@if ($requireCheckedIn)
    <p class="mt-2 text-xs text-ink-muted dark:text-slate-400">{{ __('Esta sede solo permite dispensar a socios que han registrado su entrada.') }}</p>
@endif
