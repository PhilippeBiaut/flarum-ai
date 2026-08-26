<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * Turns a total count plus a set of weights into integer counts that sum to
 * exactly the total (largest-remainder method). This is what guarantees
 * "50 discussions requested => 50 discussions planned", whatever the shape of
 * the distribution.
 */
final class DayDistributor
{
    /**
     * @param  array<array-key, float>  $weights
     * @return array<array-key, int>  same keys, same order, summing to $total
     */
    public static function distribute(int $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $result = array_map(fn () => 0, $weights);

        if ($total <= 0) {
            return $result;
        }

        $sum = array_sum($weights);

        if ($sum <= 0) {
            $weights = array_map(fn () => 1.0, $weights);
            $sum = (float) count($weights);
        }

        $fractions = [];

        foreach ($weights as $key => $weight) {
            $exact = $total * max(0.0, (float) $weight) / $sum;
            $result[$key] = (int) floor($exact);
            $fractions[$key] = $exact - floor($exact);
        }

        $remainder = $total - array_sum($result);

        if ($remainder > 0) {
            arsort($fractions);

            foreach (array_keys($fractions) as $key) {
                if ($remainder <= 0) {
                    break;
                }

                $result[$key]++;
                $remainder--;
            }
        }

        return $result;
    }

    /**
     * Multinomial draw: $total independent picks among the slots, proportional
     * to their weights.
     *
     * Preferred over distribute() whenever the total can be smaller than the
     * number of slots. Largest-remainder would then hand every unit to the
     * top-weighted slots and leave the whole start of the period empty; drawing
     * instead keeps activity spread over the period while still respecting the
     * weekday and growth shape.
     *
     * @param  array<array-key, float>  $weights
     * @return array<array-key, int>  same keys, summing to exactly $total
     */
    public static function sample(int $total, array $weights, Rng $rng): array
    {
        $result = array_map(fn () => 0, $weights);

        if ($total <= 0 || $weights === []) {
            return $result;
        }

        $keys = array_keys($weights);
        $prefix = [];
        $cumulative = 0.0;

        foreach ($keys as $position => $key) {
            $cumulative += max(0.0, (float) $weights[$key]);
            $prefix[$position] = $cumulative;
        }

        if ($cumulative <= 0) {
            return self::distribute($total, $weights);
        }

        $last = count($keys) - 1;

        for ($draw = 0; $draw < $total; $draw++) {
            $target = $rng->float() * $cumulative;
            $low = 0;
            $high = $last;

            while ($low < $high) {
                $mid = intdiv($low + $high, 2);

                if ($prefix[$mid] < $target) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            $result[$keys[$low]]++;
        }

        return $result;
    }

    /**
     * Same as distribute(), but every entry is kept within [$min, $max].
     * The total is honoured exactly whenever it is feasible; when it is not,
     * the closest feasible total is produced and the caller is expected to warn.
     *
     * @param  array<array-key, float>  $weights
     * @return array<array-key, int>
     */
    public static function distributeClamped(int $total, array $weights, int $min, int $max): array
    {
        if ($weights === []) {
            return [];
        }

        $count = count($weights);
        $min = max(0, $min);
        $max = max($min, $max);

        // Clamp to what the [min, max] bounds allow at all.
        $total = max($count * $min, min($total, $count * $max));

        $result = array_map(fn () => $min, $weights);
        $capacity = array_map(fn () => $max - $min, $weights);
        $remaining = $total - $count * $min;

        while ($remaining > 0) {
            $active = array_filter($capacity, fn (int $c) => $c > 0);

            if ($active === []) {
                break;
            }

            $activeWeights = array_intersect_key($weights, $active);
            $allocation = self::distribute($remaining, $activeWeights);
            $moved = 0;

            foreach ($allocation as $key => $amount) {
                $take = min($amount, $capacity[$key]);
                $result[$key] += $take;
                $capacity[$key] -= $take;
                $moved += $take;
            }

            $remaining -= $moved;

            if ($moved === 0) {
                // Rounding stalled (every share floored to zero): nudge one slot.
                $key = array_key_first($active);
                $result[$key]++;
                $capacity[$key]--;
                $remaining--;
            }
        }

        return $result;
    }
}
