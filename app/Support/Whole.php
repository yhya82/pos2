<?php

namespace App\Support;

use RuntimeException;

/**
 * Quantities are counted in whole units — 1, 2, 3 — never 2.5. One definition
 * of "whole" for every form rule and service check, so they can't drift apart.
 * "5", "5.0" and "5.000" all count (they are five); "2.5" and "abc" don't.
 */
final class Whole
{
    public static function is(mixed $value): bool
    {
        return is_numeric($value) && (float) $value === floor((float) $value);
    }

    /**
     * @throws RuntimeException when $value isn't a whole number of at least $min
     */
    public static function assert(mixed $value, string $label, int $min = 0): void
    {
        if (! self::is($value) || (float) $value < $min) {
            $shown = is_scalar($value) ? (string) $value : '?';

            throw new RuntimeException($min > 0
                ? "{$label} must be a whole number of {$min} or more (1, 2, 3…) — it was {$shown}."
                : "{$label} must be a whole number (0, 1, 2…) — it was {$shown}.");
        }
    }
}
