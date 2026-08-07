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
 *   1. **As the operator types**, the name / member_no search runs and renders its results in place,
 *      beneath the input (prompt 204). It never resolves tokens, so it never reaches the scan throttle.
 *   2. **On Enter** — or the scanner's trailing Return — the raw input goes to {@see ResolveMemberByToken}
 *      first. If it resolves, the member is identified at once; if not, it falls through to the same search.
 *
 * One box, two lookups, and they are separable: that is what lets it be live. No mode toggle, no radio,
 * no second field. The host decides only what happens AFTER a member is found,
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

    /**
     * A camera-decoded QR token routes through the SAME lookup as the wedge scanner (prompt 35).
     *
     * It lives here rather than on each host because `x-counter.camera-scan` calls `$wire.submitCameraScan`
     * by name: identical two-line copies on the door and the dispensary were the same near-duplicate this
     * prompt exists to remove. A host renders the camera by passing `cameraScanEnabled` to the partial; the
     * three screens that do not are unchanged, and turning it on for one of them is now that one variable.
     */
    public function submitCameraScan(string $token): void
    {
        $this->lookup = $token;
        $this->submitLookup();
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
     * **Live as the operator types (prompt 204).** Prompt 194 gated this on `$lookupSearched` — results
     * appeared only after Enter — on the reasoning that one box cannot search per keystroke because a token
     * has to be resolved whole and a half-typed name would reach prompt 58's failed-scan throttle. The first
     * half of that is true and the second does not follow: **the two lookups are separable**. Only
     * {@see submitLookup()} resolves tokens, and only it can reach the throttle. Searching by name never
     * touches {@see ResolveMemberByToken} at all, so it can run on every keystroke for free.
     *
     * Three of the five screens this replaced DID search live; asking an operator with a member at the
     * counter to type, stop, and press a key they cannot see was the regression, and the placeholder that
     * told them to was the evidence that it needed explaining.
     *
     * A scan-shaped term is suppressed WHILE IT IS BEING TYPED: a wedge reader types its 48 characters into
     * this box before its trailing Return arrives, and *"Sin resultados."* flickering under every scan is
     * noise about a search nobody asked for. Once Enter HAS been pressed (`$lookupSearched`) the same term
     * searches normally — that is 194's fall-through for an unrecognised card, and it must still land on
     * *"Sin resultados."* rather than on nothing at all.
     *
     * @return Collection<int, Member>|null
     */
    public function lookupResults(): ?Collection
    {
        $term = trim($this->lookup);

        if (mb_strlen($term) < 2) {
            return null;
        }

        if (self::looksLikeAScan($term) && ! $this->lookupSearched) {
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

    /**
     * An example, not an instruction (prompt 204).
     *
     * 194 put *"pulsa Enter"* in both placeholders and argued it was load-bearing: with results gated behind
     * a submit, an operator who typed and waited saw nothing, so the field had to say what to do. That was
     * an honest fix for a shape that should not have existed — **a control that has to explain its own
     * keystroke is the defect**, not a control that is missing a caption. Results are live now, so there is
     * nothing to explain and the example comes back.
     *
     * The member-number half returns with it. 194 dropped it because the full string truncated in the bar's
     * narrow socio column at 1180x820 — but what it measured was the string WITH the Enter instruction on
     * the end. Re-measured without it on this branch: it fits.
     */
    public function lookupPlaceholder(): string
    {
        return $this->cardReadersEnabled()
            ? __('Escanea la tarjeta o escribe un nombre')
            : __('Ej. García o M-00042');
    }

    /** What the host does once a socio is identified. The ONLY thing that differs between screens. */
    abstract protected function onMemberFound(Member $member, bool $scanned): void;
}
