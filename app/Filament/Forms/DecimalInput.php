<?php

namespace App\Filament\Forms;

use App\Support\TypedNumber;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Prompt 306 — the panel's ONE decimal field (grams, euros, percentages): `DecimalInput::make('grams')` wherever a panel
 * field takes one. `<input type="number">` only accepts the BROWSER's decimal separator, so on a UK/US-locale laptop a
 * Spanish "1000,01" could not be typed at all. This is a text input asking for a decimal keyboard; what was typed
 * ("1000,01", "1000.01", "1.000,01") is read through the one typed-number rule ({@see TypedNumber}) both for validation
 * and for the saved value — so `numeric()`, `minValue()` and `maxValue()` keep meaning numbers and the page's code
 * receives "1000.01". Something unreadable ("abc", a lone "1.000") reaches validation as typed and is refused with the
 * field's existing message. The counter's keypads are separate.
 */
class DecimalInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->type('text')
            ->inputMode('decimal')
            ->rule('numeric')
            ->mutateStateForValidationUsing(fn (mixed $state): mixed => self::canonical($state))
            ->dehydrateStateUsing(fn (mixed $state): mixed => self::canonical($state));
    }

    /**
     * The `numeric` RULE only. Filament's `numeric()` also casts the live state to a number, which turns "1000,01" into
     * 1000 before anything else sees it — exactly the input this field exists to accept.
     */
    public function numeric(bool|Closure $condition = true): static
    {
        $this->rule('numeric', $condition);

        return $this;
    }

    /**
     * A decimal field's LIVE state (a closure's `$get()`, `$this->data`) as a canonical number string, or null when it is
     * empty or unreadable. The saved data is already canonical; a live read sees what was typed ("196,5"), where
     * `is_numeric()` or a `(float)` cast would get it wrong.
     */
    public static function number(mixed $state): ?string
    {
        if (is_int($state) || is_float($state)) {
            return (string) $state;
        }

        return is_string($state) && trim($state) !== '' ? TypedNumber::canonical($state) : null;
    }

    public static function canonical(mixed $state): mixed
    {
        if (! is_string($state) || trim($state) === '') {
            return $state;
        }

        return TypedNumber::canonical($state) ?? $state;
    }
}
