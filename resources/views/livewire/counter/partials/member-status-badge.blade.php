{{-- The member's status badge — ONE rendering for every counter screen (prompt 272). The same socio read as an
     outlined neutral pill on Socios and a green tinted pill on Recepción and the POS; an operator moving between
     screens should not have to re-read a familiar card. Colour AND the word, never colour alone.
     Params: `status` (App\Enums\MemberStatus), optional `size` ('sm' on the compact cards, 'md' at the door). --}}
@php
    $badgeColour = match ($status) {
        \App\Enums\MemberStatus::ACTIVE => 'border-success/30 bg-success/10 text-success',
        \App\Enums\MemberStatus::APPLICANT => 'border-warning/30 bg-warning/10 text-warning',
        \App\Enums\MemberStatus::SUSPENDED, \App\Enums\MemberStatus::EXPELLED => 'border-error/30 bg-error/10 text-error',
        default => 'border-line bg-surface-alt text-ink-muted dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
    };
@endphp
<span data-member-status-badge @class([
    'rounded-full border font-semibold uppercase tracking-wide',
    'px-2.5 py-0.5 text-xs' => ($size ?? 'sm') === 'md',
    'px-1.5 py-0.5 text-[10px]' => ($size ?? 'sm') !== 'md',
    $badgeColour,
])>{{ $status->label() }}</span>
