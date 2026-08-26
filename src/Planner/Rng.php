<?php

namespace Pbiaut\AiSeeder\Planner;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded random number generator. Uses a private engine instance rather than
 * the global mt_rand() state, so planning is reproducible and never disturbs
 * anything else running in the same PHP process.
 */
final class Rng
{
    private Randomizer $randomizer;

    public function __construct(public readonly int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /** Uniform float in [0, 1). */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    /** Uniform float in [$min, $max). */
    public function between(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    public function int(int $min, int $max): int
    {
        return $min >= $max ? $min : $this->randomizer->getInt($min, $max);
    }

    public function bool(float $probability = 0.5): bool
    {
        return $this->float() < $probability;
    }

    /** Normal distribution via Box-Muller. */
    public function gauss(float $mean, float $stdDev): float
    {
        $u1 = max(1e-12, $this->float());
        $u2 = $this->float();

        return $mean + $stdDev * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /**
     * Pick a key from a weighted map. Keys are preserved.
     *
     * @param  array<array-key, float>  $weights
     */
    public function weightedKey(array $weights): int|string|null
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            $keys = array_keys($weights);

            return $keys === [] ? null : $keys[$this->int(0, count($keys) - 1)];
        }

        $target = $this->float() * $total;

        foreach ($weights as $key => $weight) {
            $target -= $weight;

            if ($target <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $values
     * @return array<int, T>
     */
    public function shuffle(array $values): array
    {
        return $this->randomizer->shuffleArray($values);
    }

    /**
     * Heavy-tailed positive weight: a few very large values, many small ones.
     * Used to model "a handful of members / threads carry most of the activity".
     */
    public function powerLaw(float $exponent = 0.8): float
    {
        return pow($this->between(0.02, 1.0), -$exponent);
    }
}
