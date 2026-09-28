<?php

namespace App\Filament\Support;

/**
 * Prompt 297 — a multi-choice whose FIRST option is "all of them" (*Todas las sedes*, *Tus sedes*, *Todas*) and stays in
 * step with the rest: ticking it ticks every choice; unticking any one unticks it; unticking it clears the choice; and
 * ticking the last one by hand ticks it too.
 */
final class AllOption
{
    public const KEY = 'all';

    /**
     * @param  array<mixed>|null  $state  the new selection
     * @param  array<mixed>|null  $old  the selection before this change
     * @param  list<string>  $choices  every real choice
     * @return list<string>
     */
    public static function sync(?array $state, ?array $old, array $choices): array
    {
        $state = array_map('strval', $state ?? []);
        $had = in_array(self::KEY, array_map('strval', $old ?? []), true);
        $has = in_array(self::KEY, $state, true);
        $picked = array_values(array_intersect($choices, $state));

        return match (true) {
            $has && ! $had => [self::KEY, ...$choices],
            $had && ! $has => count($picked) === count($choices) ? [] : $picked,
            count($choices) > 1 && count($picked) === count($choices) => [self::KEY, ...$picked],
            default => $picked,
        };
    }

    /**
     * The real choices in a submitted selection, without "all".
     *
     * @param  mixed  $state
     * @return list<string>
     */
    public static function chosen($state): array
    {
        return array_values(array_filter(array_map('strval', (array) $state), fn (string $v): bool => $v !== '' && $v !== self::KEY));
    }
}
