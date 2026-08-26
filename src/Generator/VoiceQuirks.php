<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\Planner\Rng;

/**
 * Per-member writing habits, applied in PHP rather than asked of the model.
 *
 * A model cannot hold a mannerism for two hundred messages: told "this member
 * never uses capitals", it complies for three posts and drifts back. PHP holds
 * it forever. That is what breaks the uniformity of voice that survives every
 * prompt improvement - the last real tell of generated content.
 *
 * Each member keeps their quirks for the whole run, drawn once with their
 * persona. Most members have none: a forum where everybody writes oddly is just
 * a different kind of uniform.
 */
class VoiceQuirks
{
    public const LOWERCASE = 'lowercase';
    public const NO_ACCENTS = 'no_accents';
    public const MOBILE = 'mobile';
    public const ELLIPSIS = 'ellipsis';
    public const CAPS_EMPHASIS = 'caps_emphasis';
    public const NO_FINAL_PERIOD = 'no_final_period';
    public const DOUBLE_SPACE = 'double_space';
    public const EMOJI_SIGN = 'emoji_sign';
    public const TYPOS = 'typos';

    /** @var array<string, float> */
    private const WEIGHTS = [
        self::LOWERCASE => 10.0,
        self::NO_ACCENTS => 8.0,
        self::MOBILE => 10.0,
        self::ELLIPSIS => 9.0,
        self::CAPS_EMPHASIS => 7.0,
        self::NO_FINAL_PERIOD => 9.0,
        self::DOUBLE_SPACE => 5.0,
        self::EMOJI_SIGN => 6.0,
        self::TYPOS => 8.0,
    ];

    /** Share of members who simply write normally. */
    private const PLAIN_SHARE = 0.45;

    private const SMILEYS = [' :)', ' ;)', ' :-)', ' ^^'];

    /**
     * Draws the habits of one member.
     *
     * @return array<int, string>
     */
    public static function draw(Rng $rng): array
    {
        if ($rng->bool(self::PLAIN_SHARE)) {
            return [];
        }

        $weights = self::WEIGHTS;
        $quirks = [];
        $wanted = $rng->bool(0.75) ? 1 : 2;

        while (count($quirks) < $wanted && $weights !== []) {
            $key = $rng->weightedKey($weights);

            if ($key === null) {
                break;
            }

            $quirks[] = (string) $key;
            unset($weights[(string) $key]);
        }

        // Lowercasing and stripping accents are each strong on their own;
        // together they read as broken rather than as a person.
        if (in_array(self::LOWERCASE, $quirks, true) && in_array(self::NO_ACCENTS, $quirks, true)) {
            array_pop($quirks);
        }

        return $quirks;
    }

    /**
     * @param  array<int, string>  $quirks
     */
    public function apply(string $text, array $quirks, Rng $rng): string
    {
        if ($quirks === [] || trim($text) === '') {
            return $text;
        }

        [$masked, $vault] = $this->mask($text);

        foreach ($quirks as $quirk) {
            $masked = match ($quirk) {
                self::LOWERCASE => $this->lowercase($masked),
                self::NO_ACCENTS => $this->stripAccents($masked),
                self::MOBILE => $this->mobile($masked, $rng),
                self::ELLIPSIS => $this->ellipsis($masked, $rng),
                self::CAPS_EMPHASIS => $this->capsEmphasis($masked, $rng),
                self::NO_FINAL_PERIOD => $this->dropFinalPeriod($masked),
                self::DOUBLE_SPACE => $this->doubleSpace($masked),
                self::EMOJI_SIGN => $this->sign($masked, $rng),
                self::TYPOS => $this->typos($masked, $rng),
                default => $masked,
            };
        }

        return $this->unmask($masked, $vault);
    }

