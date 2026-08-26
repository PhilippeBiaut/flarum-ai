<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;

/**
 * Generates every reply of a thread in a single call.
 *
 * This is both the cheap option (one call instead of one per reply) and the
 * good one: the model sees the whole conversation it is writing, so people
 * actually answer each other, disagree, correct themselves and close the loop,
 * instead of producing N independent comments on the same opening post.
 */
class ReplyBundleGenerator
{
    /** Beyond this, a thread is generated in several successive calls. */
    public const MAX_PER_CALL = 12;

    public function __construct(
        protected Client $client,
        protected PromptBuilder $prompts,
    ) {
    }

    /**
     * @param  array<string, mixed>  $opPersona
     * @param  array<int, array<string, mixed>>  $personas  one per reply, in order
     * @param  array<int, array{author: string, content: string}>  $alreadyWritten  earlier replies of the same thread
     * @return array<int, string>  one body per requested reply
     *
     * @throws OpenAiException
     */
    public function generate(
        string $title,
        string $openingPost,
        array $opPersona,
        array $personas,
        GenerationContext $context,
        array $alreadyWritten = [],
        ?string $model = null,
    ): array {
        $count = count($personas);

        if ($count === 0) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You write a whole forum thread: several members replying to each other over time.'
        );

        $participants = [];

        foreach (array_values($personas) as $index => $persona) {
            $participants[] = 'Reply '.($index + 1).' is written by '.($persona['display_name'] ?? $persona['username'] ?? 'a member')
                ."\n".$this->prompts->describePersona($persona, 'Member');
        }

        $transcript = [];

        foreach ($alreadyWritten as $message) {
            $transcript[] = $message['author'].': '.$message['content'];
        }

        $user = implode("\n", array_filter([
            'Thread title: '.$title,
            '',
            $this->prompts->describePersona($opPersona, 'Opening post written by'),
            'Opening post:',
            '"""',
            $openingPost,
            '"""',
            '',
            $transcript === [] ? null : "Replies already posted in this thread:\n\"\"\"\n".implode("\n\n", $transcript)."\n\"\"\"\n",
            "Now write the next $count replies, in order, each by the member listed below:",
            '',
            implode("\n\n", $participants),
            '',
            'Make it a real conversation: they answer the opening post AND each other, quote each other occasionally',
            'with "> ", partially disagree, ask a follow-up, report back later ("tried it, still not working"),',
            'and at least one reply should be very short. Do not summarise, do not conclude politely.',
            'Never mention dates or how much time has passed.',
            '',
            'Answer as {"replies": [{"content": "..."}, ...]} with exactly '.$count.' entries, in order.',
        ]));

        $response = $this->client->chatJson($system, $user, $model);
        $raw = $response['replies'] ?? $response['posts'] ?? [];

        if (! is_array($raw) || $raw === []) {
            throw new OpenAiException('The model returned no replies.', 0, true);
        }

        $replies = [];

        foreach ($raw as $entry) {
            $content = is_array($entry) ? ($entry['content'] ?? $entry['body'] ?? '') : $entry;

            if (is_string($content) && trim($content) !== '') {
                $replies[] = trim($content);
            }
        }

        if ($replies === []) {
            throw new OpenAiException('The model returned no usable replies.', 0, true);
        }

        // A short answer must not stall the batch: reuse the tail rather than fail.
        while (count($replies) < $count) {
            $replies[] = $replies[count($replies) % count($replies)];
        }

        return array_slice($replies, 0, $count);
    }
}
