<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * Seeded random number generator.
 *
 * Self-contained xoshiro128** rather than PHP 8.2's \Random\Randomizer, so the
 * planner runs on any PHP 8.0+, and rather than mt_srand()/mt_rand(), which
 * would trample the global random state of whatever else is running in the same
 * process. Same seed always yields the same plan.
 */
final class Rng
{
    private const MASK = 0xFFFFFFFF;

    /** @var array<int, int> four 32-bit state words */
    private array $state;

    public function __construct(public int $seed)
    {
        // SplitMix32 expands the seed into a well-mixed 128-bit state; seeding
        // xoshiro directly from a small integer gives poor early output.
        $x = $seed & self::MASK;
        $state = [];

        for ($i = 0; $i < 4; $i++) {
            $x = ($x + 0x9E3779B9) & self::MASK;
            $z = $x;
            $z = self::mul32($z ^ ($z >> 16), 0x85EBCA6B);
            $z = self::mul32($z ^ ($z >> 13), 0xC2B2AE35);
            $state[$i] = ($z ^ ($z >> 16)) & self::MASK;
        }

        // An all-zero state is a fixed point of the generator.
        $this->state = array_sum($state) === 0 ? [1, 2, 3, 4] : $state;
    }

    /** Uniform float in [0, 1). */
    public function float(): float
    {
        return $this->next() / 4294967296.0;
    }

    /** Uniform float in [$min, $max). */
    public function between(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    public function int(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        $value = $min + (int) floor($this->float() * ($max - $min + 1));

        return $value > $max ? $max : $value;
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
     * Fisher-Yates, driven by this generator so shuffling stays reproducible.
     *
     * @template T
     *
     * @param  array<int, T>  $values
     * @return array<int, T>
     */
    public function shuffle(array $values): array
    {
        $values = array_values($values);

        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }

    /**
     * Heavy-tailed positive weight: a few very large values, many small ones.
     * Used to model "a handful of members / threads carry most of the activity".
     */
    public function powerLaw(float $exponent = 0.8): float
    {
        return pow($this->between(0.02, 1.0), -$exponent);
    }

    /** One 32-bit draw from xoshiro128**. */
    private function next(): int
    {
        [$s0, $s1, $s2, $s3] = $this->state;

        $result = ($this->rotl(($s1 * 5) & self::MASK, 7) * 9) & self::MASK;

        $t = ($s1 << 9) & self::MASK;

        $s2 ^= $s0;
        $s3 ^= $s1;
        $s1 ^= $s2;
        $s0 ^= $s3;
        $s2 ^= $t;
        $s3 = $this->rotl($s3, 11);

        $this->state = [$s0, $s1, $s2, $s3];

        return $result;
    }

    private function rotl(int $value, int $shift): int
    {
        return (($value << $shift) | ($value >> (32 - $shift))) & self::MASK;
    }

    /**
     * 32-bit multiply, mod 2^32.
     *
     * Done in two 16-bit halves because the plain product of two 32-bit values
     * exceeds PHP_INT_MAX and silently becomes a float, which both loses
     * precision and raises a deprecation notice on PHP 8.1+.
     */
    private static function mul32(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;

        $low = ($a & 0xFFFF) * $b;
        $high = (((($a >> 16) * $b) & 0xFFFF) << 16) & self::MASK;

        return ($low + $high) & self::MASK;
    }
}