    /**
     * Hides everything a transformation must not touch: fenced and inline code,
     * mentions, URLs and quoted lines. Lowercasing a mention or stripping the
     * accents out of a URL would break them outright.
     *
     * @return array{string, array<int, string>}
     */
    private function mask(string $text): array
    {
        $vault = [];

        $patterns = [
            '/```.*?```/s',                 // fenced code
            '/`[^`\n]+`/',                  // inline code
            '/@"[^"]+"#\d+/u',              // user mentions
            '/@[a-z0-9_-]+#p?\d+/iu',       // post and legacy mentions
            '/https?:\/\/\S+/i',            // links
            '/^\s*>.*$/m',                  // quoted lines
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $match) use (&$vault): string {
                $vault[] = $match[0];

                return "\x01".(count($vault) - 1)."\x02";
            }, $text) ?? $text;
        }

        return [$text, $vault];
    }

    /**
     * @param  array<int, string>  $vault
     */
    private function unmask(string $text, array $vault): string
    {
        foreach ($vault as $index => $original) {
            $text = str_replace("\x01".$index."\x02", $original, $text);
        }

        return $text;
    }

    private function lowercase(string $text): string
    {
        return mb_strtolower($text);
    }

    private function stripAccents(string $text): string
    {
        $from = ['à', 'â', 'ä', 'á', 'ã', 'å', 'ç', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'í', 'ì', 'ô', 'ö', 'ò', 'ó', 'õ', 'û', 'ü', 'ù', 'ú', 'ÿ', 'ñ', 'œ', 'æ'];
        $to = ['a', 'a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'n', 'oe', 'ae'];

        $text = str_replace($from, $to, $text);

        return str_replace(
            array_map('mb_strtoupper', $from),
            array_map('mb_strtoupper', $to),
            $text
        );
    }

    /** Typed with a thumb: no apostrophes, commas dropped, lines run short. */
    private function mobile(string $text, Rng $rng): string
    {
        $text = str_replace(['’', "'"], ['', ''], $text);
        $text = preg_replace_callback('/,\s/', fn () => $rng->bool(0.6) ? ' ' : ', ', $text) ?? $text;

        return preg_replace('/\n{2,}/', "\n", $text) ?? $text;
    }

    private function ellipsis(string $text, Rng $rng): string
    {
        return preg_replace_callback(
            '/(?<=[a-zà-ÿ0-9])([.,])(\s+)(?=[A-Za-zÀ-ÿ])/u',
            fn (array $m) => $rng->bool(0.4) ? '...'.$m[2] : $m[1].$m[2],
            $text
        ) ?? $text;
    }

    private function capsEmphasis(string $text, Rng $rng): string
    {
        return preg_replace_callback('/\b([a-zà-ÿ]{5,})\b/u', function (array $m) use ($rng) {
            return $rng->bool(0.035) ? mb_strtoupper($m[1]) : $m[1];
        }, $text) ?? $text;
    }

    private function dropFinalPeriod(string $text): string
    {
        return preg_replace('/\.\s*$/', '', rtrim($text)) ?? $text;
    }

    private function doubleSpace(string $text): string
    {
        return preg_replace('/([.!?])\s(?=[A-ZÀ-Ÿ])/u', '$1  ', $text) ?? $text;
    }

    private function sign(string $text, Rng $rng): string
    {
        $smiley = self::SMILEYS[$rng->int(0, count(self::SMILEYS) - 1)];

        return rtrim($text).$smiley;
    }

    /** The occasional slip: a doubled letter, or two swapped. */
    private function typos(string $text, Rng $rng): string
    {
        return preg_replace_callback('/\b([a-zà-ÿ]{5,})\b/u', function (array $m) use ($rng) {
            if (! $rng->bool(0.022)) {
                return $m[1];
            }

            $word = $m[1];
            $length = mb_strlen($word);
            $at = $rng->int(1, $length - 2);

            if ($rng->bool()) {
                // Doubled letter.
                return mb_substr($word, 0, $at).mb_substr($word, $at, 1).mb_substr($word, $at);
            }

            // Two letters swapped.
            return mb_substr($word, 0, $at)
                .mb_substr($word, $at + 1, 1)
                .mb_substr($word, $at, 1)
                .mb_substr($word, $at + 2);
        }, $text) ?? $text;
    }
}
