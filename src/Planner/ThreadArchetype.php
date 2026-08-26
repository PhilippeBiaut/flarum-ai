<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * The shape of a thread.
 *
 * Real forums do not produce one kind of thread. Some questions get two answers
 * and die; some turn into a long back-and-forth between the author and one
 * helper; some opinions attract thirty short takes. Giving every thread the
 * same structure is what makes a generated forum feel flat even when each
 * individual message reads well.
 *
 * An archetype biases how many replies a thread gets, how much its author comes
 * back, and which kinds of reply dominate.
 */
final class ThreadArchetype
{
    /** Nobody answers. Every forum is full of these. */
    public const DEAD = 'dead';

    /** Asked, answered, done. */
    public const QUICK = 'quick';

    /** Long back-and-forth, mostly the author and one or two helpers. */
    public const TROUBLESHOOT = 'troubleshoot';

    /** Everyone has a take, most of them short. */
    public const OPINION = 'opinion';

    /** Something to react to rather than solve. */
    public const SHOWCASE = 'showcase';

    /**
     * name => [relative weight, reply-count multiplier, author-return share, type bias]
     *
     * @var array<string, array{float, float, float, array<string, float>}>
     */
    public const ARCHETYPES = [
        self::DEAD => [22.0, 0.0, 0.0, []],
        self::QUICK => [30.0, 0.35, 0.12, [
            ReplyType::ANSWER => 30.0,
            ReplyType::EXPERIENCE => 14.0,
            ReplyType::EXPERT => 12.0,
            ReplyType::RESOURCE => 8.0,
            ReplyType::CLARIFY => 8.0,
            ReplyType::THANKS => 10.0,
            ReplyType::PARTIAL => 6.0,
            ReplyType::ALTERNATIVE => 6.0,
            ReplyType::AGREE => 6.0,
        ]],
        self::TROUBLESHOOT => [22.0, 1.9, 0.24, [
            ReplyType::CLARIFY => 16.0,
            ReplyType::ANSWER => 16.0,
            ReplyType::EXPERT => 14.0,
            ReplyType::FOLLOWUP => 14.0,
            ReplyType::PARTIAL => 10.0,
            ReplyType::ALTERNATIVE => 8.0,
            ReplyType::CORRECTION => 6.0,
            ReplyType::EXPERIENCE => 6.0,
            ReplyType::THANKS => 6.0,
            ReplyType::SKEPTICAL => 4.0,
        ]],
        self::OPINION => [16.0, 1.6, 0.07, [
            ReplyType::EXPERIENCE => 20.0,
            ReplyType::DISAGREE => 16.0,
            ReplyType::AGREE => 14.0,
            ReplyType::ANSWER => 10.0,
            ReplyType::INCISIVE => 10.0,
            ReplyType::ALTERNATIVE => 8.0,
            ReplyType::SKEPTICAL => 6.0,
            ReplyType::HUMOUR => 6.0,
            ReplyType::TEASING => 4.0,
            ReplyType::PEDANTIC => 3.0,
            ReplyType::EXPERT => 3.0,
        ]],
        self::SHOWCASE => [10.0, 0.9, 0.15, [
            ReplyType::AGREE => 22.0,
            ReplyType::EXPERIENCE => 16.0,
            ReplyType::CLARIFY => 14.0,
            ReplyType::HUMOUR => 10.0,
            ReplyType::EXPERT => 10.0,
            ReplyType::ALTERNATIVE => 8.0,
            ReplyType::TEASING => 6.0,
            ReplyType::ANSWER => 6.0,
            ReplyType::THANKS => 5.0,
            ReplyType::PEDANTIC => 3.0,
        ]],
    ];

    public static function draw(Rng $rng, float $deadShare = 0.22): string
    {
        $weights = [];

        foreach (self::ARCHETYPES as $name => [$weight]) {
            $weights[$name] = $name === self::DEAD ? max(0.0, $deadShare * 100) : $weight;
        }

        $key = $rng->weightedKey($weights);

        return $key === null ? self::QUICK : (string) $key;
    }

    /** How the archetype stretches or shrinks the thread's reply count. */
    public static function multiplier(string $archetype): float
    {
        return self::ARCHETYPES[$archetype][1] ?? 1.0;
    }

    /** Share of the thread's replies written by whoever opened it. */
    public static function authorShare(string $archetype): float
    {
        return self::ARCHETYPES[$archetype][2] ?? 0.0;
    }

    /**
     * @return array<string, float>
     */
    public static function typeWeights(string $archetype): array
    {
        return self::ARCHETYPES[$archetype][3] ?? [];
    }
}
