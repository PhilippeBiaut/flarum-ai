<?php

namespace Pbiaut\AiSeeder\Generator;

/**
 * Shared prompt scaffolding. Kept in one place so the "sound like real people,
 * not like an assistant" rules are stated once and apply to every generator.
 */
class PromptBuilder
{
    public function system(GenerationContext $context, string $role): string
    {
        $lines = [
            $role,
            '',
            'Forum: '.$context->forumTitle,
        ];

        if ($context->theme !== '') {
            $lines[] = 'What the forum is about: '.$context->theme;
        }

        if ($context->audience !== '') {
            $lines[] = 'Who posts there: '.$context->audience;
        }

        $lines[] = 'Overall tone: '.$context->tone;
        $lines[] = 'Write everything in '.$context->language.'.';
        $lines[] = '';
        $lines[] = 'Hard rules:';
        $lines[] = '- These are ordinary forum members writing to each other, not an assistant answering a user.';
        $lines[] = '- Never open with a greeting formula every time, never sign off, never say you are an AI.';
        $lines[] = '- Vary length a lot: some messages are one line, some are several paragraphs.';
        $lines[] = '- Avoid bullet-point-heavy, perfectly balanced, "here are 5 tips" writing. Real people ramble a little.';
        $lines[] = '- Light Flarum-flavoured Markdown only when it is natural: **bold**, > quotes, simple lists, `code`.';
        $lines[] = '- No headings, no horizontal rules, no tables.';
        $lines[] = '- Occasional informality, typos or self-corrections are welcome, but stay readable.';
        $lines[] = '- Answer with JSON only, matching the requested shape exactly.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    public function describePersona(array $persona, string $label = 'Author'): string
    {
        $parts = [$label.': '.($persona['display_name'] ?? $persona['username'] ?? 'a member')];

        if (! empty($persona['bio'])) {
            $parts[] = 'Background: '.$persona['bio'];
        }

        if (! empty($persona['voice'])) {
            $parts[] = 'Writing style: '.$persona['voice'];
        }

        if (! empty($persona['interests']) && is_array($persona['interests'])) {
            $parts[] = 'Interests: '.implode(', ', array_map('strval', $persona['interests']));
        }

        return implode("\n", $parts);
    }
}
