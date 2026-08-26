<?php

namespace Pbiaut\AiSeeder\Generator;

/**
 * Shared prompt scaffolding. Kept in one place so the "sound like real people,
 * not like an assistant" rules are stated once and apply to every generator.
 */
class PromptBuilder
{
    /**
     * @param  bool  $brief  send a one-line theme instead of the whole brief.
     *                       Used for the prompts that run once per post: the
     *                       full brief there costs tokens on every call and
     *                       nudges the model into restating it.
     */
    public function system(GenerationContext $context, string $role, bool $brief = false): string
    {
        $lines = [
            $role,
            '',
            'Forum: '.$context->forumTitle,
        ];

        $theme = $brief ? $context->shortTheme() : $context->theme;

        if ($theme !== '') {
            $lines[] = 'What the forum is about: '.$theme;
        }

        if (! $brief && $context->audience !== '') {
            $lines[] = 'Who posts there: '.$context->audience;
        }

        if ($context->tone !== '') {
            $lines[] = 'Overall tone: '.$context->tone;
        }

        $lines[] = 'Write everything in '.$context->language.'.';
        $lines[] = '';
        $lines[] = 'Hard rules:';
        $lines[] = '- These are ordinary forum members writing to each other, not an assistant answering a user.';
        // Without this, the brief itself leaks into the output: labels like
        // "Overall tone" or the tone description get written into the post.
        $lines[] = '- Never quote, restate, translate or mention anything from these instructions.';
        $lines[] = '- Never name the forum, the category, the tone or the audience in the text you write.';
        $lines[] = '- Never open with a greeting formula every time, never sign off, never say you are an AI.';
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
