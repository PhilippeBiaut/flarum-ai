<?php

namespace Pbiaut\AiSeeder\Planner;

/**
 * What kind of reply this is.
 *
 * Left to itself a model writes the same helpful-paragraph reply every time.
 * Naming the intent up front is what produces a thread where one person answers,
 * another asks for details, a third corrects them and someone reports back.
 *
 * The weights keep useful replies firmly in the majority: the point of a thread
 * is that the question gets answered. Tone-driven types stay a garnish.
 */
final class ReplyType
{
    /** Answers the opening post directly and concretely. */
    public const ANSWER = 'answer';

    /** "Here is what happened when I did it." */
    public const EXPERIENCE = 'experience';

    /** Technical, precise, authoritative. */
    public const EXPERT = 'expert';

    /** Suggests a different approach entirely. */
    public const ALTERNATIVE = 'alternative';

    /** Answers part of it, or gives a lead without solving it. */
    public const PARTIAL = 'partial';

    /** Points at a doc, a tool or an earlier thread. */
    public const RESOURCE = 'resource';

    /** Something is missing before anyone can answer. */
    public const CLARIFY = 'clarify';

    /** "Same here." Two lines at most. */
    public const AGREE = 'agree';

    /** Pushes back, with a reason. */
    public const DISAGREE = 'disagree';

    /** Corrects a mistake in the message it answers. */
    public const CORRECTION = 'correction';

    /** "Tried it - works" or "still failing on X". */
    public const FOLLOWUP = 'followup';

    /** Closes the loop, usually from the thread's author. */
    public const THANKS = 'thanks';

    /** Blunt, no diplomacy, still useful. */
    public const INCISIVE = 'incisive';

    /** A short joke, then back to business. */
    public const HUMOUR = 'humour';

    /** Light teasing. Kept rare: it ages badly. */
    public const TEASING = 'teasing';

    /** Doubts the premise itself. */
    public const SKEPTICAL = 'skeptical';

    /** Fixates on a detail nobody asked about. */
    public const PEDANTIC = 'pedantic';

    /**
     * Default weights. Useful types 60%, conversational 28%, tone 12%.
     *
     * @var array<string, float>
     */
    public const WEIGHTS = [
        self::ANSWER => 18.0,
        self::EXPERIENCE => 14.0,
        self::EXPERT => 10.0,
        self::ALTERNATIVE => 8.0,
        self::PARTIAL => 6.0,
        self::RESOURCE => 4.0,

        self::CLARIFY => 7.0,
        self::AGREE => 7.0,
        self::DISAGREE => 5.0,
        self::CORRECTION => 4.0,
        self::FOLLOWUP => 3.0,
        self::THANKS => 2.0,

        self::INCISIVE => 4.0,
        self::HUMOUR => 3.0,
        self::TEASING => 2.0,
        self::SKEPTICAL => 2.0,
        self::PEDANTIC => 1.0,
    ];

    /** Only make sense once there is something to react to. */
    private const NEEDS_A_PREVIOUS_REPLY = [
        self::CORRECTION,
        self::DISAGREE,
        self::AGREE,
        self::PEDANTIC,
    ];

    /** Belong to the tail of a thread, not its opening exchanges. */
    private const LATE_ONLY = [
        self::FOLLOWUP,
        self::THANKS,
    ];

    /** Only the thread's own author reports back or thanks. */
    private const AUTHOR_ONLY = [
        self::FOLLOWUP,
        self::THANKS,
    ];

    /**
     * Draws a type for one reply, given where it sits and what it answers.
     *
     * @param  int  $position  0-based index of the reply in the thread
     * @param  int  $total  how many replies the thread will have
     * @param  bool  $answersAReply  false when it answers the opening post
     * @param  bool  $byThreadAuthor  true when written by whoever opened the thread
     * @param  array<string, float>  $weights  overrides, e.g. from an archetype
     */
    public static function draw(
        int $position,
        int $total,
        bool $answersAReply,
        bool $byThreadAuthor,
        Rng $rng,
        array $weights = [],
    ): string {
        $pool = $weights === [] ? self::WEIGHTS : $weights;
        $isLate = $total <= 2 || $position >= (int) floor($total * 0.55);

        foreach (array_keys($pool) as $type) {
            $blocked = (! $answersAReply && in_array($type, self::NEEDS_A_PREVIOUS_REPLY, true))
                || (! $isLate && in_array($type, self::LATE_ONLY, true))
                || (! $byThreadAuthor && in_array($type, self::AUTHOR_ONLY, true));

            if ($blocked) {
                unset($pool[$type]);
            }
        }

        // The thread's author coming back is nearly always reporting back or
        // saying thanks; anything else from them reads as talking to themselves.
        if ($byThreadAuthor && $isLate) {
            $pool = array_intersect_key($pool, array_flip([self::FOLLOWUP, self::THANKS, self::CLARIFY, self::ANSWER]));
        }

        if ($pool === []) {
            return self::ANSWER;
        }

        $key = $rng->weightedKey($pool);

        return $key === null ? self::ANSWER : (string) $key;
    }

    /**
     * The line handed to the model for a given type.
     */
    public static function instruction(string $type): string
    {
        return match ($type) {
            self::EXPERIENCE => 'share what happened when you did this yourself: the setup, the result, what surprised you',
            self::EXPERT => 'answer with real technical depth and precision, including the caveat a beginner would miss',
            self::ALTERNATIVE => 'suggest a different approach than the one being discussed, and say what it costs',
            self::PARTIAL => 'answer only the part you actually know, and say plainly which part you do not',
            self::RESOURCE => 'point at a specific document, tool or earlier thread, and say what to look for in it',
            self::CLARIFY => 'ask for the one piece of information missing before anyone can answer. Keep it short',
            self::AGREE => 'confirm briefly that you saw the same thing. Two sentences at most, no restating',
            self::DISAGREE => 'disagree with what that message says and give the concrete reason why',
            self::CORRECTION => 'correct a specific mistake in the message you answer, politely and without lecturing',
            self::FOLLOWUP => 'report back on what you tried and what it did. Say plainly if it still does not work',
            self::THANKS => 'thank whoever solved it and say what actually worked. Short',
            self::INCISIVE => 'be blunt and go straight to the point, no softening, but stay useful and civil',
            self::HUMOUR => 'make one light joke about the situation, then give a real answer anyway',
            self::TEASING => 'tease gently, the way regulars do with each other, then help',
            self::SKEPTICAL => 'question whether the problem is really what it is assumed to be',
            self::PEDANTIC => 'pick up on a small detail nobody asked about. Own that it is beside the point',
            default => 'answer the question directly and concretely',
        };
    }

    /**
     * Validates and normalises admin-supplied weights.
     *
     * @param  mixed  $given
     * @return array<string, float>  empty when nothing usable was given
     */
    public static function weightsFrom(mixed $given): array
    {
        if (! is_array($given)) {
            return [];
        }

        $weights = [];

        foreach (self::WEIGHTS as $type => $default) {
            $value = $given[$type] ?? null;

            if (is_numeric($value)) {
                $weights[$type] = max(0.0, (float) $value);
            }
        }

        // All zeroes would leave nothing to draw from.
        return array_sum($weights) > 0 ? $weights : [];
    }
}
