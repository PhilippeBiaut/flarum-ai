<?php

namespace Pbiaut\AiSeeder\Generator;

use Pbiaut\AiSeeder\OpenAI\Client;
use Pbiaut\AiSeeder\OpenAI\OpenAiException;
use Pbiaut\AiSeeder\Planner\ReplyLength;

/**
 * Generates every reply of a thread in a single call.
 *
 * This is both the cheap option (one call instead of one per reply) and the
 * good one: the model sees the whole conversation it is writing, so people
 * actually answer each other, correct themselves and close the loop, instead of
 * producing N independent comments on the same opening post.
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
     * @param  array<int, int>  $lengths  target word count per reply, same order
     * @param  array<int, array{author: string, content: string}>  $alreadyWritten
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
        array $lengths = [],
    ): array {
        $count = count($personas);

        if ($count === 0) {
            return [];
        }

        $system = $this->prompts->system(
            $context,
            'You write the replies a forum thread actually gets: people who read the opening post and help.',
            true
        );

        $participants = [];

        foreach (array_values($personas) as $index => $persona) {
            $words = $lengths[$index] ?? 90;

            $participants[] = 'Reply '.($index + 1).' - by '
                .($persona['display_name'] ?? $persona['username'] ?? 'a member')
                .', length: '.ReplyLength::instruction((int) $words)."\n"
                .$this->prompts->describePersona($persona, 'Member');
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
            "Write the next $count replies, in order, one per member listed below.",
            '',
            // The point of a reply is to be useful. Everything else is texture.
            'What a reply is for:',
            '- Answer the actual question asked in the opening post. If it asks how to do something,',
            '  say how. If it asks which to choose, choose one and say why. Be concrete and specific.',
            '- Give real substance: the step, the setting, the number, the caveat, the thing that bit you.',
            '- No filler. No "great question", no "hope this helps", no restating the problem back,',
            '  no summarising what someone else just said before adding your own point.',
            '- A reply that has nothing to add should be short, not padded to look substantial.',
            '',
            'What keeps it a conversation rather than N separate answers:',
            '- Later replies build on earlier ones: agree and add a detail, disagree and say why,',
            '  quote a line with "> " when answering it directly.',
            '- One or two replies can be a follow-up question or a report back ("tried it, still failing on X").',
            '- Never mention dates, delays, or how much time has passed.',
            '',
            'Respect each reply\'s target length. Short means short: one or two sentences, no preamble.',
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
