<?php

namespace Pbiaut\AiSeeder\Generator;

/**
 * Rejects replies that would embarrass the forum.
 *
 * Two problems this solves. The obvious one is near-duplicates: models asked
 * for a dozen replies to the same post produce the same answer twice, in
 * slightly different words. The other is assistant tells - "I hope this helps",
 * a markdown heading, the forum's own name - which no real member writes.
 *
 * Rejected slots are regenerated rather than filled with a copy of a sibling,
 * which is what an earlier version did and what put duplicate replies on the
 * forum in the first place.
 */
class ReplyQuality
{
    /** Two replies above this similarity are treated as the same reply. */
    public const SIMILARITY = 0.72;

    /** Substrings that give away an assistant rather than a member. */
    private const TELLS = [
        'as an ai', 'as a language model', 'en tant qu', "je suis une ia",
        'i hope this helps', "j'espère que ça aide", "j'espere que ca aide",
        'hope that helps', 'feel free to ask', "n'hésitez pas à demander",
        'great question', 'excellente question', 'bonne question',
        'in conclusion', 'en conclusion', 'to summarize', 'pour résumer',
    ];

    /**
     * @param  array<int, string>  $others  the other replies of the same thread
     * @return string|null  why it was rejected, or null when it is fine
     */
    public function reject(string $reply, array $others): ?string
    {
        $trimmed = trim($reply);

        if ($trimmed === '') {
            return 'empty';
        }

        if (mb_strlen($trimmed) < 15) {
            return 'too short to be a real reply';
        }

        // Markdown headings and rules: forum members do not write those.
        if (preg_match('/^\s{0,3}#{1,6}\s/m', $trimmed) === 1) {
            return 'contains a markdown heading';
        }

        if (preg_match('/^\s{0,3}(-{3,}|\*{3,}|_{3,})\s*$/m', $trimmed) === 1) {
            return 'contains a horizontal rule';
        }

        $lower = mb_strtolower($trimmed);

        foreach (self::TELLS as $tell) {
            if (str_contains($lower, $tell)) {
                return 'contains an assistant tell: "'.$tell.'"';
            }
        }

        $normalised = self::normalise($trimmed);

        foreach ($others as $other) {
            if (self::similarity($normalised, self::normalise($other)) >= self::SIMILARITY) {
                return 'nearly identical to another reply in the thread';
            }
        }

        return null;
    }

    /**
     * Word-level Jaccard similarity.
     *
     * Chosen over similar_text(), which is O(n^2) and would be run for every
     * pair of replies in a thread; on the lengths involved here it is both
     * faster and better at catching "same answer, reworded".
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $wordsA = array_unique(explode(' ', $a));
        $wordsB = array_unique(explode(' ', $b));

        $shared = count(array_intersect($wordsA, $wordsB));
        $union = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union === 0 ? 0.0 : $shared / $union;
    }

    /** Lowercase, unpunctuated, single-spaced: compares meaning, not typography. */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
