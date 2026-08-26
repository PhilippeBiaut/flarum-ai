<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * How long each reply should be.
 *
 * Real threads are mostly short answers with the occasional detailed one. Ask
 * a model for "a reply" without saying how long and it converges on the same
 * comfortable three paragraphs every time, which is the single most obvious
 * tell that a thread was generated.
 *
 * Each bucket is a range rather than a fixed count, and a concrete target is
 * drawn inside it, so no two replies aim for exactly the same length.
 */
final class ReplyLength
{
    /** label => [min words, max words, relative weight] */
    public const BUCKETS = [
        'very_short' => [8, 25, 20.0],   // "same here, worked for me"
        'short' => [25, 60, 28.0],
        'medium' => [60, 160, 30.0],
        'long' => [160, 260, 16.0],
        'very_long' => [260, 420, 6.0],
    ];

    /**
     * Draws a word target for one reply.
     *
     * @return array{bucket: string, words: int}
     */
    public static function draw(Rng $rng): array
    {
        $weights = [];

        foreach (self::BUCKETS as $label => [, , $weight]) {
            $weights[$label] = $weight;
        }

        $bucket = (string) $rng->weightedKey($weights);
        [$min, $max] = self::BUCKETS[$bucket];

        return ['bucket' => $bucket, 'words' => $rng->int($min, $max)];
    }

    /**
     * The instruction handed to the model for a given target.
     *
     * Expressed as a loose range around the target rather than an exact count:
     * an exact number makes models pad or truncate to hit it.
     */
    public static function instruction(int $words): string
    {
        $low = max(5, (int) round($words * 0.75));
        $high = (int) round($words * 1.25);

        return "roughly $low to $high words";
    }
}
