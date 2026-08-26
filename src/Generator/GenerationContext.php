<?php

namespace Pbiaut\AiSeeder\Generator;

/**
 * Everything the model needs to know about the forum it is writing for.
 */
final class GenerationContext
{
    public function __construct(
        public string $forumTitle,
        public string $language,
        public string $theme,
        public string $tone,
        public string $audience,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, string $forumTitle = 'the forum'): self
    {
        return new self(
            forumTitle: self::text($config, 'forum_title', $forumTitle),
            language: self::text($config, 'language', 'English'),
            theme: self::text($config, 'theme', ''),
            tone: self::text($config, 'tone', 'casual and helpful'),
            audience: self::text($config, 'audience', ''),
        );
    }

    /**
     * A one-line version of the theme.
     *
     * The full text is worth sending when choosing what threads to open, but
     * repeating a long brief on every single post and reply wastes tokens and,
     * worse, pushes the model towards restating it instead of writing.
     */
    public function shortTheme(int $max = 200): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $this->theme) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line !== '') {
                return mb_strlen($line) > $max ? mb_substr($line, 0, $max).'...' : $line;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function text(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
