<?php

namespace App\Livewire\Counter\Concerns;

use App\Actions\Members\ResolveMemberByToken;
use App\Exceptions\ScanRateLimitedException;
use App\Models\Member;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * THE member lookup — one field, used by every counter screen that identifies a socio (prompt 194).
 *
 * Before this there were SEVEN inputs across five screens. The dispensary blocking state and the check-in
 * screen each offered TWO, stacked: *"Escanear tarjeta o buscar socio"* (which already accepted a typed
 * name) directly above *"o busca por nombre / nº de socio"* (which did the same job again). An operator
 * reading top to bottom had to decide which box a typed name belonged in, and the answer was *either* —
 * the worst possible answer. Socios, the till and the bar offered a name box with no scan affordance at
 * all, and since a USB wedge reader just types into whatever is focused and presses Enter, a card scanned
 * there landed in a name search and found nothing. Between them the two shapes taught operators that
 * scanning "works on Dispensario but not on Socios", which is not a rule anyone designed.
 *
 * So: ONE box. The operator types or scans into it and this works out which happened.
 *
 *   1. The raw input goes to {@see ResolveMemberByToken}. If it resolves, the member is identified at once.
 *   2. If it does not, the name / member_no search runs and renders its results in place, beneath the input.
 *
 * No mode toggle, no radio, no second field. The host decides only what happens AFTER a member is found,
 * through {@see onMemberFound()} — if a host ever needs this to behave differently BEFORE that point, the
 * shape is wrong and the difference belongs after the event, not inside here.
 *
 * A card UID is an identifier, not a secret: token lookup is fine at a staffed counter where a person is
 * standing in front of an operator, and this trait is composed only by `App\Livewire\Counter` screens. It
 * must never reach the member PWA, which has its own guard and its own scoped lookup.
 */
trait FindsMembers
{
    /** The ONE lookup field. A scan and a typed name arrive in the same box. */
    public string $lookup = '';

    /** True once a lookup failed to resolve as a token and fell through to the name search. */
    public bool $lookupSearched = false;

    /**
     * Does this input plausibly come from a scanner rather than a keyboard?
     *
     * This is the whole reason prompt 58's failed-scan throttle does not fire on ordinary searching. Once
     * every unresolved input passes through ResolveMemberByToken, every typed name that is not a token
     * looks like a failed scan — and a staff member searching thirty members across a shift would trip a
     * limiter built for someone brute-forcing QR codes.
     *
     * A card token is `Str::random(48)`: long and strictly alphanumeric. A human typing "García" or
     * "M-00042" matches neither the length nor the charset, so a search miss is never counted as a scan
     * failure, and a malformed token still is.
     */
    public static function looksLikeAScan(string $input): bool
    {
        return mb_strlen($input) >= 32 && preg_match('/^[A-Za-z0-9]+$/', $input) === 1;
    }

    /** Enter (or the scanner's trailing Return) — token first, then the name search in place. */
    public function submitLookup(): void
    {
        $term = trim($this->lookup);

        if ($term === '') {
            return;
        }

        try {
            // A throttle key ONLY when this could have been a scan — see looksLikeAScan().
            $member = (new ResolveMemberByToken)->handle(
                $term,
                self::looksLikeAScan($term) ? (string) (Auth::id() ?? request()->ip()) : null,
            );
        } catch (ScanRateLimitedException) {
            $this->flash(__('Demasiados intentos de escaneo. Espera unos segundos.'), 'error');

            return;
        }

        if ($member !== null) {
            $this->lookup = '';
            $this->lookupSearched = false;
            $this->onMemberFound($member, scanned: true);

            return;
        }

        // Not a token: fall through to the name / nº search, rendered beneath the same box.
        $this->lookupSearched = true;
    }

    /** A result row was tapped. */
    public function selectMember(string $memberId): void
    {
        $member = Member::query()->find($memberId);

        if ($member === null) {
            return;
        }

        $this->lookup = '';
        $this->lookupSearched = false;
        $this->onMemberFound($member, scanned: false);
    }

    public function clearLookup(): void
    {
        $this->lookup = '';
        $this->lookupSearched = false;
    }

    /**
     * The name / member_no results to render in place, or null when there is nothing to show yet.
     *
     * @return Collection<int, Member>|null
     */
    public function lookupResults(): ?Collection
    {
        $term = trim($this->lookup);

        if (! $this->lookupSearched || mb_strlen($term) < 2) {
            return null;
        }

        return Member::query()
            ->where(fn ($q) => $q
                ->where('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%')
                ->orWhere('member_no', 'like', '%'.$term.'%'))
            ->orderBy('last_name')
            ->limit(10)
            ->get();
    }

    /**
     * Does this sede have card readers? Configuration, not feature detection — a USB QR or RFID reader IS a
     * keyboard, with no presence any browser API can detect, so there is nothing to feature-test. Default
     * off, because a club with no readers is the common case and should never be told to scan.
     *
     * It governs the WORDS ONLY. Token resolution still runs first when it is off, so a scan that happens
     * anyway still works.
     */
    public function cardReadersEnabled(): bool
    {
        return (bool) Settings::get('card_readers_enabled', false);
    }

    /** What the one field is called, which is the only thing the readers flag changes. */
    public function lookupLabel(): string
    {
        return $this->cardReadersEnabled()
            ? __('Escanea la tarjeta o escribe un nombre / nº de socio')
            : __('Buscar socio por nombre o nº');
    }

    public function lookupPlaceholder(): string
    {
        return $this->cardReadersEnabled()
            ? __('Escanea la tarjeta, o escribe y pulsa Enter')
            : __('Ej. García o M-00042');
    }

    /** What the host does once a socio is identified. The ONLY thing that differs between screens. */
    abstract protected function onMemberFound(Member $member, bool $scanned): void;
}
