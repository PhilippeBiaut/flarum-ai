<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;

/**
 * Sorts discussions that already exist into the forum's tag list.
 *
 * A whole batch of threads is classified in one call: it is far cheaper, and
 * seeing them side by side gives the model a sense of the boundaries between
 * categories that it does not get one thread at a time.
 *
 * The model may only pick from the paths it is given, or answer null. It is
 * never asked to invent a category, because the admin's list is the taxonomy.
 */
class TagClassifier
{
    public const BATCH_SIZE = 20;

    /** Characters of the opening post shown to the model per thread. */
    private const EXCERPT = 500;

    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
    ) {
    }

    /**
     * @param  array<int, array{id: int, title: string, excerpt: string}>  $discussions
     * @param  array<int, string>  $paths  the allowed tag paths
     * @return array<int, string|null>  discussion id => chosen path, or null
     *
     * @throws OpenAiException
     */
    public function classify(array $discussions, array $paths, GenerationContext $context, ?string $model = null): array
    {
        if ($discussions === [] || $paths === []) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You sort existing forum threads into the categories this forum uses.',
            PromptBuilder::BRIEF
        );

        $threads = [];

        foreach (array_values($discussions) as $index => $discussion) {
            $threads[] = ($index + 1).'. '.$discussion['title']."\n   "
                .mb_substr(trim(preg_replace('/\s+/u', ' ', $discussion['excerpt']) ?? ''), 0, self::EXCERPT);
        }

        $user = implode("\n", [
            'Available categories, one per line:',
            implode("\n", $paths),
            '',
            'Classify each thread below into exactly one of those categories, copied verbatim.',
            'Judge by what the thread is really about, not by a keyword appearing in the title.',
            'If none of the categories genuinely fits, answer null for that thread rather than forcing it.',
            'Never invent a category that is not in the list above.',
            '',
            implode("\n", $threads),
            '',
            'Answer as {"assignments": [{"n": 1, "category": "..."}, {"n": 2, "category": null}]},',
            'one entry per thread, using the numbers above.',
        ]);

        $response = $this->client->chatJson($system, $user, $model);
        $assignments = $response['assignments'] ?? $response['results'] ?? [];

        if (! is_array($assignments)) {
            throw new OpenAiException('The model returned no assignments.', 0, true);
        }

        // Case-insensitive lookup, so a difference in capitalisation does not
        // throw away an otherwise correct answer.
        $allowed = [];

        foreach ($paths as $path) {
            $allowed[mb_strtolower($path)] = $path;
        }

        $ids = array_column(array_values($discussions), 'id');
        $result = [];

        foreach ($assignments as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $number = (int) ($assignment['n'] ?? $assignment['number'] ?? 0);
            $category = $assignment['category'] ?? $assignment['tag'] ?? null;

            if ($number < 1 || $number > count($ids)) {
                continue;
            }

            $id = $ids[$number - 1];

            if (! is_string($category) || trim($category) === '') {
                $result[$id] = null;
                continue;
            }

            $result[$id] = $allowed[mb_strtolower(trim($category))] ?? null;
        }

        return $result;
    }
}
