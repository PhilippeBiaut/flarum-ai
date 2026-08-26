<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * Which message each reply is answering.
 *
 * On a real thread, replies do not all address the opening post. Some answer
 * another member, pick up a detail three messages back, or correct what was
 * just said. Deciding this at planning time, rather than leaving the model to
 * improvise, is what turns a column of parallel answers into a conversation -
 * and it stays reproducible for a given seed.
 *
 * The value is an index into the thread: 0 is the opening post, 1 is the first
 * reply, 2 the second, and so on.
 */
final class ReplyTarget
{
    /** Share of replies that answer the opening post rather than each other. */
    private const OPENING_POST_SHARE = 0.62;

    /**
     * @param  int  $position  0-based index of the reply being placed
     * @return int  index of the message it answers (0 = the opening post)
     */
    public static function draw(int $position, Rng $rng): int
    {
        // The first reply has nothing else to answer.
        if ($position <= 0) {
            return 0;
        }

        if ($rng->bool(self::OPENING_POST_SHARE)) {
            return 0;
        }

        // Otherwise answer an earlier reply, strongly favouring recent ones:
        // conversations react to what was just said far more often than to
        // something buried twenty messages up.
        $weights = [];

        for ($index = 1; $index <= $position; $index++) {
            $distance = $position - $index + 1;
            $weights[$index] = 1.0 / $distance;
        }

        $key = $rng->weightedKey($weights);

        return $key === null ? 0 : (int) $key;
    }
}
