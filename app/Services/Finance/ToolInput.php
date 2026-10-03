<?php

namespace App\Services\Finance;

/**
 * Reads a tool's form out of the query string.
 *
 * The tools are calculators: every one renders from GET parameters, and a
 * half-typed or out-of-range figure should move the answer, not throw a
 * validation error at someone dragging a number around. So each value is
 * clamped into its range, and anything missing or unreadable falls back to
 * its default.
 */
class ToolInput
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array{0: float|int, 1: float|int, 2: float|int}>  $spec  key => [default, min, max]
     * @return array<string, float>
     */
    public static function numbers(array $input, array $spec): array
    {
        $numbers = [];

        foreach ($spec as $key => [$default, $min, $max]) {
            $value = $input[$key] ?? null;

            $numbers[$key] = is_numeric($value) ? (float) min($max, max($min, (float) $value)) : (float) $default;
        }

        return $numbers;
    }
}
