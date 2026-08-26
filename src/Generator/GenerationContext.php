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
     * @param  array<string, mixed>  $config
     */
    private static function text(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
