<?php

namespace App\Support;

/**
 * The result of every door/counter check for a member at a surface. Each rule is
 * satisfied or not, and carries its enforcement mode (BLOCK | WARN | OVERRIDE). The
 * check-in screen and the POS both render this and decide whether to admit, warn,
 * or require a manager override — from ONE resolver, so the door and the counter
 * never silently disagree.
 *
 * @phpstan-type Rule array{rule: string, satisfied: bool, mode: string, message: string}
 */
final class EligibilityVerdict
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    /**
     * Rules that failed and would hard-block (BLOCK or OVERRIDE mode) without an override.
     *
     * @return list<Rule>
     */
    public function blockingRules(): array
    {
        return array_values(array_filter(
            $this->rules,
            fn (array $r) => ! $r['satisfied'] && in_array($r['mode'], ['BLOCK', 'OVERRIDE'], true),
        ));
    }

    /**
     * Failed rules in WARN mode — admit, but flag.
     *
     * @return list<Rule>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->rules, fn (array $r) => ! $r['satisfied'] && $r['mode'] === 'WARN'));
    }

    public function isBlocked(): bool
    {
        return $this->blockingRules() !== [];
    }

    public function isClear(): bool
    {
        return array_filter($this->rules, fn (array $r) => ! $r['satisfied']) === [];
    }

    /**
     * @return list<string>
     */
    public function blockingMessages(): array
    {
        return array_map(fn (array $r) => $r['message'], $this->blockingRules());
    }
}
