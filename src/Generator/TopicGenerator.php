<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;

/**
 * Generates discussion titles in bulk. Titles already produced for the batch
 * are fed back in as "do not repeat these", which is what stops a 200-thread
 * forum from ending up with fifteen variations of the same question.
 */
class TopicGenerator
{
    public const BATCH_SIZE = 20;

    /** How many previous titles to show the model (keeps the prompt bounded). */
    public const CONTEXT_TITLES = 80;

    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
    ) {
    }

    /**
     * @param  array<int, string|null>  $tagNames  one entry per requested title, null when untagged
     * @param  array<int, string>  $existingTitles
     * @return array<int, string>  same order and length as $tagNames
     *
     * @throws OpenAiException
     */
    public function generate(array $tagNames, GenerationContext $context, array $existingTitles = [], ?string $model = null): array
    {
        $count = count($tagNames);

        if ($count === 0) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You invent the kind of thread titles real members actually open on a forum.'
        );

        $slots = [];

        foreach (array_values($tagNames) as $index => $tag) {
            $slots[] = ($index + 1).'. '.($tag === null || $tag === '' ? 'no particular category' : 'category: '.$tag);
        }

        $recent = array_slice($existingTitles, -self::CONTEXT_TITLES);

        $user = implode("\n", array_filter([
            "Write $count discussion titles, one per slot below, in the same order.",
            '',
            implode("\n", $slots),
            '',
            'Mix the intents the way a real forum does: questions, calls for help, bug reports, opinions,',
            'showing off a result, comparisons, newcomer introductions, small announcements.',
            'Titles are short (4 to 12 words), written the way a member types them, sometimes with a question mark,',
            'sometimes lowercase, never in title case, never clickbait, never starting with a number list.',
            '',
            $recent === [] ? null : "These titles already exist in this forum, produce nothing similar:\n- ".implode("\n- ", $recent),
            '',
            'Answer as {"titles": ["...", "..."]} with exactly '.$count.' entries.',
        ]));

        $response = $this->client->chatJson($system, $user, $model);
        $raw = $response['titles'] ?? $response['topics'] ?? [];

        if (! is_array($raw) || $raw === []) {
            throw new OpenAiException('The model returned no titles.', 0, true);
        }

        $titles = [];

        foreach ($raw as $entry) {
            $title = is_array($entry) ? ($entry['title'] ?? '') : $entry;

            if (! is_scalar($title)) {
                continue;
            }

            $title = $this->clean((string) $title);

            if ($title !== '') {
                $titles[] = $title;
            }
        }

        if ($titles === []) {
            throw new OpenAiException('The model returned no usable titles.', 0, true);
        }

        // Pad defensively: a short answer must not stall the whole batch.
        while (count($titles) < $count) {
            $titles[] = $titles[count($titles) % max(1, count($titles))].' ('.(count($titles) + 1).')';
        }

        return array_slice($titles, 0, $count);
    }

    protected function clean(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = trim($title, "\"'“”«» \t");
        // Strip a leading "12. " numbering the model sometimes keeps.
        $title = preg_replace('/^\d+[.)]\s*/', '', $title) ?? $title;

        return mb_substr($title, 0, 180);
    }
}
