<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * Models how long after the opening post replies actually arrive on a real
 * forum: a burst in the first day, a tail over the following days.
 *
 * Roughly 60% within 24h, 85% within 72h, the rest spread over the window.
 */
final class ReplyCurve
{
    private const DAY = 86400;

    /**
     * @return array<int, int>  ascending delays in seconds
     */
    public static function delays(int $count, Rng $rng, int $windowDays): array
    {
        if ($count <= 0) {
            return [];
        }

        $window = max(3600, $windowDays * self::DAY);
        $delays = [];

        for ($i = 0; $i < $count; $i++) {
            $bucket = $rng->float();

            if ($bucket < 0.60) {
                // First 24 hours, heavily front-loaded.
                $delay = self::DAY * pow($rng->float(), 2.2);
            } elseif ($bucket < 0.85) {
                // 24h to 72h.
                $delay = self::DAY + 2 * self::DAY * $rng->float();
            } else {
                // Long tail up to the end of the window.
                $tail = max(0.0, $window - 3 * self::DAY);
                $delay = 3 * self::DAY + $tail * pow($rng->float(), 1.6);
            }

            $delays[] = (int) round(min($window, max(60.0, $delay)));
        }

        sort($delays);

        return $delays;
    }
}
